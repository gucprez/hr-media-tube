package com.hrmediasports.studio.server

import com.hrmediasports.link.Accepted
import com.hrmediasports.link.Answer
import com.hrmediasports.link.Bye
import com.hrmediasports.link.Challenge
import com.hrmediasports.link.Crypto
import com.hrmediasports.link.Handshake
import com.hrmediasports.link.Hello
import com.hrmediasports.link.HelloError
import com.hrmediasports.link.HelloOk
import com.hrmediasports.link.IceCandidateMsg
import com.hrmediasports.link.LinkJson
import com.hrmediasports.link.LinkMessage
import com.hrmediasports.link.Offer
import com.hrmediasports.link.OnAir
import com.hrmediasports.link.Rejected
import com.hrmediasports.link.SessionCodec
import com.hrmediasports.link.Telemetry
import com.hrmediasports.link.parseLinkMessage
import com.hrmediasports.link.toJson
import io.ktor.http.HttpStatusCode
import io.ktor.server.application.Application
import io.ktor.server.application.ApplicationCallPipeline
import io.ktor.server.application.call
import io.ktor.server.application.install
import io.ktor.server.http.content.staticResources
import io.ktor.server.request.header
import io.ktor.server.request.host
import io.ktor.server.request.path
import io.ktor.server.response.respond
import io.ktor.server.response.respondBytes
import io.ktor.server.response.respondText
import io.ktor.server.routing.get
import io.ktor.server.routing.post
import io.ktor.server.routing.routing
import io.ktor.server.websocket.DefaultWebSocketServerSession
import io.ktor.server.websocket.WebSockets
import io.ktor.server.websocket.webSocket
import io.ktor.http.ContentType
import io.ktor.websocket.CloseReason
import io.ktor.websocket.Frame
import io.ktor.websocket.close
import io.ktor.websocket.readText
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonObject
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.CopyOnWriteArraySet

const val DEFAULT_PORT = 8765

/** Conexión autenticada de un dispositivo. El sellado y el envío son atómicos para no desordenar `seq`. */
class DeviceConn(val connId: Long, val session: DefaultWebSocketServerSession, private val codec: SessionCodec) {
    private val lock = Mutex()
    suspend fun send(message: LinkMessage) = lock.withLock {
        runCatching { session.send(Frame.Text(codec.seal(message))) }
    }
    fun open(text: String) = codec.open(text)
}

class Hub(val core: StudioCore) {
    val ui = CopyOnWriteArraySet<DefaultWebSocketServerSession>()
    val conns = ConcurrentHashMap<String, DeviceConn>()

    suspend fun broadcast(json: String) {
        ui.forEach { s -> runCatching { s.send(Frame.Text(json)) } }
    }

    fun stateJson(): String = buildJsonObject {
        put("type", "state")
        core.pairing?.let {
            putJsonObject("pairing") {
                put("sessionId", it.sessionId)
                put("host", it.host)
                put("port", it.port)
                put("expiresAt", it.expiresAtMillis)
            }
        }
        put("devices", LinkJson.encodeToJsonElement(ListSerializer(DeviceInfo.serializer()), core.snapshot()))
    }.toString()

    suspend fun broadcastState() = broadcast(stateJson())
}

/** Solo la propia laptop puede usar la interfaz del estudio y su API (no la red local). */
private fun io.ktor.server.application.ApplicationCall.isLocalUi(port: Int): Boolean {
    val remote = request.local.remoteAddress
    val loopback = remote == "127.0.0.1" || remote == "::1" || remote == "0:0:0:0:0:0:0:1" || remote == "localhost"
    val hostOk = request.host() in setOf("localhost", "127.0.0.1", "::1", "[::1]")
    // Un WebSocket no está sujeto a CORS: se valida el origen para que una página web ajena no controle el estudio.
    val origin = request.header("Origin")
    val originOk = origin == null || origin in setOf(
        "http://localhost:$port", "http://127.0.0.1:$port", "http://[::1]:$port", "http://localhost", "http://127.0.0.1",
    )
    return loopback && hostOk && originOk
}

