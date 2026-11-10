package com.hrmediasports.studio.camera

import com.hrmediasports.link.Accepted
import com.hrmediasports.link.Challenge
import com.hrmediasports.link.Crypto
import com.hrmediasports.link.Handshake
import com.hrmediasports.link.Hello
import com.hrmediasports.link.HelloError
import com.hrmediasports.link.HelloOk
import com.hrmediasports.link.LinkMessage
import com.hrmediasports.link.Reconnect
import com.hrmediasports.link.Rejected
import com.hrmediasports.link.SessionCodec
import com.hrmediasports.link.parseLinkMessage
import com.hrmediasports.link.toJson
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import java.util.concurrent.TimeUnit

/** Eventos del enlace con el estudio. */
sealed interface LinkEvent {
    /** Canal autenticado en ambos sentidos. `state`: "pending" (espera aceptación) o "accepted". */
    data class Authenticated(val state: String) : LinkEvent
    data object Accepted : LinkEvent
    data class Rejected(val reason: String) : LinkEvent

    /** Mensaje de sesión ya verificado (firma y secuencia). */
    data class Message(val message: LinkMessage) : LinkEvent

    /** Se perdió el canal; `retryInMillis` != null si se reintentará solo. */
    data class Lost(val reason: String, val retryInMillis: Long?) : LinkEvent

    /** Error que NO se arregla reintentando (credencial inválida, estudio nuevo, etc.). */
    data class Fatal(val code: String) : LinkEvent
}

/**
 * Cliente del enlace local (ws://). Autentica con HMAC (el secreto nunca viaja), firma cada mensaje y
 * se reconecta solo con el token derivado, siempre con el mismo `deviceId`: el estudio reemplaza la
 * conexión anterior en lugar de crear una fuente nueva.
 */
