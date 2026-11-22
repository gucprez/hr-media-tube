package com.hrmediasports.studio.board

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min

/** Imagen en escala de grises (luminancia 0..255), fila por fila. */
class Gray(val width: Int, val height: Int, val px: IntArray) {
    operator fun get(x: Int, y: Int): Int = px[y * width + x]

    /** Recorta y gira la región para que quede "derecha" según la rotación de la cámara. */
    fun crop(left: Int, top: Int, right: Int, bottom: Int): Gray {
        val l = left.coerceIn(0, width - 1); val t = top.coerceIn(0, height - 1)
        val r = right.coerceIn(l + 1, width); val b = bottom.coerceIn(t + 1, height)
        val w = r - l; val h = b - t
        val out = IntArray(w * h)
        for (y in 0 until h) System.arraycopy(px, (t + y) * width + l, out, y * w, w)
        return Gray(w, h, out)
    }

    fun rotate(degrees: Int): Gray = when (((degrees % 360) + 360) % 360) {
        90 -> Gray(height, width, IntArray(width * height).also { o -> for (y in 0 until height) for (x in 0 until width) o[x * height + (height - 1 - y)] = px[y * width + x] })
        180 -> Gray(width, height, IntArray(width * height).also { o -> for (i in px.indices) o[px.size - 1 - i] = px[i] })
        270 -> Gray(height, width, IntArray(width * height).also { o -> for (y in 0 until height) for (x in 0 until width) o[(width - 1 - x) * height + y] = px[y * width + x] })
        else -> this
    }

    /** Reduce a un ancho máximo para que el análisis sea barato (menos calor). */
    fun downscale(maxWidth: Int): Gray {
        if (width <= maxWidth) return this
        val f = width.toFloat() / maxWidth
        val w = maxWidth; val h = max(1, (height / f).toInt())
        val o = IntArray(w * h)
        for (y in 0 until h) for (x in 0 until w) o[y * w + x] = this[min(width - 1, (x * f).toInt()), min(height - 1, (y * f).toInt())]
        return Gray(w, h, o)
    }
}

/** Resultado de leer un número: dígitos y confianza 0..1. */
data class DigitsReading(val digits: String, val confidence: Float)

/**
 * Lector de números de 7 segmentos (marcadores LED/LCD de gimnasio).
 *  1. Umbral de Otsu; los segmentos encendidos son la parte minoritaria (claros sobre fondo oscuro
 *     o, en LCD, oscuros sobre fondo claro).
 *  2. Se separan los dígitos por columnas vacías; se descartan manchas pequeñas (dos puntos, ruido).
 *  3. En cada dígito se mide cada uno de los 7 segmentos y se busca el patrón.
 * Si un dígito no coincide con ningún patrón, la lectura es null: nunca se inventa un número.
 */
object SevenSegment {
    //  aaa
    // f   b
    //  ggg
    // e   c
    //  ddd
    private val PATTERNS = mapOf(
        0b1111110 to '0', 0b0110000 to '1', 0b1101101 to '2', 0b1111001 to '3', 0b0110011 to '4',
        0b1011011 to '5', 0b1011111 to '6', 0b1110000 to '7', 0b1111111 to '8', 0b1111011 to '9',
        0b1110010 to '7', // 7 con segmento f encendido (algunos marcadores)
        0b0011111 to '6', // 6 sin segmento a
        0b1110011 to '9', // 9 sin segmento d
    )

    fun otsu(g: Gray): Int {
        val hist = IntArray(256); g.px.forEach { hist[it.coerceIn(0, 255)]++ }
        val total = g.px.size
        var sum = 0.0; for (i in 0..255) sum += i * hist[i].toDouble()
        var sumB = 0.0; var wB = 0; var best = 0.0; var thr = 127
        for (t in 0..255) {
            wB += hist[t]; if (wB == 0) continue
            val wF = total - wB; if (wF == 0) break
            sumB += t * hist[t].toDouble()
            val mB = sumB / wB; val mF = (sum - sumB) / wF
            val between = wB.toDouble() * wF * (mB - mF) * (mB - mF)
            if (between > best) { best = between; thr = t }
        }
        return thr
    }

    /** Mapa binario: true = segmento encendido. Devuelve null si no hay contraste suficiente. */
    fun binarize(g: Gray): BooleanArray? {
        val thr = otsu(g)
        var minV = 255; var maxV = 0
        g.px.forEach { minV = min(minV, it); maxV = max(maxV, it) }
        if (maxV - minV < 40) return null // imagen plana: no hay números visibles
        val bright = BooleanArray(g.px.size) { g.px[it] > thr }
        val brightCount = bright.count { it }
        return if (brightCount <= bright.size / 2) bright else BooleanArray(bright.size) { !bright[it] }
    }

