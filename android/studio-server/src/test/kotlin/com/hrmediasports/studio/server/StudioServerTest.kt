package com.hrmediasports.studio.server

import com.hrmediasports.link.Accepted
import com.hrmediasports.link.Answer
import com.hrmediasports.link.Challenge
import com.hrmediasports.link.Crypto
import com.hrmediasports.link.Handshake
import com.hrmediasports.link.Hello
import com.hrmediasports.link.HelloError
import com.hrmediasports.link.HelloOk
import com.hrmediasports.link.LinkJson
import com.hrmediasports.link.LinkMessage
import com.hrmediasports.link.Offer
import com.hrmediasports.link.OnAir
import com.hrmediasports.link.PairingInfo
import com.hrmediasports.link.Rejected
import com.hrmediasports.link.SessionCodec
import com.hrmediasports.link.Telemetry
import com.hrmediasports.link.parseLinkMessage
import com.hrmediasports.link.toJson
import io.ktor.client.plugins.websocket.DefaultClientWebSocketSession
import io.ktor.client.plugins.websocket.WebSockets
import io.ktor.client.plugins.websocket.webSocket
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.post
import io.ktor.client.call.body
import io.ktor.http.HttpStatusCode
import io.ktor.server.testing.ApplicationTestBuilder
import io.ktor.server.testing.testApplication
import io.ktor.websocket.Frame
import io.ktor.websocket.readText
import kotlinx.coroutines.withTimeout
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Pruebas con WebSockets reales de Ktor y un "dispositivo" que usa las mismas primitivas que la app. */
class StudioServerTest {
    private class Link(val codec: SessionCodec, val state: String)

    private suspend fun DefaultClientWebSocketSession.recvText(): String =
        withTimeout(5_000) { (incoming.receive() as Frame.Text).readText() }

    /** Apretón de manos del lado dispositivo. Devuelve el enlace o el código de error del servidor. */
    private suspend fun DefaultClientWebSocketSession.connect(
        p: PairingInfo, id: String, auth: String, key: ByteArray,
    ): Pair<Link?, String?> {
        val ch = parseLinkMessage(recvText()) as Challenge
        val nonceD = Crypto.randomHex(16)
        send(Frame.Text(Hello(deviceId = id, name = "Cel $id", role = "camera", auth = auth, nonceD = nonceD,
            proof = Handshake.deviceProof(key, ch.sessionId, ch.nonceS, nonceD, id, auth)).toJson()))
        val reply = parseLinkMessage(recvText())
        if (reply is HelloError) return null to reply.code
        reply as HelloOk
        // El dispositivo también verifica que el estudio conoce el secreto.
        assertEquals(Handshake.serverProof(key, ch.sessionId, ch.nonceS, nonceD, id), reply.proof)
        return Link(SessionCodec.forDevice(Handshake.sessionKey(key, ch.sessionId, ch.nonceS, nonceD)), reply.state) to null
    }

    private suspend fun DefaultClientWebSocketSession.recvSealed(link: Link): LinkMessage = link.codec.open(recvText())!!

    private fun withStudio(block: suspend ApplicationTestBuilder.(StudioCore, PairingInfo) -> Unit) = testApplication {
        val core = StudioCore()
        application { studioModule(core, 8765, Hub(core)) }
        block(core, core.createStudio("192.168.1.5", 8765))
    }

    @Test fun deviceFlow() = withStudio { core, p ->
        val client = createClient { install(WebSockets) }
        client.webSocket("/ws/ui") {
            val ui = this
            recvText()
            client.webSocket("/ws/device") {
                val (l, err) = connect(p, "cam1", Handshake.AUTH_PAIRING, p.secret)
                assertNull(err)
                val link = l!!
                assertEquals("pending", link.state)

                // Pendiente: su oferta NO llega al estudio.
                send(Frame.Text(link.codec.seal(Offer("v=0 pendiente"))))

                // Se da tiempo a que el servidor procese (y descarte) esa oferta antes de aceptar:
                // los marcos de dos sockets distintos no tienen orden garantizado entre sí.
                kotlinx.coroutines.delay(400)

                // El operador acepta.
                ui.send(Frame.Text("""{"type":"accept","deviceId":"cam1"}"""))
                assertEquals(Accepted, recvSealed(link))

                // Ahora sí: oferta → UI como "signal"; respuesta del UI → dispositivo.
                send(Frame.Text(link.codec.seal(Offer("v=0 oferta"))))
                var signal: String? = null
                while (signal == null) { val t = ui.recvText(); if (t.contains("\"signal\"")) signal = t }
                assertNotNull(signal)
                assertTrue(signal!!.contains("v=0 oferta"))
                assertTrue("la oferta de pendiente no debió reenviarse", !signal!!.contains("pendiente"))

                ui.send(Frame.Text("""{"type":"answer","deviceId":"cam1","sdp":"v=0 respuesta"}"""))
                assertEquals(Answer("v=0 respuesta"), recvSealed(link))

                // AL AIRE llega al dispositivo.
                ui.send(Frame.Text("""{"type":"onair","deviceId":"cam1","active":true}"""))
                assertEquals(OnAir(true), recvSealed(link))
                assertTrue(core.snapshot().single().onAir)

                // Telemetría firmada actualiza el estado.
                send(Frame.Text(link.codec.seal(Telemetry(t = 1, width = 1280, height = 720, fps = 30.0, hardwareEncoder = true))))
                withTimeout(5_000) { while (core.snapshot().single().telemetry == null) kotlinx.coroutines.delay(20) }
                assertEquals(720, core.snapshot().single().telemetry!!.height)
            }
        }
        // Tras cerrarse el WebSocket, la cámara queda fuera de línea PERO sigue siendo una sola entrada.
        withTimeout(5_000) { while (core.snapshot().single().online) kotlinx.coroutines.delay(20) }
        assertEquals(1, core.snapshot().size)

        // Reconexión con el token derivado: acepta de inmediato y NO duplica la fuente.
        client.webSocket("/ws/device") {
            val (l, err) = connect(p, "cam1", Handshake.AUTH_TOKEN, Handshake.deviceToken(p.secret, "cam1"))
            assertNull(err)
            assertEquals("accepted", l!!.state)
            assertEquals(Accepted, recvSealed(l))
            assertEquals(1, core.snapshot().size)
            assertTrue(core.snapshot().single().online)
        }
    }

