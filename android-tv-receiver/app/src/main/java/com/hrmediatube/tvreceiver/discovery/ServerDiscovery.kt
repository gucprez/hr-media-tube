package com.hrmediatube.tvreceiver.discovery

import android.os.Handler
import android.os.Looper
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.SocketTimeoutException

/**
 * Escucha mensajes de broadcast UDP que el script del PC envía en la red local,
 * para autocompletar la IP sin que el usuario tenga que escribirla con el control remoto.
 *
 * Formato esperado del mensaje: "HRMEDIATUBE|<ip>|<puertoRtsp>|<nombreStream>"
 */
class ServerDiscovery(private val onFound: (ip: String, port: Int, path: String) -> Unit) {

    data class Found(val ip: String, val port: Int, val path: String)

    @Volatile private var running = false
    private var thread: Thread? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    fun start() {
        if (running) return
        running = true
        thread = Thread {
            try {
                val socket = DatagramSocket(null)
                socket.reuseAddress = true
                socket.bind(java.net.InetSocketAddress(DISCOVERY_PORT))
                socket.broadcast = true
                socket.soTimeout = 1000
                val buffer = ByteArray(512)

                while (running) {
                    try {
                        val packet = DatagramPacket(buffer, buffer.size)
                        socket.receive(packet)
                        val message = String(packet.data, 0, packet.length, Charsets.UTF_8).trim()
                        parse(message)?.let { found ->
                            mainHandler.post { onFound(found.ip, found.port, found.path) }
                        }
                    } catch (_: SocketTimeoutException) {
                        // normal: solo revisamos si seguimos activos
                    }
                }
                socket.close()
            } catch (_: Exception) {
                // Si el socket no pudo abrirse (puerto ocupado, permisos, etc.) simplemente
                // no habrá autodescubrimiento; el usuario siempre puede escribir la IP a mano.
            }
        }
        thread?.isDaemon = true
        thread?.start()
    }

    fun stop() {
        running = false
        thread?.interrupt()
        thread = null
    }

    private fun parse(message: String): Found? {
        val parts = message.split("|")
        if (parts.size != 4 || parts[0] != PROTOCOL_TAG) return null
        val port = parts[2].toIntOrNull() ?: return null
        return Found(ip = parts[1], port = port, path = parts[3])
    }

    companion object {
        const val DISCOVERY_PORT = 40404
        private const val PROTOCOL_TAG = "HRMEDIATUBE"
    }
}
