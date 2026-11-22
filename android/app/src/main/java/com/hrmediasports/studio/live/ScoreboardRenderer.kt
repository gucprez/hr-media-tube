package com.hrmediasports.studio.live

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.graphics.Typeface

/**
 * Dibuja el marcador que se incrusta en el video (no en la pantalla del operador).
 * Medidas pensadas para un video de 1280 px de ancho; se escala igual en 1080p.
 * Usa dos bitmaps alternados para no modificar uno mientras OpenGL lo está subiendo.
 */
class ScoreboardRenderer {
    companion object {
        const val WIDTH = 640
        const val HEIGHT = 72
        /** Ancho de referencia del video sobre el que se definen las medidas. */
        const val REFERENCE_WIDTH = 1280f
        const val REFERENCE_HEIGHT = 720f
    }

    private val buffers = arrayOf(
        Bitmap.createBitmap(WIDTH, HEIGHT, Bitmap.Config.ARGB_8888),
        Bitmap.createBitmap(WIDTH, HEIGHT, Bitmap.Config.ARGB_8888),
    )
    private var index = 0
    private var lastKey: String? = null

    private val bg = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.argb(235, 12, 15, 20) }
    private val ptsBg = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.argb(255, 28, 33, 42) }
    private val clockBg = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.argb(255, 200, 30, 30) }
    private val strip = Paint(Paint.ANTI_ALIAS_FLAG)
    private val name = text(30f, Typeface.DEFAULT_BOLD, Color.WHITE)
    private val pts = text(40f, Typeface.DEFAULT_BOLD, Color.WHITE)
    private val period = text(22f, Typeface.DEFAULT_BOLD, Color.argb(255, 210, 215, 225))
    private val clock = text(34f, Typeface.create(Typeface.MONOSPACE, Typeface.BOLD), Color.WHITE)
    private val dotOn = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.argb(255, 255, 196, 0) }
    private val dotOff = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.argb(90, 255, 255, 255) }

    private fun text(size: Float, tf: Typeface, c: Int) = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        textSize = size; typeface = tf; color = c; textAlign = Paint.Align.CENTER
    }

    /** Devuelve un bitmap nuevo solo si cambió algo visible; null si no hay que actualizar. */
    fun render(s: LiveScore, now: Long): Bitmap? {
        val clockText = s.clockText(now)
        val key = "${s.home}|${s.away}|${s.period}|$clockText"
        if (key == lastKey) return null
        lastKey = key
        index = 1 - index
        val bmp = buffers[index]
        bmp.eraseColor(Color.TRANSPARENT)
        draw(Canvas(bmp), s, clockText)
        return bmp
    }

    fun invalidate() { lastKey = null }

    private fun draw(c: Canvas, s: LiveScore, clockText: String) {
        val h = HEIGHT.toFloat()
        c.drawRoundRect(RectF(0f, 0f, WIDTH.toFloat(), h), 12f, 12f, bg)
        var x = 0f
        x = team(c, s.home, x, h)
        x = team(c, s.away, x + 4f, h)
        // Periodo
        val perW = 64f
        c.drawText(s.periodText(), x + perW / 2, baseline(period, h), period)
        x += perW
        // Reloj
        val r = RectF(x, 6f, WIDTH - 6f, h - 6f)
        c.drawRoundRect(r, 8f, 8f, clockBg)
        c.drawText(clockText, r.centerX(), baseline(clock, h), clock)
    }

    /** Franja de color, nombre, faltas (puntos amarillos) y puntos. Devuelve la x final. */
    private fun team(c: Canvas, t: Team, x0: Float, h: Float): Float {
        strip.color = t.color.toInt()
        c.drawRect(x0 + 6f, 8f, x0 + 14f, h - 8f, strip)
        val nameW = 104f
        val cx = x0 + 14f + nameW / 2
        c.drawText(t.name, cx, baseline(name, h) - 7f, name)
        val dotR = 4f; val gap = 13f
        val startX = cx - gap * 2
        for (i in 0 until 5) c.drawCircle(startX + i * gap, h - 13f, dotR, if (i < t.fouls) dotOn else dotOff)
        val px = x0 + 14f + nameW
        val ptsW = 74f
        c.drawRoundRect(RectF(px, 6f, px + ptsW, h - 6f), 8f, 8f, ptsBg)
        c.drawText(t.points.toString(), px + ptsW / 2, baseline(pts, h), pts)
        return px + ptsW
    }

    private fun baseline(p: Paint, h: Float): Float = h / 2 - (p.descent() + p.ascent()) / 2
}

/** Escala un logo a un alto máximo en píxeles de video, conservando proporción. */
fun scaleLogo(src: Bitmap, maxHeight: Int = 84, maxWidth: Int = 220): Bitmap {
    val ratio = minOf(maxHeight.toFloat() / src.height, maxWidth.toFloat() / src.width, 1f)
    val w = (src.width * ratio).toInt().coerceAtLeast(1)
    val h = (src.height * ratio).toInt().coerceAtLeast(1)
    return if (w == src.width && h == src.height) src else Bitmap.createScaledBitmap(src, w, h, true)
}