    @Test fun secondConnectionOfSameDeviceSupersedesTheFirst() = withStudio { core, p ->
        val client = createClient { install(WebSockets) }
        client.webSocket("/ws/ui") {
            val ui = this
            recvText()
            client.webSocket("/ws/device") {
                val first = connect(p, "cam1", Handshake.AUTH_PAIRING, p.secret).first!!
                ui.send(Frame.Text("""{"type":"accept","deviceId":"cam1"}"""))
                assertEquals(Accepted, recvSealed(first))
                val firstSession = this
                client.webSocket("/ws/device") {
                    val second = connect(p, "cam1", Handshake.AUTH_TOKEN, Handshake.deviceToken(p.secret, "cam1")).first!!
                    assertEquals(Accepted, recvSealed(second))
                    // La conexión vieja fue cerrada por el servidor.
                    withTimeout(5_000) { firstSession.closeReason.await() }
                    assertEquals(1, core.snapshot().size)
                    assertTrue(core.snapshot().single().online)
                }
            }
        }
    }

    @Test fun wrongSecretIsRejected() = withStudio { _, p ->
        val client = createClient { install(WebSockets) }
        client.webSocket("/ws/device") {
            val (link, err) = connect(p, "evil", Handshake.AUTH_PAIRING, Crypto.randomBytes(16))
            assertNull(link)
            assertEquals("bad_proof", err)
        }
    }

    @Test fun removedDeviceGetsRejectedAndCannotReconnect() = withStudio { core, p ->
        val client = createClient { install(WebSockets) }
        client.webSocket("/ws/ui") {
            val ui = this
            recvText()
            client.webSocket("/ws/device") {
                val link = connect(p, "cam1", Handshake.AUTH_PAIRING, p.secret).first!!
                ui.send(Frame.Text("""{"type":"accept","deviceId":"cam1"}"""))
                assertEquals(Accepted, recvSealed(link))
                ui.send(Frame.Text("""{"type":"forget","deviceId":"cam1"}"""))
                assertEquals(Rejected("removed"), recvSealed(link))
            }
            assertTrue(core.snapshot().isEmpty())
            client.webSocket("/ws/device") {
                val (l, err) = connect(p, "cam1", Handshake.AUTH_TOKEN, Handshake.deviceToken(p.secret, "cam1"))
                assertNull(l)
                assertEquals("unknown_device", err)
            }
        }
    }

    @Test fun uiAndApiAreOnlyForThisComputer() = withStudio { _, _ ->
        val client = createClient { }
        assertEquals(HttpStatusCode.OK, client.get("/api/state").status)
        // DNS rebinding / acceso desde otro host.
        assertEquals(HttpStatusCode.Forbidden, client.get("/api/state") { header("Host", "evil.example.com") }.status)
        // Página web ajena intentando controlar el estudio.
        assertEquals(HttpStatusCode.Forbidden, client.post("/api/studio") { header("Origin", "http://evil.example.com") }.status)
    }

    @Test fun createStudioReturnsUriAndQrPng() = withStudio { core, _ ->
        val client = createClient { }
        val r = client.post("/api/studio?host=192.168.7.7")
        assertEquals(HttpStatusCode.OK, r.status)
        val json = LinkJson.parseToJsonElement(r.body<ByteArray>().decodeToString()).jsonObject
        val parsed = PairingInfo.parse(json["uri"]!!.jsonPrimitive.content)!!
        assertEquals("192.168.7.7", parsed.host)
        assertEquals(core.pairing!!.sessionId, parsed.sessionId)
        val png = client.get("/api/qr.png").body<ByteArray>()
        assertEquals(0x89.toByte(), png[0])
        assertEquals('P'.code.toByte(), png[1])
    }
}
