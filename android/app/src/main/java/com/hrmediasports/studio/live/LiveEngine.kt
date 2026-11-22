package com.hrmediasports.studio.live

import android.annotation.SuppressLint
import android.content.ContentValues
import android.content.Context
import android.graphics.Bitmap
import android.os.Build
import android.os.Environment
import android.os.PowerManager
import android.os.SystemClock
import android.provider.MediaStore
import android.util.Log
import android.view.SurfaceView
import com.hrmediasports.studio.link.RemoteVideoSource
import com.pedro.common.ConnectChecker
import com.pedro.encoder.input.gl.render.filters.`object`.ImageObjectFilterRender
import com.pedro.encoder.utils.CodecUtil
import com.pedro.library.base.recording.RecordController
import com.pedro.library.util.BitrateAdapter
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Lo que el motor reporta a la pantalla. */
data class EngineStatus(
    val prepared: Boolean = false,
    val previewing: Boolean = false,
    val links: Map<Platform, LinkStatus> = Platform.entries.associateWith { LinkStatus.Off },
    val videoBitrateKbps: Int = 0,
    val bitrateCapKbps: Int = 0,
    val fps: Int = 0,
    val thermal: Thermal = Thermal.Unknown,
    val recording: Boolean = false,
    val recordingStartedAt: Long? = null,
    val lastRecording: String? = null,
    val streamingSince: Long? = null,
    val error: String? = null,
    val hardwareEncoder: Boolean = true,
    /** Cambia cada vez que se prepara la cámara: la vista previa se vuelve a crear. */
    val generation: Int = 0,
    /** 0 = cámara de este teléfono; 1.. = cámara remota (lugar + 1). */
    val videoSource: Int = 0,
    val slate: Slate = Slate.None,
) {
    val anyOn: Boolean get() = links.values.any { it != LinkStatus.Off && it !is LinkStatus.Failed }
    val anyLive: Boolean get() = links.values.any { it == LinkStatus.Live }
}

/**
 * Motor de transmisión real (capa 5): cámara → OpenGL (marcador y logo) → codificador por hardware
 * → RTMP/RTMPS a varios destinos + grabación de respaldo en el teléfono.
 *
 * Vive a nivel de proceso para que la transmisión no dependa de la pantalla: si el operador sale de
 * la app, el servicio en primer plano mantiene el proceso y la cámara.
 */
