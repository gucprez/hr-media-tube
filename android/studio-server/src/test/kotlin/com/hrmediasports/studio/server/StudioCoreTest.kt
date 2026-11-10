package com.hrmediasports.studio.server

import com.hrmediasports.link.Crypto
import com.hrmediasports.link.Handshake
import com.hrmediasports.link.Hello
import com.hrmediasports.link.PairingInfo
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class StudioCoreTest {
    private var now = 1_000L
    private val core = StudioCore { now }
    private val pairing: PairingInfo = core.createStudio("192.168.1.5", 8765)

    private fun hello(id: String, auth: String, key: ByteArray, nonceS: String = "ns", nonceD: String = "nd", name: String = "Cel") =
        Hello(
            deviceId = id, name = name, role = "camera", auth = auth, nonceD = nonceD,
            proof = Handshake.deviceProof(key, pairing.sessionId, nonceS, nonceD, id, auth),
        )

    private fun pair(id: String): Ticket {
        val h = hello(id, Handshake.AUTH_PAIRING, pairing.secret)
        val d = core.decide("ns", h) as HelloDecision.Ok
        return core.register(h, d)
    }

    @Test fun pairingCreatesPendingDevice() {
        pair("a")
        val d = core.snapshot().single()
        assertEquals("pending", d.state)
        assertTrue(d.online)
    }

    @Test fun badProofIsRejectedAndLocksAfterTooManyTries() {
        val wrong = Crypto.randomBytes(16)
        repeat(StudioCore.MAX_FAILED_PROOFS) {
            val r = core.decide("ns", hello("x", Handshake.AUTH_PAIRING, wrong)) as HelloDecision.Fail
            assertEquals("bad_proof", r.code)
        }
        // Incluso con el secreto correcto, ya está bloqueado.
        val r = core.decide("ns", hello("x", Handshake.AUTH_PAIRING, pairing.secret)) as HelloDecision.Fail
        assertEquals("locked", r.code)
    }

    @Test fun expiredPairingIsRejected() {
        now = pairing.expiresAtMillis + 1
        val r = core.decide("ns", hello("a", Handshake.AUTH_PAIRING, pairing.secret)) as HelloDecision.Fail
        assertEquals("pairing_expired", r.code)
    }

    @Test fun acceptedDeviceReconnectsWithTokenEvenAfterExpiry() {
        pair("a")
        assertEquals(AcceptResult.OK, core.accept("a"))
        now = pairing.expiresAtMillis + 10_000
        val token = Handshake.deviceToken(pairing.secret, "a")
        val h = hello("a", Handshake.AUTH_TOKEN, token, nonceS = "ns2")
        val d = core.decide("ns2", h) as HelloDecision.Ok
        assertTrue(d.reconnect)
    }

    @Test fun reconnectDoesNotDuplicateTheDevice() {
        pair("a"); core.accept("a")
        val token = Handshake.deviceToken(pairing.secret, "a")
        val first = core.register(hello("a", Handshake.AUTH_TOKEN, token), core.decide("ns", hello("a", Handshake.AUTH_TOKEN, token)) as HelloDecision.Ok)
        val second = core.register(hello("a", Handshake.AUTH_TOKEN, token), core.decide("ns", hello("a", Handshake.AUTH_TOKEN, token)) as HelloDecision.Ok)
        assertEquals(1, core.snapshot().size)
        assertTrue(second.supersededOnline)
        assertTrue(second.connId > first.connId)
        // El cierre tardío de la conexión vieja no deja al dispositivo fuera de línea.
        assertFalse(core.markOffline("a", first.connId))
        assertTrue(core.snapshot().single().online)
        assertTrue(core.markOffline("a", second.connId))
        assertFalse(core.snapshot().single().online)
    }

    @Test fun acceptedDeviceCannotBeImpersonatedWithTheQr() {
        pair("a"); core.accept("a")
        val r = core.decide("ns", hello("a", Handshake.AUTH_PAIRING, pairing.secret)) as HelloDecision.Fail
        assertEquals("device_exists", r.code)
    }

    @Test fun tokenOfAnotherDeviceDoesNotWork() {
        pair("a"); core.accept("a"); pair("b"); core.accept("b")
        val tokenB = Handshake.deviceToken(pairing.secret, "b")
        val r = core.decide("ns", hello("a", Handshake.AUTH_TOKEN, tokenB)) as HelloDecision.Fail
        assertEquals("bad_proof", r.code)
    }

    @Test fun tokenOfPendingOrUnknownDeviceIsRejected() {
        pair("a")  // pendiente
        val token = Handshake.deviceToken(pairing.secret, "a")
        assertEquals("unknown_device", (core.decide("ns", hello("a", Handshake.AUTH_TOKEN, token)) as HelloDecision.Fail).code)
        assertEquals("unknown_device", (core.decide("ns", hello("zzz", Handshake.AUTH_TOKEN, token)) as HelloDecision.Fail).code)
    }

    @Test fun removeForgetsDeviceAndNewStudioClearsAll() {
        pair("a"); core.accept("a")
        core.remove("a")
        assertTrue(core.snapshot().isEmpty())
        pair("b")
        core.createStudio("10.0.0.2", 8765)
        assertTrue(core.snapshot().isEmpty())
    }

    @Test fun rejectsBadRoleVersionAndDeviceId() {
        val base = hello("a", Handshake.AUTH_PAIRING, pairing.secret)
        assertEquals("bad_role", (core.decide("ns", base.copy(role = "admin")) as HelloDecision.Fail).code)
        assertEquals("unsupported_version", (core.decide("ns", base.copy(v = 9)) as HelloDecision.Fail).code)
        assertEquals("bad_device", (core.decide("ns", base.copy(deviceId = "")) as HelloDecision.Fail).code)
    }

    @Test fun noStudioNoPairing() {
        val empty = StudioCore { now }
        assertEquals("no_studio", (empty.decide("ns", hello("a", Handshake.AUTH_PAIRING, pairing.secret)) as HelloDecision.Fail).code)
    }

    @Test fun onlyOneCameraCanBeAcceptedInTheFirstVersion() {
        pair("a"); pair("b")
        assertEquals(AcceptResult.OK, core.accept("a"))
        assertEquals(AcceptResult.CAMERA_LIMIT, core.accept("b"))
        assertEquals("pending", core.snapshot().first { it.id == "b" }.state)
        // Aceptar de nuevo la ya aceptada es idempotente; al desconectarla se puede aceptar la otra.
        assertEquals(AcceptResult.OK, core.accept("a"))
        core.remove("a")
        assertEquals(AcceptResult.OK, core.accept("b"))
    }

    @Test fun limitIsConfigurable() {
        val two = StudioCore(maxCameras = 2) { now }
        val p = two.createStudio("1.1.1.1", 8765)
        fun join(id: String) {
            val h = Hello(deviceId = id, name = id, role = "camera", auth = "pairing", nonceD = "nd",
                proof = Handshake.deviceProof(p.secret, p.sessionId, "ns", "nd", id, "pairing"))
            two.register(h, two.decide("ns", h) as HelloDecision.Ok)
        }
        join("a"); join("b"); join("c")
        assertEquals(AcceptResult.OK, two.accept("a"))
        assertEquals(AcceptResult.OK, two.accept("b"))
        assertEquals(AcceptResult.CAMERA_LIMIT, two.accept("c"))
    }

    @Test fun unknownDeviceCannotBeAccepted() {
        assertEquals(AcceptResult.UNKNOWN, core.accept("nadie"))
    }
}
