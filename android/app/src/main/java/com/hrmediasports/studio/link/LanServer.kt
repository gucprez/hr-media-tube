package com.hrmediasports.studio.link

import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.DataInputStream
import java.io.DataOutputStream
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import java.net.SocketTimeoutException
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicBoolean

/** Eventos que el servidor del Estudio entrega a quien lo usa. Se llaman desde hilos de red. */
interface LanServerListener {
    fun onScoreboardConnected(id: String, name: String) {}
    fun onScoreboardDisconnected(id: String) {}
    fun onScore(msg: ScoreMessage) {}
    fun onCameraConnected(id: String, name: String, slot: Int) {}
    fun onCameraDisconnected(id: String, slot: Int) {}
    fun onVideoConfig(slot: Int, config: VideoConfig) {}
    fun onVideoFrame(slot: Int, frame: VideoFrame) {}
    fun onThumb(slot: Int, jpeg: ByteArray) {}
    fun onStats(slot: Int, stats: DeviceStats) {}
    fun onRejected(reason: String) {}
}

/**
 * Servidor TCP del Estudio. Acepta una pizarra (la nueva reemplaza a la anterior) y hasta
 * [maxCameras] cámaras; si un teléfono se reconecta con el mismo id, recupera su lugar.
 * Una conexión sin tramas durante [readTimeoutMs] se da por perdida (los teléfonos envían PING cada segundo).
 */
