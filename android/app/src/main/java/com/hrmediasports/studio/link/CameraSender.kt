package com.hrmediasports.studio.link

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Bitmap
import android.media.MediaCodec
import android.os.BatteryManager
import android.os.Build
import android.os.PowerManager
import android.view.SurfaceView
import com.pedro.common.AudioCodec
import com.pedro.common.VideoCodec
import com.pedro.encoder.input.sources.audio.NoAudioSource
import com.pedro.encoder.input.sources.video.Camera2Source
import com.pedro.encoder.utils.CodecUtil
import com.pedro.library.base.StreamBase
import com.pedro.library.util.streamclient.StreamBaseClient
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.io.ByteArrayOutputStream
import java.nio.ByteBuffer

/** Cámara del teléfono → H.264 por hardware → cliente LAN. Sin audio (el sonido lo toma el Estudio). */
class LanVideoStream(context: Context, val camera: Camera2Source, private val onConfig: (VideoConfig) -> Unit, private val onFrame: (VideoFrame) -> Unit) :
    StreamBase(context, camera, NoAudioSource()) {
    private var width = 1280; private var height = 720

    fun setSize(w: Int, h: Int) { width = w; height = h }

    override fun getStreamClient(): StreamBaseClient = throw UnsupportedOperationException("sin cliente RTMP")
    override fun setVideoCodecImp(codec: VideoCodec) {}
    override fun setAudioCodecImp(codec: AudioCodec) {}
    override fun onAudioInfoImp(sampleRate: Int, isStereo: Boolean) {}
    override fun startStreamImp(endPoint: String) {}
    override fun stopStreamImp() {}
    override fun getAudioDataImp(audioBuffer: ByteBuffer, info: MediaCodec.BufferInfo) {}

    override fun onVideoInfoImp(sps: ByteBuffer, pps: ByteBuffer?, vps: ByteBuffer?) {
        if (pps == null) return
        onConfig(VideoConfig(width, height, bytes(sps), bytes(pps)))
    }

    override fun getVideoDataImp(videoBuffer: ByteBuffer, info: MediaCodec.BufferInfo) {
        if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) return
        val d = videoBuffer.duplicate()
        d.position(info.offset); d.limit(info.offset + info.size)
        val data = ByteArray(info.size); d.get(data)
        onFrame(VideoFrame(info.presentationTimeUs, info.flags and MediaCodec.BUFFER_FLAG_KEY_FRAME != 0, data))
    }

    private fun bytes(b: ByteBuffer): ByteArray { val d = b.duplicate(); d.rewind(); return ByteArray(d.remaining()).also { d.get(it) } }
}

data class SenderStatus(
    val prepared: Boolean = false,
    val sending: Boolean = false,
    val fps: Int = 0,
    val thermal: Int = -1,
    val error: String? = null,
    val generation: Int = 0,
)

/**
 * Modo CÁMARA: este teléfono solo filma y manda el video al Estudio por wifi.
 * Envía también una miniatura por segundo (para la Vista previa del Estudio) y su temperatura.
 */
@SuppressLint("StaticFieldLeak") // solo applicationContext
object CameraSender {
    private lateinit var app: Context
    private var stream: LanVideoStream? = null
    private var client: LanClient? = null
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private var aux: Job? = null
    private val _status = MutableStateFlow(SenderStatus())
    val status: StateFlow<SenderStatus> = _status.asStateFlow()
    val linkStatus: StateFlow<ClientStatus>? get() = client?.status

    const val BITRATE = 4_000_000 // red local: más calidad que hacia internet sin costo de datos

    fun init(context: Context) { if (!::app.isInitialized) app = context.applicationContext }

    fun prepare(): Boolean {
        val s = stream ?: LanVideoStream(app, Camera2Source(app), { cfg ->
            val c = client ?: return@LanVideoStream
            val f = Frame(FrameType.VIDEO_CONFIG, cfg.encode())
            c.sticky = f; c.send(f.type, f.payload)
        }, { frame -> client?.sendVideo(frame) }).also { stream = it; it.setFpsListener { fps -> _status.update { st -> st.copy(fps = fps) } } }
        if (s.isStreaming || s.isOnPreview) return _status.value.prepared
        s.forceCodecType(CodecUtil.CodecType.HARDWARE, CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND)
        var ok = runCatching { s.prepareVideo(1280, 720, BITRATE, 30, 1, 0) }.getOrDefault(false)
        if (!ok) {
            s.forceCodecType(CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND, CodecUtil.CodecType.FIRST_COMPATIBLE_FOUND)
            ok = runCatching { s.prepareVideo(1280, 720, BITRATE, 30, 1, 0) }.getOrDefault(false)
        }
        val audio = ok && runCatching { s.prepareAudio(44_100, false, 64_000) }.getOrDefault(false)
        if (ok) runCatching { s.camera.enableVideoStabilization() }
        _status.update { it.copy(prepared = ok && audio, generation = it.generation + 1, error = if (ok && audio) null else "La cámara no acepta 720p.") }
        return ok && audio
    }

    fun startPreview(view: SurfaceView) {
        val s = stream ?: return
        if (_status.value.prepared && !s.isOnPreview) runCatching { s.startPreview(view) }
    }

    fun stopPreview() { stream?.let { if (it.isOnPreview) runCatching { it.stopPreview() } } }

    /** Se conecta al Estudio y empieza a mandar video. */
    fun start(pairing: PairingCode, deviceId: String, name: String) {
        val s = stream ?: return
        stop()
        val c = LanClient(pairing, LanRole.Camera, deviceId, name)
        c.onConnected = { s.requestKeyframe() }
        client = c
        c.start()
        if (!s.isStreaming) runCatching { s.startStream("") }
        s.requestKeyframe()
        com.hrmediasports.studio.live.LiveService.start(app, cameraOnly = true)
        _status.update { it.copy(sending = true) }
        aux = scope.launch {
            var tick = 0
            while (isActive) {
                delay(1_000); tick++
                if (c.connected) {
                    s.getGlInterface().takePhoto { bmp -> thumb(bmp)?.let { c.send(FrameType.THUMB, it) } }
                    if (tick % 2 == 0) c.send(FrameType.STATS, DeviceStats(thermal(), battery(), _status.value.fps).encode())
                }
                _status.update { it.copy(thermal = thermal()) }
            }
        }
    }

    fun stop() {
        aux?.cancel(); aux = null
        client?.stop(); client = null
        stream?.let { if (it.isStreaming) runCatching { it.stopStream() } }
        com.hrmediasports.studio.live.LiveService.stop(app)
        _status.update { it.copy(sending = false) }
    }

    fun switchCamera() { runCatching { stream?.camera?.switchCamera() } }

    fun releaseIfIdle() {
        val s = stream ?: return
        if (s.isStreaming) return
        stopPreview(); runCatching { s.release() }; stream = null
        _status.value = SenderStatus()
    }

    private fun thumb(b: Bitmap): ByteArray? = runCatching {
        val small = Bitmap.createScaledBitmap(b, 320, 180, true)
        ByteArrayOutputStream().also { small.compress(Bitmap.CompressFormat.JPEG, 60, it) }.toByteArray()
    }.getOrNull()

    private fun thermal(): Int = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q)
        runCatching { app.getSystemService(PowerManager::class.java).currentThermalStatus }.getOrDefault(-1) else -1

    private fun battery(): Int = runCatching { app.getSystemService(BatteryManager::class.java).getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY) }.getOrDefault(-1)
}
