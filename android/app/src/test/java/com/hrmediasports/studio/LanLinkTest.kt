package com.hrmediasports.studio

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.link.Base32
import com.hrmediasports.studio.link.ClientStatus
import com.hrmediasports.studio.link.FrameType
import com.hrmediasports.studio.link.LanClient
import com.hrmediasports.studio.link.LanRole
import com.hrmediasports.studio.link.LanServer
import com.hrmediasports.studio.link.LanServerListener
import com.hrmediasports.studio.link.PairingCode
import com.hrmediasports.studio.link.ScoreMessage
import com.hrmediasports.studio.link.VideoConfig
import com.hrmediasports.studio.link.VideoFrame
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.concurrent.CopyOnWriteArrayList

/** Enlace Estudio ↔ Pizarra/Cámara con sockets TCP reales en localhost. */
class LanLinkTest {
    private val events = CopyOnWriteArrayList<String>()
    private val scores = CopyOnWriteArrayList<ScoreMessage>()
    private val frames = CopyOnWriteArrayList<VideoFrame>()
    private val configs = CopyOnWriteArrayList<VideoConfig>()
    private val listener = object : LanServerListener {
        override fun onScoreboardConnected(id: String, name: String) { events += "pizarra+$id" }
        override fun onScoreboardDisconnected(id: String) { events += "pizarra-$id" }
        override fun onScore(msg: ScoreMessage) { scores += msg }
        override fun onCameraConnected(id: String, name: String, slot: Int) { events += "cam+$id@$slot" }
        override fun onCameraDisconnected(id: String, slot: Int) { events += "cam-$id@$slot" }
        override fun onVideoConfig(slot: Int, config: VideoConfig) { configs += config }
        override fun onVideoFrame(slot: Int, frame: VideoFrame) { frames += frame }
        override fun onRejected(reason: String) { events += "rechazo" }
    }
    private val servers = mutableListOf<LanServer>()
    private val clients = mutableListOf<LanClient>()

    @After fun tearDown() { clients.forEach { it.stop() }; servers.forEach { it.stop() } }

    private fun server(secret: ByteArray, maxCameras: Int = 2, timeout: Int = 5_000): Pair<LanServer, Int> {
        val s = LanServer(secret, listener, maxCameras, timeout); servers += s
        return s to s.start(0)
    }

    private fun client(code: PairingCode, role: LanRole, id: String) =
        LanClient(code, role, id, "Teléfono $id", retryDelayMs = 200, pingEveryMs = 200).also { clients += it; it.start() }

    private fun waitFor(ms: Long = 5_000, cond: () -> Boolean) {
        val end = System.currentTimeMillis() + ms
        while (!cond()) { check(System.currentTimeMillis() < end) { "tiempo agotado; eventos=$events" }; Thread.sleep(20) }
    }

    private fun match(h: Int, a: Int) = MatchState(h, a, 300, 2, 14)

    @Test fun qrRoundTripAndManualCode() {
        val code = PairingCode.random("192.168.1.20", 47800)
        assertEquals(code, PairingCode.parse(code.toUri()))
        val manual = code.manualCode()
        assertEquals(19, manual.length) // 16 letras en grupos de 4
        assertEquals(code, PairingCode.manual("192.168.1.20", 47800, manual.lowercase()))
        assertNull(PairingCode.parse("hrms://estudio?v=9&h=x&p=1&k=AAAA"))
        assertNull(PairingCode.parse("https://example.com"))
        assertEquals("MZXW6YTBOI", Base32.encode("foobar".toByteArray()))
    }

    @Test fun scoreboardSendsReadingsToStudio() {
        val code0 = PairingCode.random("127.0.0.1", 1)
        val (_, port) = server(code0.secret)
        val code = PairingCode("127.0.0.1", port, code0.secret)
        val c = client(code, LanRole.Scoreboard, "s21")
        waitFor { c.status.value is ClientStatus.Connected }
        assertEquals(ClientStatus.Connected("Pizarra"), c.status.value)
        c.send(FrameType.SCORE, ScoreMessage(match(10, 8), 0.93f, auto = true, seq = 1).encode())
        waitFor { scores.isNotEmpty() }
        assertEquals(10, scores[0].match.homePoints)
        assertEquals(0.93f, scores[0].confidence!!, 0.001f)
        assertTrue(events.contains("pizarra+s21"))
    }

    @Test fun wrongCodeIsRejectedAndDoesNotRetryForever() {
        val (_, port) = server(PairingCode.random("x", 1).secret)
        val c = client(PairingCode.random("127.0.0.1", port), LanRole.Scoreboard, "intruso")
        waitFor { c.status.value is ClientStatus.Rejected }
        assertTrue((c.status.value as ClientStatus.Rejected).reason.contains("QR"))
        assertTrue(scores.isEmpty())
    }

