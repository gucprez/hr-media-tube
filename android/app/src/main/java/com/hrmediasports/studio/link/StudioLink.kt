package com.hrmediasports.studio.link

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.SystemClock
import com.hrmediasports.studio.engine.ScoreboardSnapshot
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.live.LiveEngine
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.net.Inet4Address
import java.net.NetworkInterface

/** Estado de una cámara remota visto desde el Estudio. */
data class RemoteCamera(
    val slot: Int,
    val connected: Boolean = false,
    val name: String = "",
    val thumb: Bitmap? = null,
    val thermal: Int = -1,
    val battery: Int = -1,
    val fps: Int = 0,
    val hasVideo: Boolean = false,
)

/** Direcciones IPv4 locales (wifi o punto de acceso) donde los teléfonos pueden encontrar al Estudio. */
fun localAddresses(): List<String> = runCatching {
    NetworkInterface.getNetworkInterfaces().toList()
        .filter { it.isUp && !it.isLoopback }
        .sortedBy { n -> listOf("wlan", "ap", "swlan", "eth", "rndis").indexOfFirst { n.name.startsWith(it) }.let { if (it < 0) 99 else it } }
        .flatMap { n -> n.inetAddresses.toList().filterIsInstance<Inet4Address>().filter { it.isSiteLocalAddress }.map { it.hostAddress!! } }
        .distinct()
}.getOrDefault(emptyList())

/**
 * Lado del Estudio del enlace local: abre el puerto, genera el QR y reparte lo que llega:
 * el marcador a la interfaz (como [ScoreboardSource]) y el video de las cámaras al motor.
 */
@SuppressLint("StaticFieldLeak")
object StudioLink : ScoreboardSource, LanServerListener {
    private var server: LanServer? = null
    private val _pairing = MutableStateFlow<PairingCode?>(null)
    val pairing: StateFlow<PairingCode?> = _pairing.asStateFlow()

    private val _snapshot = MutableStateFlow(ScoreboardSnapshot(null, ReadingOrigin.Remote, LinkState.NotConnected, null, null))
    override val snapshot: StateFlow<ScoreboardSnapshot> = _snapshot.asStateFlow()

    private val _cameras = MutableStateFlow(List(2) { RemoteCamera(it) })
    val cameras: StateFlow<List<RemoteCamera>> = _cameras.asStateFlow()

    private val _clockRunning = MutableStateFlow(false)
    val clockRunning: StateFlow<Boolean> = _clockRunning.asStateFlow()

    /** Último mensaje con su instante de llegada (para reloj que sigue corriendo entre mensajes). */
    @Volatile var lastAuto: Boolean = false; private set

    private val secretPrefs = "estudio-enlace"

    fun start(context: Context) {
        if (server != null) { refreshAddress(); return }
        // El secreto se conserva: los teléfonos ya emparejados se reconectan solos tras reiniciar la app.
        val prefs = context.applicationContext.getSharedPreferences(secretPrefs, Context.MODE_PRIVATE)
        val saved = prefs.getString("k", null)?.let { Base32.decode(it) }?.takeIf { it.size == PairingCode.SECRET_BYTES }
        val secret = saved ?: PairingCode.random("x", 1).secret.also { prefs.edit().putString("k", Base32.encode(it)).apply() }
        val s = LanServer(secret, this)
        val port = runCatching { s.start(LAN_PORT) }.getOrNull() ?: return
        server = s
        _pairing.value = PairingCode(localAddresses().firstOrNull() ?: "0.0.0.0", port, secret)
    }

    /** La IP cambia si se cambia de wifi: se recalcula al mostrar el QR. */
    fun refreshAddress() {
        val p = _pairing.value ?: return
        val ip = localAddresses().firstOrNull() ?: return
        if (ip != p.host) _pairing.value = PairingCode(ip, p.port, p.secret)
    }

    /** Nuevo código: los teléfonos emparejados antes tendrán que volver a escanear. */
    fun resetCode(context: Context) {
        server?.stop(); server = null
        context.applicationContext.getSharedPreferences(secretPrefs, Context.MODE_PRIVATE).edit().remove("k").apply()
        _cameras.value = List(2) { RemoteCamera(it) }
        _snapshot.update { it.copy(link = LinkState.NotConnected) }
        start(context)
    }

    // ---------------- Eventos del servidor (hilos de red) ----------------

    override fun onScoreboardConnected(id: String, name: String) = _snapshot.update { it.copy(link = LinkState.Connected) }
    override fun onScoreboardDisconnected(id: String) = _snapshot.update { it.copy(link = LinkState.NotConnected) }

    override fun onScore(msg: ScoreMessage) {
        lastAuto = msg.auto
        _clockRunning.value = msg.clockRunning
        _snapshot.value = ScoreboardSnapshot(msg.match, ReadingOrigin.Remote, LinkState.Connected, System.currentTimeMillis(), msg.confidence)
    }

    override fun onCameraConnected(id: String, name: String, slot: Int) = updateCam(slot) { it.copy(connected = true, name = name) }
    override fun onCameraDisconnected(id: String, slot: Int) = updateCam(slot) { it.copy(connected = false, hasVideo = false) }

    override fun onVideoConfig(slot: Int, config: VideoConfig) {
        LiveEngine.remotes.getOrNull(slot)?.setConfig(config)
        updateCam(slot) { it.copy(hasVideo = true) }
    }

    override fun onVideoFrame(slot: Int, frame: VideoFrame) { LiveEngine.remotes.getOrNull(slot)?.push(frame) }

    override fun onThumb(slot: Int, jpeg: ByteArray) {
        val bmp = runCatching { BitmapFactory.decodeByteArray(jpeg, 0, jpeg.size) }.getOrNull() ?: return
        updateCam(slot) { it.copy(thumb = bmp) }
    }

    override fun onStats(slot: Int, stats: DeviceStats) = updateCam(slot) { it.copy(thermal = stats.thermal, battery = stats.battery, fps = stats.fps) }

    private fun updateCam(slot: Int, f: (RemoteCamera) -> RemoteCamera) {
        if (slot !in 0..1) return
        _cameras.update { list -> list.mapIndexed { i, c -> if (i == slot) f(c) else c } }
    }

    fun nowMs() = SystemClock.elapsedRealtime()
}