fun Application.studioModule(core: StudioCore = StudioCore(), port: Int = DEFAULT_PORT, hub: Hub = Hub(core)) {
    install(WebSockets) {
        pingPeriodMillis = 15_000
        timeoutMillis = 30_000
        maxFrameSize = 1_000_000
    }

    intercept(ApplicationCallPipeline.Plugins) {
        if (call.request.path() != "/ws/device" && !call.isLocalUi(port)) {
            call.respond(HttpStatusCode.Forbidden, "Solo disponible en esta computadora")
            finish()
        }
    }

    routing {
        staticResources("/", "web")

        get("/api/hosts") {
            call.respondText(
                lanAddresses().joinToString(",", "[", "]") { """{"name":${JsonPrimitive(it.name)},"ip":"${it.ip}"}""" },
                ContentType.Application.Json,
            )
        }

        post("/api/studio") {
            val host = call.request.queryParameters["host"]?.takeIf { it.isNotBlank() }
                ?: lanAddresses().firstOrNull()?.ip
                ?: "127.0.0.1"
            val pairing = core.createStudio(host, port)
            hub.conns.values.forEach { c -> runCatching { c.session.close(CloseReason(CloseReason.Codes.GOING_AWAY, "studio_reset")) } }
            hub.conns.clear()
            hub.broadcastState()
            call.respondText(
                buildJsonObject {
                    put("sessionId", pairing.sessionId)
                    put("host", pairing.host)
                    put("port", pairing.port)
                    put("expiresAt", pairing.expiresAtMillis)
                    put("uri", pairing.toUri())
                }.toString(),
                ContentType.Application.Json,
            )
        }

        get("/api/qr.png") {
            val p = core.pairing
            if (p == null) call.respond(HttpStatusCode.NotFound) else call.respondBytes(qrPng(p.toUri()), ContentType.Image.PNG)
        }

        get("/api/state") { call.respondText(hub.stateJson(), ContentType.Application.Json) }

        // ---------- Interfaz del estudio (navegador en esta laptop) ----------
        webSocket("/ws/ui") {
            hub.ui.add(this)
            try {
                send(Frame.Text(hub.stateJson()))
                for (frame in incoming) {
                    if (frame !is Frame.Text) continue
                    val obj = runCatching { LinkJson.parseToJsonElement(frame.readText()).jsonObject }.getOrNull() ?: continue
                    handleUi(hub, obj)
                }
            } finally {
                hub.ui.remove(this)
            }
        }

        // ---------- Dispositivos de la red local (cámaras, lector) ----------
        webSocket("/ws/device") { handleDevice(hub) }
    }
}

private suspend fun handleUi(hub: Hub, msg: JsonObject) {
    val core = hub.core
    val id = msg["deviceId"]?.jsonPrimitive?.contentOrNull
    when (msg["type"]?.jsonPrimitive?.contentOrNull) {
        "accept" -> if (id != null) when (core.accept(id)) {
            AcceptResult.OK -> {
                hub.conns[id]?.send(Accepted)
                hub.broadcastState()
            }
            AcceptResult.CAMERA_LIMIT -> hub.broadcast(
                buildJsonObject {
                    put("type", "notice")
                    put("text", "Primera versión: ${core.maxCameras} cámara a la vez. Desconecta la actual antes de aceptar otra (se amplía tras validar 720p/30 en dispositivos reales).")
                }.toString(),
            )
            AcceptResult.UNKNOWN -> Unit
        }
        "reject", "forget" -> if (id != null) {
            core.remove(id)
            hub.conns.remove(id)?.let { c ->
                c.send(Rejected("removed"))
                runCatching { c.session.close(CloseReason(CloseReason.Codes.NORMAL, "removed")) }
            }
            hub.broadcastState()
        }
        "answer" -> if (id != null) {
            msg["sdp"]?.jsonPrimitive?.contentOrNull?.let { hub.conns[id]?.send(Answer(it)) }
        }
        "ice" -> if (id != null) {
            val cand = msg["candidate"]?.jsonPrimitive?.contentOrNull ?: return
            hub.conns[id]?.send(
                IceCandidateMsg(cand, msg["sdpMid"]?.jsonPrimitive?.contentOrNull, msg["sdpMLineIndex"]?.jsonPrimitive?.int ?: 0),
            )
        }
        "onair" -> if (id != null) {
            val active = msg["active"]?.jsonPrimitive?.boolean ?: return
            core.setOnAir(id, active)
            hub.conns[id]?.send(OnAir(active))
            hub.broadcastState()
        }
    }
}