class LanServer(
    private val secret: ByteArray,
    private val listener: LanServerListener,
    private val maxCameras: Int = 2,
    private val readTimeoutMs: Int = 5_000,
) {
    private var server: ServerSocket? = null
    private val running = AtomicBoolean(false)
    private val conns = ConcurrentHashMap<String, Conn>()      // clave: rol|id
    private val cameraSlots = arrayOfNulls<String>(maxCameras) // id por lugar
    private var scoreboardId: String? = null

    val port: Int get() = server?.localPort ?: -1

    /** Abre el puerto (prueba [preferredPort] y los 9 siguientes; 0 = cualquiera). Devuelve el puerto real. */
    fun start(preferredPort: Int = LAN_PORT): Int {
        if (running.get()) return port
        val candidates = if (preferredPort == 0) listOf(0) else (preferredPort until preferredPort + 10).toList() + 0
        var s: ServerSocket? = null
        for (p in candidates) {
            s = runCatching { ServerSocket().apply { reuseAddress = true; bind(InetSocketAddress(p)) } }.getOrNull()
            if (s != null) break
        }
        val bound = s ?: error("No se pudo abrir ningún puerto")
        server = bound
        running.set(true)
        Thread({ acceptLoop(bound) }, "lan-accept").apply { isDaemon = true }.start()
        return bound.localPort
    }

    fun stop() {
        running.set(false)
        runCatching { server?.close() }
        conns.values.forEach { it.close() }
        conns.clear()
    }

    fun cameraIdAt(slot: Int): String? = synchronized(cameraSlots) { cameraSlots.getOrNull(slot) }

    private fun acceptLoop(s: ServerSocket) {
        while (running.get()) {
            val socket = runCatching { s.accept() }.getOrNull() ?: break
            Thread({ handle(socket) }, "lan-conn").apply { isDaemon = true }.start()
        }
    }

    private inner class Conn(val socket: Socket, val out: DataOutputStream, val role: LanRole, val id: String) {
        @Volatile var closed = false
        fun close() { closed = true; runCatching { socket.close() } }
    }

    private fun handle(socket: Socket) {
        var conn: Conn? = null
        var slot = -1
        try {
            socket.tcpNoDelay = true
            socket.soTimeout = readTimeoutMs
            val input = DataInputStream(BufferedInputStream(socket.getInputStream(), 64 * 1024))
            val out = DataOutputStream(BufferedOutputStream(socket.getOutputStream()))
            val challenge = LanCrypto.challenge()
            FrameIO.write(out, FrameType.CHALLENGE, challenge)
            val first = FrameIO.read(input)
            val hello = if (first.type == FrameType.HELLO) Hello.decode(first.payload) else null
            if (hello == null || !LanCrypto.equal(hello.mac, LanCrypto.helloMac(secret, challenge, hello.role, hello.id))) {
                FrameIO.write(out, FrameType.REJECT, "Código del Estudio incorrecto: vuelve a escanear el QR.".toByteArray())
                listener.onRejected("conexión con código incorrecto")
                return
            }
            if (hello.role == LanRole.Camera) {
                slot = claimSlot(hello.id)
                if (slot < 0) {
                    FrameIO.write(out, FrameType.REJECT, "El Estudio ya tiene $maxCameras cámaras conectadas.".toByteArray())
                    return
                }
            }
            val c = Conn(socket, out, hello.role, hello.id)
            conns.put("${hello.role.wire}|${hello.id}", c)?.close() // reconexión: reemplaza la vieja
            conn = c
            val assigned = if (hello.role == LanRole.Camera) "Cámara ${slot + 2}" else "Pizarra"
            FrameIO.write(out, FrameType.WELCOME, assigned.toByteArray())
            when (hello.role) {
                LanRole.Scoreboard -> {
                    val replaced = synchronized(this) {
                        val old = scoreboardId
                        scoreboardId = hello.id
                        if (old != null && old != hello.id) conns.remove("${LanRole.Scoreboard.wire}|$old")?.let { old to it } else null
                    }
                    // Otra pizarra tomó el lugar: se le avisa para que deje de reintentar (si no, se turnan sin fin).
                    replaced?.let { (oldId, oldConn) ->
                        runCatching { FrameIO.write(oldConn.out, FrameType.REJECT, "Otra pizarra se conectó al Estudio y tomó este lugar.".toByteArray()) }
                        oldConn.close()
                        listener.onScoreboardDisconnected(oldId)
                    }
                    listener.onScoreboardConnected(hello.id, hello.name)
                }
                LanRole.Camera -> listener.onCameraConnected(hello.id, hello.name, slot)
            }
            while (running.get() && !c.closed) {
                val f = try { FrameIO.read(input) } catch (e: SocketTimeoutException) { break }
                when (f.type) {
                    FrameType.SCORE -> if (hello.role == LanRole.Scoreboard) ScoreMessage.decode(f.payload)?.let(listener::onScore)
                    FrameType.VIDEO_CONFIG -> if (slot >= 0) VideoConfig.decode(f.payload)?.let { listener.onVideoConfig(slot, it) }
                    FrameType.VIDEO_FRAME -> if (slot >= 0) VideoFrame.decode(f.payload)?.let { listener.onVideoFrame(slot, it) }
                    FrameType.THUMB -> if (slot >= 0) listener.onThumb(slot, f.payload)
                    FrameType.STATS -> if (slot >= 0) DeviceStats.decode(f.payload)?.let { listener.onStats(slot, it) }
                    FrameType.PING -> {}
                    else -> {}
                }
            }
        } catch (_: Exception) {
            // conexión cerrada o datos inválidos
        } finally {
            runCatching { socket.close() }
            val c = conn
            if (c != null) {
                // Solo se informa la desconexión si no la reemplazó otra conexión del mismo teléfono.
                val key = "${c.role.wire}|${c.id}"
                if (conns.remove(key, c)) {
                    when (c.role) {
                        LanRole.Scoreboard -> listener.onScoreboardDisconnected(c.id)
                        LanRole.Camera -> listener.onCameraDisconnected(c.id, slot)
                    }
                }
            }
        }
    }

    /** Mismo id → mismo lugar (reconexión). Si no, el primer lugar libre. */
    private fun claimSlot(id: String): Int = synchronized(cameraSlots) {
        val existing = cameraSlots.indexOf(id)
        if (existing >= 0) return existing
        for (i in cameraSlots.indices) {
            val occupant = cameraSlots[i]
            if (occupant == null || !conns.containsKey("${LanRole.Camera.wire}|$occupant")) {
                cameraSlots[i] = id
                return i
            }
        }
        -1
    }
}
