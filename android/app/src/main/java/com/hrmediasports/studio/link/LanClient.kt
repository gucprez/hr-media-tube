package com.hrmediasports.studio.link

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.DataInputStream
import java.io.DataOutputStream
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.LinkedBlockingDeque
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

sealed interface ClientStatus {
    data object Idle : ClientStatus
    data class Connecting(val attempt: Int) : ClientStatus
    data class Connected(val assignedName: String) : ClientStatus
    data class Rejected(val reason: String) : ClientStatus
}

/**
 * Cliente de la Pizarra y de la Cámara: se conecta al Estudio, se autentica con el código del QR,
 * envía tramas desde una cola y reconecta solo mientras no se detenga.
 * Para el video la cola tiene tope: si la red no da abasto se descartan cuadros hasta el siguiente
 * cuadro clave, en vez de acumular retraso.
 */
class LanClient(
    private val pairing: PairingCode,
    private val role: LanRole,
    private val deviceId: String,
    private val deviceName: String,
    private val retryDelayMs: Long = 2_000,
    private val pingEveryMs: Long = 1_000,
    private val maxQueuedVideo: Int = 45,
) {
    private val _status = MutableStateFlow<ClientStatus>(ClientStatus.Idle)
    val status: StateFlow<ClientStatus> = _status.asStateFlow()

    private val running = AtomicBoolean(false)
    private val queue = LinkedBlockingDeque<Frame>()
    @Volatile private var socket: Socket? = null
    @Volatile private var waitingKeyframe = false
    /** Se reenvía al reconectar (p. ej. SPS/PPS o el último marcador). */
    @Volatile var sticky: Frame? = null
    @Volatile var dropped = 0L; private set
    /** Se llama al conectar (o reconectar): la cámara pide un cuadro clave. */
    var onConnected: (() -> Unit)? = null

    fun start() {
        if (!running.compareAndSet(false, true)) return
        Thread({ loop() }, "lan-client").apply { isDaemon = true }.start()
    }

    fun stop() {
        running.set(false)
        runCatching { socket?.close() }
        queue.clear()
        _status.value = ClientStatus.Idle
    }

    val connected: Boolean get() = _status.value is ClientStatus.Connected

    fun send(type: Int, payload: ByteArray) {
        if (!running.get()) return
        // De marcador, miniatura y estado solo importa el último.
        if (type == FrameType.SCORE || type == FrameType.THUMB || type == FrameType.STATS) queue.removeIf { it.type == type }
        queue.offer(Frame(type, payload))
    }

    fun sendVideo(frame: VideoFrame) {
        if (!connected) return
        if (waitingKeyframe && !frame.keyFrame) { dropped++; return }
        waitingKeyframe = false
        val queuedVideo = queue.count { it.type == FrameType.VIDEO_FRAME }
        if (queuedVideo >= maxQueuedVideo) {
            // Red saturada: se vacía el video pendiente y se espera un cuadro clave.
            queue.removeIf { it.type == FrameType.VIDEO_FRAME }
            dropped += queuedVideo
            if (!frame.keyFrame) { waitingKeyframe = true; dropped++; return }
        }
        queue.offer(Frame(FrameType.VIDEO_FRAME, frame.encode()))
    }

    private fun loop() {
        var attempt = 0
        while (running.get()) {
            attempt++
            _status.value = ClientStatus.Connecting(attempt)
            val rejected = runCatching { session() }.getOrElse { null }
            if (rejected != null) { _status.value = ClientStatus.Rejected(rejected); running.set(false); break }
            if (_status.value is ClientStatus.Connected) attempt = 0
            if (!running.get()) break
            sleep(retryDelayMs)
        }
    }

    /** Una conexión completa. Devuelve el motivo si el Estudio la rechazó; null si se cortó. */
    private fun session(): String? {
        val s = Socket()
        socket = s
        try {
            s.connect(InetSocketAddress(pairing.host, pairing.port), 3_000)
            s.tcpNoDelay = true
            s.soTimeout = 6_000
            val input = DataInputStream(BufferedInputStream(s.getInputStream()))
            val out = DataOutputStream(BufferedOutputStream(s.getOutputStream(), 64 * 1024))
            val ch = FrameIO.read(input)
            if (ch.type != FrameType.CHALLENGE) return null
            val mac = LanCrypto.helloMac(pairing.secret, ch.payload, role, deviceId)
            FrameIO.write(out, FrameType.HELLO, Hello(role, deviceId, deviceName, mac).encode())
            val answer = FrameIO.read(input)
            if (answer.type == FrameType.REJECT) return String(answer.payload)
            if (answer.type != FrameType.WELCOME) return null
            s.soTimeout = 0
            queue.removeIf { it.type == FrameType.VIDEO_FRAME } // video viejo: no sirve
            waitingKeyframe = role == LanRole.Camera
            sticky?.let { FrameIO.write(out, it.type, it.payload) }
            _status.value = ClientStatus.Connected(String(answer.payload))
            onConnected?.invoke()
            // Detecta si el Estudio cierra (lectura en otro hilo).
            val alive = AtomicBoolean(true)
            var kicked: String? = null
            Thread({
                runCatching {
                    while (alive.get()) {
                        val f = FrameIO.read(input)
                        if (f.type == FrameType.REJECT) { kicked = String(f.payload); break }
                    }
                }
                alive.set(false)
            }, "lan-client-rx").apply { isDaemon = true }.start()
            var lastPing = 0L
            while (running.get() && alive.get()) {
                val f = queue.poll(200, TimeUnit.MILLISECONDS)
                if (f != null) FrameIO.write(out, f.type, f.payload)
                val now = System.currentTimeMillis()
                if (now - lastPing >= pingEveryMs) { FrameIO.write(out, FrameType.PING, ByteArray(0)); lastPing = now }
            }
            alive.set(false)
            Thread.sleep(50) // deja terminar al hilo lector por si el Estudio mandó un motivo
            return kicked
        } finally {
            runCatching { s.close() }
            socket = null
            if (running.get() && _status.value is ClientStatus.Connected) _status.value = ClientStatus.Connecting(1)
        }
    }

    private fun sleep(ms: Long) { try { Thread.sleep(ms) } catch (_: InterruptedException) {} }
}
