package com.hrmediasports.studio

import com.hrmediasports.studio.board.BoardParse
import com.hrmediasports.studio.board.Gray
import com.hrmediasports.studio.board.SevenSegment
import com.hrmediasports.studio.board.Stabilizer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.random.Random

/** Dibuja números de 7 segmentos sintéticos (como un marcador LED) y comprueba la lectura. */
class SevenSegmentTest {
    private val segs = mapOf(
        '0' to "abcdef", '1' to "bc", '2' to "abdeg", '3' to "abcdg", '4' to "bcfg",
        '5' to "acdfg", '6' to "acdefg", '7' to "abc", '8' to "abcdefg", '9' to "abcdfg",
    )

    /** Texto con dígitos y ':' sobre fondo; dw×dh por dígito, grosor t, inclinación opcional. */
    private fun draw(text: String, dw: Int = 40, dh: Int = 70, t: Int = 8, fg: Int = 230, bg: Int = 25,
                     slant: Float = 0f, noise: Int = 0, seed: Int = 1): Gray {
        val gap = 14; val margin = 12
        val w = margin * 2 + text.length * (dw + gap); val h = dh + margin * 2
        val px = IntArray(w * h) { bg }
        fun rect(x0: Int, y0: Int, x1: Int, y1: Int) {
            for (y in y0 until y1) for (x in x0 until x1) {
                val sx = x + ((h - y) * slant).toInt()
                if (sx in 0 until w && y in 0 until h) px[y * w + sx] = fg
            }
        }
        var x = margin
        for (c in text) {
            val y = margin
            if (c == ':') { rect(x + dw / 2 - 3, y + dh / 3, x + dw / 2 + 3, y + dh / 3 + 6); rect(x + dw / 2 - 3, y + 2 * dh / 3, x + dw / 2 + 3, y + 2 * dh / 3 + 6); x += dw / 2 + gap; continue }
            val s = segs.getValue(c)
            val mid = y + dh / 2
            if ('a' in s) rect(x + t, y, x + dw - t, y + t)
            if ('b' in s) rect(x + dw - t, y + t, x + dw, mid)
            if ('c' in s) rect(x + dw - t, mid, x + dw, y + dh - t)
            if ('d' in s) rect(x + t, y + dh - t, x + dw - t, y + dh)
            if ('e' in s) rect(x, mid, x + t, y + dh - t)
            if ('f' in s) rect(x, y + t, x + t, mid)
            if ('g' in s) rect(x + t, mid - t / 2, x + dw - t, mid + t / 2)
            x += dw + gap
        }
        if (noise > 0) { val r = Random(seed); for (i in px.indices) px[i] = (px[i] + r.nextInt(-noise, noise + 1)).coerceIn(0, 255) }
        return Gray(w, h, px)
    }

    @Test fun readsEveryDigit() {
        for (n in listOf("0123456789", "88", "7", "41", "100")) assertEquals(n, SevenSegment.read(draw(n))?.digits)
    }

    @Test fun readsClockIgnoringColon() {
        val r = SevenSegment.read(draw("07:32"))!!
        assertEquals("0732", r.digits)
        assertEquals(7 * 60 + 32, BoardParse.clockSeconds(r.digits))
    }

    @Test fun toleratesNoiseSlantAndLcdPolarity() {
        assertEquals("56", SevenSegment.read(draw("56", noise = 30))?.digits)
        assertEquals("49", SevenSegment.read(draw("49", slant = 0.08f))?.digits)
        assertEquals("23", SevenSegment.read(draw("23", fg = 20, bg = 200))?.digits)   // LCD: oscuro sobre claro
        assertEquals("90", SevenSegment.read(draw("90", fg = 150, bg = 70))?.digits)   // poco contraste
    }

    @Test fun neverInventsANumber() {
        assertNull(SevenSegment.read(Gray(50, 50, IntArray(2500) { 120 })))           // imagen plana
        // Dígito tapado a medias (segmentos sueltos que no forman un número).
        val g = draw("8")
        for (y in 0 until g.height) for (x in 0 until g.width / 2) g.px[y * g.width + x] = 25
        val r = SevenSegment.read(g)
        assertTrue(r == null || r.digits != "8")
    }

    @Test fun rotationAndCropKeepTheReading() {
        val g = draw("12")
        assertEquals("12", SevenSegment.read(g.rotate(90).rotate(270))?.digits)
        val big = draw("3456")
        assertEquals("34", SevenSegment.read(big.crop(0, 0, big.width / 2, big.height))?.digits)
    }

    @Test fun parsingRules() {
        assertEquals(65, BoardParse.clockSeconds("105"))
        assertEquals(42, BoardParse.clockSeconds("42"))
        assertNull(BoardParse.clockSeconds("0775"))                                    // 75 s no existe
        assertNull(BoardParse.points("1234"))
        assertNull(BoardParse.period("9"))
        assertEquals(24, BoardParse.possession("24"))
    }

    @Test fun stabilizerNeedsRepeatedReadings() {
        val s = Stabilizer<Int>(3)
        assertNull(s.offer(10)); assertNull(s.offer(10)); assertEquals(10, s.offer(10))
        assertEquals(10, s.offer(13))                    // un valor nuevo aislado no cambia lo aceptado
        assertEquals(10, s.offer(null))                  // lectura fallida: se mantiene lo último
        s.offer(12); s.offer(12)
        assertEquals(12, s.offer(12))
    }
}