private suspend fun DefaultWebSocketServerSession.sendPlain(m: LinkMessage) = runCatching { send(Frame.Text(m.toJson())) }

private suspend fun DefaultWebSocketServerSession.handleDevice(hub: Hub) {
    val core = hub.core
    val sid = core.pairing?.sessionId
    if (sid == null) {
        sendPlain(HelloError("no_studio"))
        close(CloseReason(CloseReason.Codes.TRY_AGAIN_LATER, "no_studio"))
        return
    }
    val nonceS = Crypto.randomHex(16)
    sendPlain(Challenge(sessionId = sid, nonceS = nonceS))

    val first = withTimeoutOrNull(10_000) { (incoming.receive() as? Frame.Text)?.readText() }
    val hello = first?.let { parseLinkMessage(it) } as? Hello
    if (hello == null) {
        sendPlain(HelloError("bad_hello"))
        close(CloseReason(CloseReason.Codes.PROTOCOL_ERROR, "bad_hello"))
        return
    }
    val decision = core.decide(nonceS, hello)
    if (decision !is HelloDecision.Ok) {
        decision as HelloDecision.Fail
        sendPlain(HelloError(decision.code, decision.detail))
        close(CloseReason(CloseReason.Codes.VIOLATED_POLICY, decision.code))
        return
    }

    val ticket = core.register(hello, decision)
    val sessionKey = Handshake.sessionKey(decision.authKey, sid, nonceS, hello.nonceD)
    val conn = DeviceConn(ticket.connId, this, SessionCodec.forServer(sessionKey))
    // Una reconexión reemplaza a la conexión anterior del mismo dispositivo: nunca quedan dos.
    hub.conns.put(hello.deviceId, conn)?.let { old ->
        runCatching { old.session.close(CloseReason(CloseReason.Codes.GOING_AWAY, "superseded")) }
    }
    val state = if (decision.reconnect) "accepted" else "pending"
    sendPlain(HelloOk(Handshake.serverProof(decision.authKey, sid, nonceS, hello.nonceD, hello.deviceId), state))
    if (decision.reconnect) conn.send(Accepted)
    hub.broadcastState()
    hub.broadcast(buildJsonObject { put("type", "device_connected"); put("deviceId", hello.deviceId) }.toString())

    try {
        var invalid = 0
        for (frame in incoming) {
            if (frame !is Frame.Text) continue
            val m = conn.open(frame.readText())
            if (m == null) {
                if (++invalid >= 3) { close(CloseReason(CloseReason.Codes.VIOLATED_POLICY, "bad_mac")); break }
                continue
            }
            val accepted = core.get(hello.deviceId)?.state == DeviceState.ACCEPTED
            when (m) {
                is Offer, is IceCandidateMsg -> if (accepted) {
                    hub.broadcast(buildJsonObject {
                        put("type", "signal")
                        put("deviceId", hello.deviceId)
                        put("msg", LinkJson.parseToJsonElement(m.toJson()))
                    }.toString())
                }
                is Telemetry -> if (accepted) {
                    core.updateTelemetry(hello.deviceId, m)
                    hub.broadcastState()
                }
                is Bye -> { close(CloseReason(CloseReason.Codes.NORMAL, "bye")); break }
                else -> Unit
            }
        }
    } finally {
        hub.conns.remove(hello.deviceId, conn)
        if (core.markOffline(hello.deviceId, ticket.connId)) hub.broadcastState()
    }
}
