package com.hrmediasports.studio.live

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import android.graphics.Shader
import android.graphics.Typeface

/** Qué gráfico de pantalla completa sale al aire en lugar de la cámara. */
enum class Slate { None, Break, Result }

/**
 * Gráficos de pantalla completa (1280×720) dibujados por la app: DESCANSO y RESULTADO.
 * Se ponen encima de la cámara, que sigue corriendo debajo para volver al instante.
 */
object SlateRenderer {
    const val W = 1280
    const val H = 720
    private val orange = Color.rgb(242, 106, 13)

    fun render(kind: Slate, s: LiveScore, logo: Bitmap?, title: String? = null): Bitmap? {
        if (kind == Slate.None) return null
        val bmp = Bitmap.createBitmap(W, H, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val bg = Paint().apply { shader = LinearGradient(0f, 0f, 0f, H.toFloat(), Color.rgb(27, 38, 56), Color.rgb(11, 18, 32), Shader.TileMode.CLAMP) }
        c.drawRect(0f, 0f, W.toFloat(), H.toFloat(), bg)
        // Franjas diagonales de marca.
        val stripe = Paint(Paint.ANTI_ALIAS_FLAG)
        for (i in 0..5) {
            stripe.color = orange; stripe.alpha = if (i % 2 == 0) 200 else 80
            val x = W * (0.62f + i * 0.08f)
            c.drawPath(Path().apply { moveTo(x, 0f); lineTo(x + 70f, 0f); lineTo(x + 70f - 360f, H.toFloat()); lineTo(x - 360f, H.toFloat()); close() }, stripe)
        }
        val big = text(120f, Color.WHITE)
        val mid = text(46f, Color.WHITE)
        val small = text(32f, Color.rgb(163, 177, 198))
        logo?.let { val l = scaleLogo(it, 150, 360); c.drawBitmap(l, (W - l.width) / 2f, 50f, null) }
        when (kind) {
            Slate.Break -> {
                c.drawText(title ?: "DESCANSO", W / 2f, 330f, big)
                c.drawText("Volvemos en breve", W / 2f, 400f, small)
                scoreRow(c, s, 520f, mid)
            }
            Slate.Result -> {
                c.drawText(title ?: "RESULTADO", W / 2f, 260f, mid.apply { color = Color.rgb(163, 177, 198) })
                scoreRow(c, s, 470f, text(150f, Color.WHITE), names = true)
                c.drawText(if (s.period <= 4) "${s.period}º cuarto" else "Prórroga ${s.period - 4}", W / 2f, 580f, small)
            }
            Slate.None -> {}
        }
        return bmp
    }

    private fun scoreRow(c: Canvas, s: LiveScore, y: Float, p: Paint, names: Boolean = true) {
        val name = text(44f, Color.WHITE)
        val chip = Paint(Paint.ANTI_ALIAS_FLAG)
        chip.color = s.home.color.toInt(); c.drawRoundRect(RectF(W / 2f - 520f, y - 60f, W / 2f - 500f, y + 10f), 6f, 6f, chip)
        chip.color = s.away.color.toInt(); c.drawRoundRect(RectF(W / 2f + 500f, y - 60f, W / 2f + 520f, y + 10f), 6f, 6f, chip)
        if (names) { c.drawText(s.home.name, W / 2f - 390f, y - 10f, name); c.drawText(s.away.name, W / 2f + 390f, y - 10f, name) }
        c.drawText("${s.home.points}", W / 2f - 160f, y, p)
        c.drawText("–", W / 2f, y, p)
        c.drawText("${s.away.points}", W / 2f + 160f, y, p)
    }

    private fun text(size: Float, color: Int) = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        textSize = size; this.color = color; typeface = Typeface.DEFAULT_BOLD; textAlign = Paint.Align.CENTER
    }
}