class LinkClient(
    private val scope: CoroutineScope,
    private val deviceId: String,
    private val deviceName: String,
    private val role: String = "camera",
    private val http: OkHttpClient = OkHttpClient.Builder()
        .pingInterval(10, TimeUnit.SECONDS)           // detecta cortes de Wi-Fi sin esperar al TCP
        .connectTimeout(5, TimeUnit.SECONDS)
        .readTimeout(0, TimeUnit.MILLISECONDS)
        .build(),
) {
    private val _events = MutableSharedFlow<LinkEvent>(extraBufferCapacity = 128)
    val events: SharedFlow<LinkEvent> = _events.asSharedFlow()

    private var host = ""
    private var port = 0
    private var key = ByteArray(0)
    private var auth = Handshake.AUTH_PAIRING
    /** Secreto del QR mientras no se haya aceptado: de él se deriva el token al aceptar. */
    private var pairingSecret: ByteArray? = null

    private var socket: WebSocket? = null
    private var codec: SessionCodec? = null
    private var generation = 0
    private var attempt = 0
    private var retryJob: Job? = null
    private var stopped = true

    /** Primer vínculo con el secreto del QR. */
    @Synchronized
    fun connectWithPairing(host: String, port: Int, secret: ByteArray) {
        pairingSecret = secret
        start(host, port, secret, Handshake.AUTH_PAIRING)
    }

    /** Reconexión con el token guardado. */
    @Synchronized
    fun connectWithToken(binding: StudioBinding) {
        pairingSecret = null
        start(binding.host, binding.port, binding.token, Handshake.AUTH_TOKEN)
    }

    /** Token de reconexión a guardar una vez aceptado (derivado, nunca recibido por la red). */
    @Synchronized
    fun derivedBinding(): StudioBinding? = pairingSecret?.let {
        StudioBinding(host, port, Crypto.b64url(Handshake.deviceToken(it, deviceId)))
    }

    @Synchronized
    private fun start(host: String, port: Int, key: ByteArray, auth: String) {
        stopInternal()
        this.host = host; this.port = port; this.key = key; this.auth = auth
        stopped = false
        attempt = 0
        open()
    }

    @Synchronized
    fun send(message: LinkMessage): Boolean {
        val c = codec ?: return false
        val s = socket ?: return false
        return s.send(c.seal(message))
    }

    @Synchronized
    fun close() = stopInternal()

    private fun stopInternal() {
        stopped = true
        retryJob?.cancel()
        generation++
        codec = null
        socket?.close(1000, "bye")
        socket = null
    }

    private fun open() {
        val gen = ++generation
        codec = null
        val req = Request.Builder().url("ws://$host:$port/ws/device").build()
        socket = http.newWebSocket(req, Listener(gen))
    }

    private fun scheduleRetry(reason: String) {
        synchronized(this) {
            if (stopped) return
            val wait = Reconnect.delayMillis(attempt++)
            _events.tryEmit(LinkEvent.Lost(reason, wait))
            retryJob?.cancel()
            retryJob = scope.launch {
                delay(wait)
                synchronized(this@LinkClient) { if (!stopped) open() }
            }
        }
    }

    private fun fatal(code: String) {
        synchronized(this) {
            stopped = true
            generation++
            socket?.close(1000, code)
            socket = null
            codec = null
        }
        _events.tryEmit(LinkEvent.Fatal(code))
    }

    private inner class Listener(private val gen: Int) : WebSocketListener() {
        private var phase = 0
        private var sid = ""
        private var nonceS = ""
        private var nonceD = ""
        private var sessionCodec: SessionCodec? = null

        private fun current() = synchronized(this@LinkClient) { gen == generation && !stopped }

        override fun onMessage(webSocket: WebSocket, text: String) {
            if (!current()) return
            when (phase) {
                0 -> {
                    val first = parseLinkMessage(text)
                    // Si el estudio aún no se creó, responde un error en lugar del desafío.
                    if (first is HelloError) return onHelloError(first)
                    val ch = first as? Challenge ?: return fatal("bad_challenge")
                    sid = ch.sessionId; nonceS = ch.nonceS; nonceD = Crypto.randomHex(16)
                    val hello = Hello(
                        deviceId = deviceId, name = deviceName, role = role, auth = auth, nonceD = nonceD,
                        proof = Handshake.deviceProof(key, sid, nonceS, nonceD, deviceId, auth),
                    )
                    phase = 1
                    webSocket.send(hello.toJson())
                }
                1 -> when (val m = parseLinkMessage(text)) {
                    is HelloError -> onHelloError(m)
                    is HelloOk -> {
                        // El estudio también debe demostrar que conoce el secreto (autenticación mutua).
                        val expected = Handshake.serverProof(key, sid, nonceS, nonceD, deviceId)
                        if (!Crypto.constantTimeEquals(expected, m.proof)) return fatal("server_not_authentic")
                        val c = SessionCodec.forDevice(Handshake.sessionKey(key, sid, nonceS, nonceD))
                        sessionCodec = c
                        synchronized(this@LinkClient) { codec = c; attempt = 0 }
                        phase = 2
                        _events.tryEmit(LinkEvent.Authenticated(m.state))
                    }
                    else -> fatal("bad_handshake")
                }
                else -> {
                    val m = sessionCodec?.open(text) ?: return   // firma o secuencia inválida: se ignora
                    when (m) {
                        is Accepted -> {
                            // Desde ahora los reintentos usan el token derivado: el estudio rechaza el QR para un dispositivo ya aceptado.
                            synchronized(this@LinkClient) {
                                pairingSecret?.let { key = Handshake.deviceToken(it, deviceId); auth = Handshake.AUTH_TOKEN }
                            }
                            _events.tryEmit(LinkEvent.Accepted)
                        }
                        is Rejected -> { _events.tryEmit(LinkEvent.Rejected(m.reason)); close() }
                        else -> _events.tryEmit(LinkEvent.Message(m))
                    }
                }
            }
        }

        private fun onHelloError(e: HelloError) {
            when (e.code) {
                // Pueden resolverse solos (el estudio aún no se creó / se está reiniciando): se reintenta.
                "no_studio" -> scheduleRetry(e.code)
                else -> fatal(e.code)
            }
        }

        /** El servidor inició el cierre (reemplazo por reconexión, estudio reiniciado…): hay que responder el cierre. */
        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
            webSocket.close(code.takeIf { it in 1000..4999 && it != 1005 } ?: 1000, reason)
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            if (current() && phase >= 1) scheduleRetry(reason.ifBlank { "closed" })
            else if (current()) scheduleRetry("closed_before_auth")
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            if (current()) scheduleRetry(t.message ?: "failure")
        }
    }
}
