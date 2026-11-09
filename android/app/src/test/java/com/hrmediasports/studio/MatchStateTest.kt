package com.hrmediasports.studio

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.domain.parseClock
import com.hrmediasports.studio.domain.periodLabel
import com.hrmediasports.studio.protocol.formatAge
import com.hrmediasports.studio.protocol.isStale
import com.hrmediasports.studio.protocol.readingAgeMillis
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class MatchStateTest {
    @Test fun formatsClock() {
        assertEquals("03:24", formatClock(204))
        assertEquals("00:00", formatClock(-5))
    }

    @Test fun parsesValidClock() {
        assertEquals(204, parseClock("03:24"))
        assertEquals(204, parseClock(" 3:24 "))
        assertEquals(99 * 60 + 59, parseClock("99:59"))
    }

    @Test fun rejectsInvalidClock() {
        assertNull(parseClock("03:60"))
        assertNull(parseClock("324"))
        assertNull(parseClock(""))
        assertNull(parseClock("ab:cd"))
    }

    @Test fun periodLabels() {
        assertEquals("1er CUARTO", periodLabel(1))
        assertEquals("2º CUARTO", periodLabel(2))
        assertEquals("3er CUARTO", periodLabel(3))
        assertEquals("4º CUARTO", periodLabel(4))
        assertEquals("PRÓRROGA 1", periodLabel(5))
    }

    @Test fun rejectsOutOfRangeState() {
        assertThrows(IllegalArgumentException::class.java) { MatchState(-1, 0, 0, 1, 0) }
        assertThrows(IllegalArgumentException::class.java) { MatchState(0, 0, 0, 0, 0) }
        assertThrows(IllegalArgumentException::class.java) { MatchState(0, 0, 0, 1, 25) }
    }

    @Test fun ageFormatting() {
        assertEquals("hace 0,2 s", formatAge(200))
        assertEquals("hace 9,9 s", formatAge(9_990))
        assertEquals("hace 12 s", formatAge(12_400))
        assertEquals("hace 3 min", formatAge(190_000))
    }

    @Test fun ageNeverNegativeAndStaleThreshold() {
        assertEquals(0L, readingAgeMillis(1_000, 2_000))
        assertFalse(isStale(3_000))
        assertTrue(isStale(3_001))
    }
}
