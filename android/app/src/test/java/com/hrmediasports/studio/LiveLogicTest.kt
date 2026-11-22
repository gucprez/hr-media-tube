package com.hrmediasports.studio

import com.hrmediasports.studio.live.LiveDestination
import com.hrmediasports.studio.live.LiveScore
import com.hrmediasports.studio.live.Platform
import com.hrmediasports.studio.live.Quality
import com.hrmediasports.studio.live.ScoreEditor
import com.hrmediasports.studio.live.Thermal
import com.hrmediasports.studio.live.bitrateCap
import com.hrmediasports.studio.live.friendlyReason
import com.hrmediasports.studio.live.maskKey
import com.hrmediasports.studio.live.thermalAdvice
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class LiveLogicTest {
    @Test fun youtubeAndFacebookEndpointsUseSecureServers() {
        val yt = LiveDestination(Platform.YouTube, enabled = true, key = "abcd-1234-efgh")
        assertEquals("rtmps://a.rtmps.youtube.com:443/live2/abcd-1234-efgh", yt.endpoint())
        val fb = LiveDestination(Platform.Facebook, enabled = true, key = "FB-123?s_bl=1&a=xyz")
        assertEquals("rtmps://live-api-s.facebook.com:443/rtmp/FB-123?s_bl=1&a=xyz", fb.endpoint())
        assertTrue(yt.ready && fb.ready)
    }

    @Test fun missingOrBadDataIsExplained() {
        assertEquals("falta la clave de transmisión", LiveDestination(Platform.YouTube, enabled = true).problem())
        assertEquals("la dirección debe empezar con rtmp:// o rtmps://", LiveDestination(Platform.Custom, enabled = true, server = "http://x", key = "k").problem())
        assertNull(LiveDestination(Platform.YouTube, enabled = false).problem())          // apagado: no molesta
        assertFalse(LiveDestination(Platform.YouTube, enabled = false, key = "k").ready)
        // Barra final en el servidor o inicial en la clave no duplica separadores.
        assertEquals("rtmp://srv/app/k", LiveDestination(Platform.Custom, enabled = true, server = "rtmp://srv/app/", key = "/k").endpoint())
        // "Otro" admite la dirección completa con la clave incluida.
        assertEquals("rtmp://live.restream.io/live/re_123", LiveDestination(Platform.Custom, enabled = true, server = "rtmp://live.restream.io/live/re_123").endpoint())
        assertNull(LiveDestination(Platform.Custom, enabled = true, server = "rtmp://live.restream.io").endpoint())
    }

    @Test fun keyIsNeverShownComplete() {
        assertEquals("••••wxyz", maskKey("abcd-efgh-wxyz"))
        assertEquals("••••", maskKey("abc"))
        assertEquals("sin clave", maskKey("  "))
    }

    @Test fun heatLowersQualityProgressivelyInsteadOfCutting() {
        val q = Quality.Hd720
        assertEquals(3_500_000, bitrateCap(q, Thermal.None))
        assertEquals(3_500_000, bitrateCap(q, Thermal.Light))
        assertTrue(bitrateCap(q, Thermal.Moderate) < bitrateCap(q, Thermal.Light))
        assertTrue(bitrateCap(q, Thermal.Severe) < bitrateCap(q, Thermal.Moderate))
        assertTrue(bitrateCap(q, Thermal.Critical) >= 800_000)
        assertNull(thermalAdvice(Thermal.None))
        assertTrue(thermalAdvice(Thermal.Severe)!!.contains("ahorro"))
    }

    @Test fun uploadEstimateDoublesWithTwoDirectDestinations() {
        val one = Quality.Hd720.uploadNeededMbps(1)
        assertEquals(one * 2, Quality.Hd720.uploadNeededMbps(2), 0.001)
        assertTrue(one in 4.0..5.5)
    }

    @Test fun reasonsAreTranslatedForTheOperator() {
        assertTrue(friendlyReason("Error configure stream, publish permitted bad").contains("clave"))
        assertTrue(friendlyReason("java.net.SocketTimeoutException: timeout").contains("internet"))
        assertTrue(friendlyReason("java.net.UnknownHostException: Unable to resolve host").contains("internet"))
        assertTrue(friendlyReason("SSLHandshakeException").contains("segura"))
    }

    @Test fun clockRunsFromMonotonicTimeAndStopsAtZero() {
        var s = LiveScore(clockBaseSeconds = 65)
        s = ScoreEditor.startClock(s, now = 1_000)
        assertEquals("01:05", s.clockText(1_000))
        assertEquals("01:00", s.clockText(6_400))
        s = ScoreEditor.stopClock(s, now = 6_400)
        assertFalse(s.running)
        assertEquals("01:00", s.clockText(99_999))                 // detenido: no avanza
        s = ScoreEditor.startClock(s, now = 10_000)
        s = ScoreEditor.tick(s, now = 71_000)
        assertFalse(s.running); assertEquals(0, s.clockBaseSeconds)
        assertEquals(s, ScoreEditor.startClock(s, now = 72_000))    // en cero no arranca
    }

    @Test fun adjustingRunningClockKeepsItRunning() {
        var s = ScoreEditor.startClock(LiveScore(clockBaseSeconds = 100), now = 0)
        s = ScoreEditor.adjustClock(s, now = 10_000, deltaSeconds = 5)
        assertTrue(s.running)
        assertEquals(95, s.remainingSeconds(10_000))
        assertEquals(94, s.remainingSeconds(11_000))
    }

    @Test fun pointsFoulsAndPeriods() {
        var s = LiveScore()
        s = ScoreEditor.addPoints(s, home = true, delta = 3)
        s = ScoreEditor.addPoints(s, home = false, delta = 2)
        s = ScoreEditor.addPoints(s, home = false, delta = -5)      // nunca negativo
        assertEquals(3, s.home.points); assertEquals(0, s.away.points)
        repeat(12) { s = ScoreEditor.addFoul(s, home = true, delta = 1) }
        assertEquals(9, s.home.fouls)
        s = ScoreEditor.nextPeriod(s)
        assertEquals(2, s.period); assertEquals(0, s.home.fouls); assertEquals(600, s.clockBaseSeconds)
        repeat(3) { s = ScoreEditor.nextPeriod(s) }
        assertEquals("OT1", s.periodText()); assertEquals(300, s.clockBaseSeconds)   // prórroga de 5 min
        assertEquals(3, s.home.points)                                           // los puntos no se tocan
    }

    @Test fun teamNamesAreShortAndNewGameKeepsTeams() {
        var s = ScoreEditor.setTeams(LiveScore(), "  tigres  ", 0xFF000000, "", 0xFFFFFFFF, 12)
        assertEquals("TIGR", s.home.name); assertEquals("VIS", s.away.name)
        assertEquals(720, s.clockBaseSeconds)                                    // reloj sin usar: toma los nuevos minutos
        s = ScoreEditor.addPoints(s, true, 10)
        s = ScoreEditor.newGame(ScoreEditor.nextPeriod(s))
        assertEquals("TIGR", s.home.name); assertEquals(0, s.home.points); assertEquals(1, s.period); assertEquals(720, s.clockBaseSeconds)
    }
}
