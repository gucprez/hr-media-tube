package com.hrmediasports.studio.domain

import java.util.Locale

/**
 * Estado del partido de básquetbol. Capa 2 (reglas deportivas): Kotlin puro,
 * sin dependencias de Android ni de la interfaz, para poder portarlo o probarlo aislado.
 */
data class MatchState(
    val homePoints: Int,
    val awayPoints: Int,
    val clockSeconds: Int,
    val period: Int,
    val possessionSeconds: Int,
) {
    init {
        require(homePoints in 0..MAX_POINTS) { "homePoints fuera de rango: $homePoints" }
        require(awayPoints in 0..MAX_POINTS) { "awayPoints fuera de rango: $awayPoints" }
        require(clockSeconds in 0..MAX_CLOCK_SECONDS) { "clockSeconds fuera de rango: $clockSeconds" }
        require(period in 1..MAX_PERIOD) { "period fuera de rango: $period" }
        require(possessionSeconds in 0..MAX_POSSESSION_SECONDS) {
            "possessionSeconds fuera de rango: $possessionSeconds"
        }
    }

    companion object {
        const val MAX_POINTS = 199
        const val MAX_CLOCK_SECONDS = 99 * 60 + 59
        const val MAX_POSSESSION_SECONDS = 24
        const val REGULATION_PERIODS = 4
        const val MAX_PERIOD = 8
    }
}

private val CLOCK_REGEX = Regex("""^\s*([0-9]{1,2}):([0-9]{2})\s*$""")

/** Formatea segundos como `mm:ss`. */
fun formatClock(totalSeconds: Int): String {
    val s = totalSeconds.coerceAtLeast(0)
    return String.format(Locale.ROOT, "%02d:%02d", s / 60, s % 60)
}

/** Interpreta `mm:ss` (minutos 0–99, segundos 0–59). Devuelve null si no es válido. */
fun parseClock(text: String): Int? {
    val match = CLOCK_REGEX.matchEntire(text) ?: return null
    val minutes = match.groupValues[1].toInt()
    val seconds = match.groupValues[2].toInt()
    return if (seconds in 0..59) minutes * 60 + seconds else null
}

/** Etiqueta del periodo: "3er CUARTO", "PRÓRROGA 1". */
fun periodLabel(period: Int): String = when {
    period == 1 -> "1er CUARTO"
    period == 3 -> "3er CUARTO"
    period in 2..MatchState.REGULATION_PERIODS -> "${period}º CUARTO"
    else -> "PRÓRROGA ${period - MatchState.REGULATION_PERIODS}"
}