@SuppressLint("StaticFieldLeak") // Solo guarda el applicationContext.
object LiveEngine {
    private const val TAG = "LiveEngine"
    private lateinit var app: Context
    private var stream: MultiRtmpStream? = null
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)

    private val _status = MutableStateFlow(EngineStatus())
    val status: StateFlow<EngineStatus> = _status.asStateFlow()

    private var quality = Quality.Hd720
    private var thermal = Thermal.Unknown
    private val renderer by lazy { ScoreboardRenderer() }
    private var scoreFilter: ImageObjectFilterRender? = null
    private var slateFilter: ImageObjectFilterRender? = null
    @Volatile private var slate: Slate = Slate.None
    @Volatile private var slateTitle: String? = null
    /** Cámaras remotas por lugar (Cámara 2 = lugar 0). */
    val remotes: List<RemoteVideoSource> = List(2) { RemoteVideoSource() }
    private var logoFilter: ImageObjectFilterRender? = null
    @Volatile private var score: LiveScore = LiveScore()
    @Volatile private var logo: Bitmap? = null
    private var recordFile: File? = null
    private val attempts = IntArray(Platform.entries.size)
    private val lastBitrate = LongArray(Platform.entries.size)
    private var currentBitrate = 0

    private val adapter = BitrateAdapter { bitrate ->
        currentBitrate = bitrate.coerceAtMost(bitrateCap(quality, thermal))
        stream?.setVideoBitrateOnFly(currentBitrate)
        _status.update { it.copy(videoBitrateKbps = currentBitrate / 1000) }
    }

    fun init(context: Context) {
        if (::app.isInitialized) return
        app = context.applicationContext
        listenThermal()
        startOverlayLoop()
    }

    private fun checker(p: Platform) = object : ConnectChecker {
        override fun onConnectionStarted(url: String) = setLink(p, LinkStatus.Connecting)
        override fun onConnectionSuccess() {
            attempts[p.ordinal] = 0
            setLink(p, LinkStatus.Live)
        }
        override fun onConnectionFailed(reason: String) {
            val s = stream ?: return
            if (!s.isActive(p.ordinal)) return
            val nice = friendlyReason(reason)
            val client = s.client(p.ordinal)
            // La clave rechazada no se arregla reintentando.
            val authProblem = reason.contains("auth", ignoreCase = true)
            if (!authProblem && client.reTry(RECONNECT_DELAY_MS, reason)) {
                attempts[p.ordinal]++
                setLink(p, LinkStatus.Reconnecting(attempts[p.ordinal], nice))
            } else {
                setLink(p, LinkStatus.Failed(nice))
                scope.launch { stopDestination(p) ; setLink(p, LinkStatus.Failed(nice)) }
            }
        }
        override fun onNewBitrate(bitrate: Long) {
            val s = stream ?: return
            lastBitrate[p.ordinal] = bitrate
            // Se adapta al destino más lento: si uno se congestiona, se baja para todos.
            val congested = Platform.entries.any { s.isActive(it.ordinal) && s.client(it.ordinal).hasCongestion() }
            adapter.adaptBitrate(bitrate, congested)
        }
        override fun onDisconnect() {
            val s = stream
            if (s == null || !s.isActive(p.ordinal)) setLink(p, LinkStatus.Off)
        }
        override fun onAuthError() {
            setLink(p, LinkStatus.Failed("la plataforma rechazó la clave"))
            scope.launch { stopDestination(p); setLink(p, LinkStatus.Failed("la plataforma rechazó la clave")) }
        }
        override fun onAuthSuccess() {}
    }

    private fun setLink(p: Platform, s: LinkStatus) = _status.update { it.copy(links = it.links + (p to s)) }

    private fun ensureStream(): MultiRtmpStream =
        stream ?: MultiRtmpStream(app, Platform.entries.map { checker(it) }).also { s ->
            stream = s
            s.setFpsListener { fps -> _status.update { it.copy(fps = fps) } }
        }

    /** Prepara cámara, micrófono y codificadores. Solo cuando no se transmite ni se ve la cámara. */
    fun prepare(q: Quality): Boolean {
        val s = ensureStream()
        if (s.isStreaming || s.isRecording || s.isOnPreview) return _status.value.prepared
        if (_status.value.videoSource != 0) { runCatching { s.changeVideoSource(s.camera) }; _status.update { it.copy(videoSource = 0) } }
        quality = q
        val cap = bitrateCap(q, thermal)
        // Codificador por hardware: el de software calienta mucho más el teléfono.
        var hardware = true
        s.forceCodecType(CodecUtil.CodecType.HARDWARE, CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND)
        var ok = runCatching { s.prepareVideo(q.width, q.height, cap, q.fps, 2, 0) }.getOrDefault(false)
        if (!ok) {
            hardware = false
            s.forceCodecType(CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND, CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND)
            ok = runCatching { s.prepareVideo(q.width, q.height, cap, q.fps, 2, 0) }.getOrDefault(false)
        }
        // Estéreo si el micrófono lo permite; si no, mono (algunos teléfonos no aceptan estéreo).
        val audio = ok && (runCatching { s.prepareAudio(44_100, true, Quality.AUDIO_BITRATE) }.getOrDefault(false)
            || runCatching { s.prepareAudio(44_100, false, 96_000) }.getOrDefault(false))
        if (ok) runCatching { s.camera.enableVideoStabilization() }
        currentBitrate = cap
        adapter.setMaxBitrate(cap)
        _status.update {
            it.copy(generation = it.generation + 1, prepared = ok && audio, hardwareEncoder = hardware, bitrateCapKbps = cap / 1000, videoBitrateKbps = cap / 1000,
                error = when { !ok -> "La cámara o el codificador no aceptan ${q.label}. Prueba otra calidad."
                    !audio -> "No se pudo abrir el micrófono."
                    else -> null })
        }
        return ok && audio
    }

    fun currentQuality(): Quality = quality

    fun startPreview(view: SurfaceView) {
        val s = stream ?: return
        if (!_status.value.prepared || s.isOnPreview) return
        runCatching { s.startPreview(view) }.onFailure { Log.w(TAG, "preview", it); return }
        installOverlays(s)
        _status.update { it.copy(previewing = true) }
    }

    fun stopPreview() {
        val s = stream ?: return
        if (s.isOnPreview) runCatching { s.stopPreview() }
        _status.update { it.copy(previewing = false) }
    }

    /** Marcador y logo dentro del video. Se vuelven a instalar cada vez que arranca OpenGL. */
    private fun installOverlays(s: MultiRtmpStream) {
        val gl = s.getGlInterface()
        gl.clearFilters()
        val pf = ImageObjectFilterRender()
        val sf = ImageObjectFilterRender()
        val lf = ImageObjectFilterRender()
        gl.addFilter(pf); gl.addFilter(sf); gl.addFilter(lf)
        slateFilter = pf; scoreFilter = sf; logoFilter = lf
        pf.setScale(100f, 100f); pf.setPosition(0f, 0f)
        applySlate()
        renderer.invalidate()
        renderer.render(score, SystemClock.elapsedRealtime())?.let { sf.setImage(it) }
        sf.setScale(ScoreboardRenderer.WIDTH / ScoreboardRenderer.REFERENCE_WIDTH * 100f, ScoreboardRenderer.HEIGHT / ScoreboardRenderer.REFERENCE_HEIGHT * 100f)
        sf.setPosition(1.8f, 3f)
        sf.setAlpha(if (score.visible && slate == Slate.None) 1f else 0f)
        applyLogo()
    }

    private fun applyLogo() {
        val lf = logoFilter ?: return
        val b = logo
        if (b == null) { lf.setAlpha(0f); return }
        lf.setImage(b)
        val sx = b.width / ScoreboardRenderer.REFERENCE_WIDTH * 100f
        val sy = b.height / ScoreboardRenderer.REFERENCE_HEIGHT * 100f
        lf.setScale(sx, sy)
        lf.setPosition(100f - sx - 1.8f, 3f)
        lf.setAlpha(if (slate == Slate.None) 1f else 0f)
    }

    fun setLogo(bitmap: Bitmap?) { logo = bitmap?.let { scaleLogo(it) }; applyLogo(); if (slate != Slate.None) applySlate() }

    @Volatile private var scoreSet = false
    fun currentScore(): LiveScore? = if (scoreSet) score else null

    fun setScore(s: LiveScore) {
        scoreSet = true
        val changed = s.home != score.home || s.away != score.away || s.period != score.period
        score = s
        scoreFilter?.setAlpha(if (s.visible && slate == Slate.None) 1f else 0f)
        if (changed && slate != Slate.None) applySlate() // el gráfico de descanso/resultado muestra el marcador actual
    }

    /** Pone (o quita) un gráfico de pantalla completa encima de la cámara. */
    fun setSlate(kind: Slate, title: String? = null) {
        slate = kind; slateTitle = title
        applySlate()
        scoreFilter?.setAlpha(if (score.visible && kind == Slate.None) 1f else 0f)
        logoFilter?.setAlpha(if (logo != null && kind == Slate.None) 1f else 0f)
        _status.update { it.copy(slate = kind) }
    }

    private fun applySlate() {
        val pf = slateFilter ?: return
        val bmp = SlateRenderer.render(slate, score, logo, slateTitle)
        if (bmp == null) { pf.setAlpha(0f); return }
        pf.setImage(bmp); pf.setScale(100f, 100f); pf.setPosition(0f, 0f); pf.setAlpha(1f)
    }

    /** Cambia la cámara que sale al aire: 0 = este teléfono, 1.. = cámara remota. */
    fun selectVideo(index: Int): Boolean {
        val s = stream ?: return false
        if (index == _status.value.videoSource) return true
        val src = if (index == 0) s.camera else remotes.getOrNull(index - 1) ?: return false
        return runCatching { s.changeVideoSource(src) }
            .onSuccess { _status.update { it.copy(videoSource = index) } }
            .onFailure { Log.w(TAG, "cambio de cámara", it) }.isSuccess
    }

    /** Milisegundos desde el último cuadro de la cámara remota (null si nunca llegó). */
    fun remoteAgeMs(slot: Int): Long? = remotes.getOrNull(slot)?.lastFrameAt?.takeIf { it > 0 }?.let { SystemClock.elapsedRealtime() - it }

    private fun startOverlayLoop() = scope.launch {
        while (isActive) {
            val f = scoreFilter
            if (f != null) renderer.render(score, SystemClock.elapsedRealtime())?.let { f.setImage(it) }
            delay(200)
        }
    }

    /** Inicia la transmisión a los destinos listos. Devuelve un problema o null. */
    fun goLive(destinations: List<LiveDestination>, record: Boolean): String? {
        val s = stream ?: return "La cámara no está lista."
        if (!_status.value.prepared) return "La cámara no está lista."
        val ready = destinations.filter { it.ready }
        if (ready.isEmpty()) return "Activa al menos un destino con su clave."
        LiveService.start(app)
        autoRecord = record
        if (record && !s.isRecording) startRecording(s)
        for (d in ready) {
            val endpoint = d.endpoint() ?: continue
            attempts[d.platform.ordinal] = 0
            setLink(d.platform, LinkStatus.Connecting)
            runCatching { s.start(d.platform.ordinal, endpoint) }
                .onFailure { setLink(d.platform, LinkStatus.Failed(friendlyReason(it.message ?: "error"))) }
        }
        _status.update { it.copy(streamingSince = it.streamingSince ?: SystemClock.elapsedRealtime(), error = null) }
        return null
    }

    /** Conecta o reconecta un solo destino mientras los demás siguen al aire. */
    fun startDestination(d: LiveDestination): String? {
        val s = stream ?: return "La cámara no está lista."
        val endpoint = d.endpoint() ?: return d.problem() ?: "Destino incompleto."
        LiveService.start(app)
        attempts[d.platform.ordinal] = 0
        setLink(d.platform, LinkStatus.Connecting)
        runCatching { s.start(d.platform.ordinal, endpoint) }
            .onFailure { setLink(d.platform, LinkStatus.Failed(friendlyReason(it.message ?: "error"))) }
        _status.update { it.copy(streamingSince = it.streamingSince ?: SystemClock.elapsedRealtime()) }
        return null
    }

    fun stopDestination(p: Platform) {
        val s = stream ?: return
        s.stop(p.ordinal)
        setLink(p, LinkStatus.Off)
        if (!s.anyActive()) finishSession(s)
    }

    fun stopAll() {
        val s = stream ?: return
        s.stopAll()
        Platform.entries.forEach { setLink(it, LinkStatus.Off) }
        finishSession(s)
    }

    private fun finishSession(s: MultiRtmpStream) {
        if (s.isRecording && !manualRecording) stopRecording(s)
        autoRecord = false
        _status.update { it.copy(streamingSince = null) }
        if (!s.isRecording) LiveService.stop(app)
        // Los codificadores quedan listos de nuevo para otra transmisión.
        if (!s.isStreaming && !s.isRecording) _status.update { it.copy(prepared = true) }
    }

    // ---------- Grabación de respaldo ----------

    @Volatile private var manualRecording = false

    /** GRABAR del Estudio: graba aunque no se transmita; sigue grabando aunque termine la transmisión. */
    fun startManualRecording(): String? {
        val s = stream ?: return "La cámara no está lista."
        if (!_status.value.prepared) return "La cámara no está lista."
        manualRecording = true
        if (!s.isRecording) { LiveService.start(app); startRecording(s) }
        return null
    }

    fun stopManualRecording() {
        val s = stream ?: return
        manualRecording = false
        if (s.isRecording && !s.anyActive()) { stopRecording(s); LiveService.stop(app) }
        else if (s.isRecording && !autoRecord) stopRecording(s)
    }

    @Volatile private var autoRecord = false

    private fun startRecording(s: MultiRtmpStream) {
        val dir = app.getExternalFilesDir(Environment.DIRECTORY_MOVIES) ?: app.filesDir
        val name = "HRMS-" + SimpleDateFormat("yyyyMMdd-HHmmss", Locale.ROOT).format(Date()) + ".mp4"
        val f = File(dir, name)
        recordFile = f
        runCatching {
            s.startRecord(f.absolutePath, object : RecordController.Listener {
                override fun onStatusChange(status: RecordController.Status) {
                    if (status == RecordController.Status.RECORDING)
                        _status.update { it.copy(recording = true, recordingStartedAt = SystemClock.elapsedRealtime()) }
                }
                override fun onError(e: Exception) {
                    _status.update { it.copy(recording = false, error = "La grabación de respaldo falló: ${e.message ?: "error"}") }
                }
            })
        }.onFailure { _status.update { s2 -> s2.copy(error = "No se pudo iniciar la grabación de respaldo.") } }
    }

    private fun stopRecording(s: MultiRtmpStream) {
        runCatching { s.stopRecord() }
        _status.update { it.copy(recording = false, recordingStartedAt = null) }
        val f = recordFile ?: return
        recordFile = null
        scope.launch(Dispatchers.IO) {
            val where = publishToGallery(f)
            _status.update { it.copy(lastRecording = where) }
        }
    }

    /** Copia la grabación a Películas/HR Media Sports para verla en la galería. */
    private fun publishToGallery(f: File): String {
        if (!f.exists() || f.length() == 0L) return "La grabación quedó vacía."
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return f.absolutePath
        return runCatching {
            val values = ContentValues().apply {
                put(MediaStore.Video.Media.DISPLAY_NAME, f.name)
                put(MediaStore.Video.Media.MIME_TYPE, "video/mp4")
                put(MediaStore.Video.Media.RELATIVE_PATH, Environment.DIRECTORY_MOVIES + "/HR Media Sports")
                put(MediaStore.Video.Media.IS_PENDING, 1)
            }
            val resolver = app.contentResolver
            val uri = resolver.insert(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, values) ?: error("sin acceso a la galería")
            resolver.openOutputStream(uri)?.use { out -> f.inputStream().use { it.copyTo(out, 1 shl 20) } } ?: error("no se pudo escribir")
            resolver.update(uri, ContentValues().apply { put(MediaStore.Video.Media.IS_PENDING, 0) }, null, null)
            f.delete()
            "Galería › Películas › HR Media Sports › ${f.name}"
        }.getOrElse { "Guardada en ${f.absolutePath} (no se pudo copiar a la galería)" }
    }

    // ---------- Temperatura ----------

    private fun listenThermal() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return
        val pm = app.getSystemService(PowerManager::class.java) ?: return
        onThermal(Thermal.fromAndroid(pm.currentThermalStatus))
        pm.addThermalStatusListener(app.mainExecutor) { onThermal(Thermal.fromAndroid(it)) }
    }

    private fun onThermal(t: Thermal) {
        thermal = t
        val cap = bitrateCap(quality, t)
        adapter.setMaxBitrate(cap)
        if (stream?.isStreaming == true && currentBitrate > cap) {
            currentBitrate = cap
            stream?.setVideoBitrateOnFly(cap)
        }
        _status.update { it.copy(thermal = t, bitrateCapKbps = cap / 1000, videoBitrateKbps = minOf(it.videoBitrateKbps.takeIf { k -> k > 0 } ?: (cap / 1000), cap / 1000)) }
    }

    // ---------- Cámara ----------

    fun switchCamera() { runCatching { stream?.camera?.switchCamera() } }
    fun setZoom(level: Float) { runCatching { stream?.camera?.setZoom(level) } }
    fun zoomRange(): ClosedFloatingPointRange<Float> = runCatching { stream?.camera?.getZoomRange()?.let { it.lower..it.upper } }.getOrNull() ?: 1f..1f
    fun zoom(): Float = runCatching { stream?.camera?.getZoom() }.getOrNull() ?: 1f
    fun setMuted(muted: Boolean) { stream?.microphone?.let { if (muted) it.mute() else it.unMute() } }
    fun isMuted(): Boolean = stream?.microphone?.isMuted() ?: false

    fun clearError() = _status.update { it.copy(error = null) }

    /** Libera todo (cuando el operador sale del modo transmisión sin estar al aire). */
    fun releaseIfIdle() {
        val s = stream ?: return
        if (s.isStreaming || s.isRecording) return
        stopPreview()
        runCatching { s.release() }
        stream = null
        _status.update { EngineStatus(thermal = it.thermal, lastRecording = it.lastRecording) }
        slate = Slate.None
    }
}
