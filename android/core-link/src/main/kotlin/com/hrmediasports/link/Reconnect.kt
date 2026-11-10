package com.hrmediasports.link

/** Espera creciente (con tope) entre reintentos de reconexión de una cámara. */
object Reconnect {
    private val steps = longArrayOf(500, 1_000, 2_000, 4_000, 8_000)

    fun delayMillis(attempt: Int): Long = steps[attempt.coerceIn(0, steps.lastIndex)]
}