    fun read(g: Gray): DigitsReading? {
        val on = binarize(g) ?: return null
        val w = g.width; val h = g.height
        // Columnas con algún píxel encendido.
        val colCount = IntArray(w) { x -> (0 until h).count { y -> on[y * w + x] } }
        val minCol = max(1, h / 25)
        val spans = mutableListOf<IntRange>()
        var start = -1
        for (x in 0..w) {
            val filled = x < w && colCount[x] >= minCol
            if (filled && start < 0) start = x
            if (!filled && start >= 0) { spans += start until x; start = -1 }
        }
        // Unir trozos de un mismo dígito separados por un hueco mínimo (segmentos con cortes).
        val merged = mutableListOf<IntRange>()
        for (s in spans) {
            val last = merged.lastOrNull()
            if (last != null && s.first - last.last <= max(1, h / 30)) merged[merged.size - 1] = last.first..s.last else merged += s
        }
        val digits = StringBuilder()
        var conf = 1f
        for (span in merged) {
            // Caja vertical ajustada del trozo.
            var top = h; var bottom = -1
            for (y in 0 until h) for (x in span) if (on[y * w + x]) { top = min(top, y); bottom = max(bottom, y) }
            if (bottom < 0) continue
            val bh = bottom - top + 1; val bw = span.last - span.first + 1
            if (bh < h * 0.45f) continue                 // dos puntos, punto decimal o ruido
            if (bw < bh * 0.28f) { digits.append('1'); continue } // un "1" es solo la columna derecha
            val (bits, c) = segments(on, w, span.first, top, bw, bh)
            val ch = PATTERNS[bits] ?: return null
            digits.append(ch); conf = min(conf, c)
        }
        if (digits.isEmpty()) return null
        return DigitsReading(digits.toString(), conf)
    }

    /** Mide los 7 segmentos en la caja (x0,y0,bw,bh). Devuelve los bits (a..g) y la confianza. */
    private fun segments(on: BooleanArray, w: Int, x0: Int, y0: Int, bw: Int, bh: Int): Pair<Int, Float> {
        fun frac(fx0: Float, fy0: Float, fx1: Float, fy1: Float): Float {
            val xa = x0 + (fx0 * bw).toInt(); val xb = max(xa + 1, x0 + (fx1 * bw).toInt())
            val ya = y0 + (fy0 * bh).toInt(); val yb = max(ya + 1, y0 + (fy1 * bh).toInt())
            var n = 0; var tot = 0
            for (y in ya until yb) for (x in xa until xb) { tot++; if (on[y * w + x]) n++ }
            return if (tot == 0) 0f else n.toFloat() / tot
        }
        val f = floatArrayOf(
            frac(0.25f, 0.00f, 0.75f, 0.12f), // a
            frac(0.75f, 0.12f, 1.00f, 0.42f), // b
            frac(0.75f, 0.58f, 1.00f, 0.88f), // c
            frac(0.25f, 0.88f, 0.75f, 1.00f), // d
            frac(0.00f, 0.58f, 0.25f, 0.88f), // e
            frac(0.00f, 0.12f, 0.25f, 0.42f), // f
            frac(0.25f, 0.44f, 0.75f, 0.56f), // g
        )
        var bits = 0; var conf = 1f
        for (i in f.indices) {
            val lit = f[i] >= 0.35f
            if (lit) bits = bits or (1 shl (6 - i))
            conf = min(conf, (abs(f[i] - 0.35f) / 0.35f).coerceAtMost(1f))
        }
        return bits to conf
    }
}

/** Interpreta lo leído en cada zona. */
object BoardParse {
    /** 4 dígitos → mm:ss; 3 → m:ss; 2 → ss (último minuto en muchos marcadores). */
    fun clockSeconds(d: String): Int? = when (d.length) {
        4 -> { val m = d.substring(0, 2).toInt(); val s = d.substring(2).toInt(); if (s < 60) m * 60 + s else null }
        3 -> { val m = d.substring(0, 1).toInt(); val s = d.substring(1).toInt(); if (s < 60) m * 60 + s else null }
        2, 1 -> d.toInt().takeIf { it < 60 }
        else -> null
    }

    fun points(d: String): Int? = d.takeIf { it.length in 1..3 }?.toInt()?.takeIf { it <= 199 }
    fun period(d: String): Int? = d.takeIf { it.length == 1 }?.toInt()?.takeIf { it in 1..8 }
    fun possession(d: String): Int? = d.takeIf { it.length in 1..2 }?.toInt()?.takeIf { it <= 24 }
}

/**
 * Filtro de estabilidad: un valor nuevo solo se acepta si se repite en [needed] lecturas seguidas.
 * Evita mandar al aire un número leído a medias mientras el marcador cambia o alguien pasa por delante.
 */
class Stabilizer<T>(private val needed: Int = 3) {
    private var candidate: T? = null
    private var count = 0
    var accepted: T? = null; private set

    fun offer(value: T?): T? {
        if (value == null) { count = 0; candidate = null; return accepted }
        if (value == candidate) count++ else { candidate = value; count = 1 }
        if (count >= needed) accepted = value
        return accepted
    }

    fun reset() { candidate = null; count = 0; accepted = null }
}
