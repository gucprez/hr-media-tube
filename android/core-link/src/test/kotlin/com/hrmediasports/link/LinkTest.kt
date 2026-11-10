package com.hrmediasports.link

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class LinkTest {
    @Test fun hmacMatchesRfc4231Vector() {
        val key = ByteArray(20) { 0x0b }
        assertEquals(
            "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7",
            Crypto.hmacHex(key, "Hi There"),
        )
    }

    @Test fun pairingUriRoundTrip() {
        val p = PairingInfo.create("192.168.1.20", 8765, nowMillis = 1_000_000)
        val parsed = PairingInfo.parse(p.toUri())!!
        assertEquals(p.sessionId, parsed.sessionId)
        assertEquals("192.168.1.20", parsed.host)
        assertEquals(8765, parsed.port)
        assertTrue(p.secret.contentEquals(parsed.secret))
        assertEquals(p.expiresAtMillis / 1000, parsed.expiresAtMillis / 1000)
    }

    @Test fun pairingExpires() {
        val p = PairingInfo.create("10.0.0.2", 8765, nowMillis = 0, ttlMillis = 1000)
        assertFalse(p.isExpired(1000))
        assertTrue(p.isExpired(1001))
    }

    @Test fun pairingUriRejectsGarbage() {
        assertNull(PairingInfo.parse("http://example.com"))
        assertNull(PairingInfo.parse("hrms://pair?v=2&h=a&p=1&s=x&k=AAAAAAAAAAAAAAAAAAAAAA&e=1"))   // versión
        assertNull(PairingInfo.parse("hrms://pair?v=1&h=a&p=99999&s=x&k=AAAAAAAAAAAAAAAAAAAAAA&e=1")) // puerto
        assertNull(PairingInfo.parse("hrms://pair?v=1&h=a&p=80&s=x&k=AA&e=1"))                          // secreto corto
        assertNull(PairingInfo.parse("hrms://pair?v=1"))
    }

    @Test fun uriNeverContainsSecretInProofs() {
        val secret = Crypto.randomBytes(16)
        val proof = Handshake.deviceProof(secret, "sid", "ns", "nd", "dev1", Handshake.AUTH_PAIRING)
        assertFalse(proof.contains(Crypto.hex(secret)))
        assertEquals(64, proof.length)
    }

    @Test fun proofsDependOnEveryInput() {
        val k = Crypto.randomBytes(16)
        val base = Handshake.deviceProof(k, "sid", "ns", "nd", "dev1", "pairing")
        assertEquals(base, Handshake.deviceProof(k, "sid", "ns", "nd", "dev1", "pairing"))
        assertTrue(base != Handshake.deviceProof(k, "sid", "ns2", "nd", "dev1", "pairing"))
        assertTrue(base != Handshake.deviceProof(k, "sid", "ns", "nd2", "dev1", "pairing"))
        assertTrue(base != Handshake.deviceProof(k, "sid", "ns", "nd", "dev2", "pairing"))
        assertTrue(base != Handshake.deviceProof(k, "sid", "ns", "nd", "dev1", "token"))
        assertTrue(base != Handshake.deviceProof(Crypto.randomBytes(16), "sid", "ns", "nd", "dev1", "pairing"))
        assertTrue(base != Handshake.serverProof(k, "sid", "ns", "nd", "dev1"))   // no se puede reusar la prueba del dispositivo
    }

    @Test fun deviceTokenIsPerDeviceAndDeterministic() {
        val s = Crypto.randomBytes(16)
        assertTrue(Handshake.deviceToken(s, "a").contentEquals(Handshake.deviceToken(s, "a")))
        assertFalse(Handshake.deviceToken(s, "a").contentEquals(Handshake.deviceToken(s, "b")))
    }

    private fun codecs(): Pair<SessionCodec, SessionCodec> {
        val key = Handshake.sessionKey(Crypto.randomBytes(16), "sid", "ns", "nd")
        return SessionCodec.forServer(key) to SessionCodec.forDevice(key)
    }

    @Test fun sessionMessagesRoundTrip() {
        val (server, device) = codecs()
        val offer = Offer("v=0...")
        assertEquals(offer, server.open(device.seal(offer)))
        assertEquals(OnAir(true), device.open(server.seal(OnAir(true))))
        val tel = Telemetry(t = 5, width = 1280, height = 720, fps = 29.7, hardwareEncoder = true)
        assertEquals(tel, server.open(device.seal(tel)))
    }

    @Test fun tamperedMessageIsRejected() {
        val (server, device) = codecs()
        val sealed = device.seal(Offer("v=0 original"))
        assertNull(server.open(sealed.replace("original", "alterado")))
    }

    @Test fun replayAndReorderAreRejected() {
        val (server, device) = codecs()
        val m1 = device.seal(Bye("1"))
        val m2 = device.seal(Bye("2"))
        assertNotNull(server.open(m2))
        assertNull(server.open(m1))      // seq anterior
        assertNull(server.open(m2))      // repetido
    }

    @Test fun wrongDirectionAndWrongKeyAreRejected() {
        val (server, device) = codecs()
        // Un mensaje del dispositivo no es válido como si viniera del servidor.
        assertNull(device.open(device.seal(Bye())))
        val other = SessionCodec.forServer(Handshake.sessionKey(Crypto.randomBytes(16), "sid", "ns", "nd"))
        assertNull(other.open(device.seal(Bye())))
    }

    @Test fun unknownFieldsAreIgnoredAndGarbageIsNull() {
        assertEquals(OnAir(false), parseLinkMessage("""{"type":"onair","active":false,"futuro":1}"""))
        assertNull(parseLinkMessage("no es json"))
        assertNull(parseLinkMessage("""{"type":"desconocido"}"""))
    }

    @Test fun reconnectBackoffGrowsAndCaps() {
        assertEquals(500, Reconnect.delayMillis(0))
        assertTrue(Reconnect.delayMillis(3) > Reconnect.delayMillis(1))
        assertEquals(8_000, Reconnect.delayMillis(99))
    }
}