    @Test fun reconnectsAfterStudioRestartsAndResendsSticky() {
        val secret = PairingCode.random("x", 1).secret
        val (s1, port) = server(secret)
        val code = PairingCode("127.0.0.1", port, secret)
        val c = client(code, LanRole.Scoreboard, "s21")
        waitFor { c.status.value is ClientStatus.Connected }
        val last = ScoreMessage(match(20, 18), null, auto = false, seq = 7)
        c.sticky = com.hrmediasports.studio.link.Frame(FrameType.SCORE, last.encode())
        s1.stop()
        waitFor { c.status.value !is ClientStatus.Connected }
        val s2 = LanServer(secret, listener); servers += s2
        s2.start(port)
        waitFor { c.status.value is ClientStatus.Connected }
        waitFor { scores.any { it.seq == 7L } }                   // el último marcador llega solo al reconectar
    }

    @Test fun silentPhoneIsDetectedAsDisconnected() {
        val secret = PairingCode.random("x", 1).secret
        val (_, port) = server(secret, timeout = 600)
        val c = LanClient(PairingCode("127.0.0.1", port, secret), LanRole.Scoreboard, "mudo", "x", retryDelayMs = 60_000, pingEveryMs = 60_000)
        clients += c; c.start()
        waitFor { events.contains("pizarra+mudo") }
        waitFor(3_000) { events.contains("pizarra-mudo") }        // sin PING: el Estudio lo da por perdido
    }

    @Test fun camerasGetStableSlotsAndVideoArrives() {
        val secret = PairingCode.random("x", 1).secret
        val (_, port) = server(secret, maxCameras = 1)
        val code = PairingCode("127.0.0.1", port, secret)
        val cam = client(code, LanRole.Camera, "camA")
        waitFor { cam.status.value is ClientStatus.Connected }
        assertEquals(ClientStatus.Connected("Cámara 2"), cam.status.value)
        cam.sticky = com.hrmediasports.studio.link.Frame(FrameType.VIDEO_CONFIG, VideoConfig(1280, 720, byteArrayOf(0, 0, 0, 1, 0x67), byteArrayOf(0, 0, 0, 1, 0x68)).encode())
        cam.send(FrameType.VIDEO_CONFIG, cam.sticky!!.payload)
        cam.sendVideo(VideoFrame(1, keyFrame = false, data = byteArrayOf(9)))   // antes del primer clave: se descarta
        cam.sendVideo(VideoFrame(2, keyFrame = true, data = byteArrayOf(0, 0, 0, 1, 0x65, 1, 2)))
        cam.sendVideo(VideoFrame(3, keyFrame = false, data = byteArrayOf(0, 0, 0, 1, 0x41, 3)))
        waitFor { frames.size == 2 }
        assertEquals(listOf(2L, 3L), frames.map { it.ptsUs })
        assertEquals(1280, configs.first().width)
        // Segunda cámara con tope 1: rechazada con motivo claro.
        val extra = client(code, LanRole.Camera, "camB")
        waitFor { extra.status.value is ClientStatus.Rejected }
        // La misma cámara que reconecta recupera su lugar.
        cam.stop()
        waitFor { events.contains("cam-camA@0") }
        val again = client(code, LanRole.Camera, "camA")
        waitFor { again.status.value is ClientStatus.Connected }
        assertTrue(events.count { it == "cam+camA@0" } == 2)
    }

    @Test fun newScoreboardReplacesOldOne() {
        val secret = PairingCode.random("x", 1).secret
        val (_, port) = server(secret)
        val code = PairingCode("127.0.0.1", port, secret)
        val a = client(code, LanRole.Scoreboard, "viejo")
        waitFor { a.status.value is ClientStatus.Connected }
        val b = client(code, LanRole.Scoreboard, "nuevo")
        waitFor { b.status.value is ClientStatus.Connected }
        waitFor { events.contains("pizarra-viejo") }
        waitFor { a.status.value is ClientStatus.Rejected }                // la vieja deja de reintentar
        Thread.sleep(600)
        assertTrue(b.status.value is ClientStatus.Connected)              // y no se turnan
        b.send(FrameType.SCORE, ScoreMessage(match(1, 0), null, false, 1).encode())
        waitFor { scores.isNotEmpty() }
        assertNotNull(scores.last())
    }

    @Test fun invalidScoreIsDiscarded() {
        assertNull(ScoreMessage.decode("h=999&a=0&c=0&p=1&s=0&seq=1".toByteArray())) // fuera de rango
        val ok = ScoreMessage(match(3, 4), null, false, 2, clockRunning = true)
        assertEquals(ok, ScoreMessage.decode(ok.encode()))
    }
}
