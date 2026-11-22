package com.hrmediasports.studio.live

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock

/** Colores disponibles para los equipos (ARGB). */
val TEAM_COLORS: List<Long> = listOf(
    0xFFE53935, 0xFF1E88E5, 0xFF43A047, 0xFFFDD835, 0xFFFB8C00, 0xFF8E24AA, 0xFFFFFFFF, 0xFF212121,
)

data class Team(val name: String, val color: Long, val points: Int = 0, val fouls: Int = 0)

/**
 * Marcador que se dibuja dentro de la transmisión. El reloj corre a partir de una marca de tiempo
 * monotónica para no acumular error aunque la pantalla se refresque con retraso.
 */
data class LiveScore(
    val home: Team = Team("LOC", TEAM_COLORS[0]),
    val away: Team = Team("VIS", TEAM_COLORS[1]),
    val period: Int = 1,
    val periodMinutes: Int = 10,
    /** Segundos restantes cuando el reloj se detuvo por última vez (o al arrancar). */
    val clockBaseSeconds: Int = 10 * 60,
    /** Marca monotónica (ms) en la que arrancó el reloj; null si está detenido. */
    val runningSince: Long? = null,
    val visible: Boolean = true,
) {
    val running: Boolean get() = runningSince != null

    fun remainingSeconds(now: Long): Int {
        val since = runningSince ?: return clockBaseSeconds
        val elapsed = ((now - since).coerceAtLeast(0) / 1000).toInt()
        return (clockBaseSeconds - elapsed).coerceAtLeast(0)
    }

    fun clockText(now: Long): String = formatClock(remainingSeconds(now))

    fun periodText(): String = if (period <= MatchState.REGULATION_PERIODS) "${period}C" else "OT${period - MatchState.REGULATION_PERIODS}"
}

object ScoreEditor {
    private fun clampPts(p: Int) = p.coerceIn(0, MatchState.MAX_POINTS)

    fun addPoints(s: LiveScore, home: Boolean, delta: Int): LiveScore =
        if (home) s.copy(home = s.home.copy(points = clampPts(s.home.points + delta)))
        else s.copy(away = s.away.copy(points = clampPts(s.away.points + delta)))

    fun addFoul(s: LiveScore, home: Boolean, delta: Int): LiveScore =
        if (home) s.copy(home = s.home.copy(fouls = (s.home.fouls + delta).coerceIn(0, 9)))
        else s.copy(away = s.away.copy(fouls = (s.away.fouls + delta).coerceIn(0, 9)))

    fun startClock(s: LiveScore, now: Long): LiveScore =
        if (s.running || s.clockBaseSeconds == 0) s else s.copy(runningSince = now)

    fun stopClock(s: LiveScore, now: Long): LiveScore =
        if (!s.running) s else s.copy(clockBaseSeconds = s.remainingSeconds(now), runningSince = null)

    /** Detiene el reloj solo cuando llega a cero. */
    fun tick(s: LiveScore, now: Long): LiveScore =
        if (s.running && s.remainingSeconds(now) == 0) s.copy(clockBaseSeconds = 0, runningSince = null) else s

    fun adjustClock(s: LiveScore, now: Long, deltaSeconds: Int): LiveScore {
        val max = MatchState.MAX_CLOCK_SECONDS
        return if (s.running) {
            // Mantiene el reloj corriendo: se corrige la base y se reinicia la marca.
            s.copy(clockBaseSeconds = (s.remainingSeconds(now) + deltaSeconds).coerceIn(0, max), runningSince = now)
        } else s.copy(clockBaseSeconds = (s.clockBaseSeconds + deltaSeconds).coerceIn(0, max))
    }

    fun setClock(s: LiveScore, now: Long, seconds: Int): LiveScore =
        s.copy(clockBaseSeconds = seconds.coerceIn(0, MatchState.MAX_CLOCK_SECONDS), runningSince = if (s.running) now else null)

    /** Siguiente periodo: reloj detenido y reiniciado; faltas de equipo a cero; prórroga de 5 min. */
    fun nextPeriod(s: LiveScore): LiveScore {
        val p = (s.period + 1).coerceAtMost(MatchState.MAX_PERIOD)
        val minutes = if (p > MatchState.REGULATION_PERIODS) 5 else s.periodMinutes
        return s.copy(period = p, clockBaseSeconds = minutes * 60, runningSince = null,
            home = s.home.copy(fouls = 0), away = s.away.copy(fouls = 0))
    }

    fun previousPeriod(s: LiveScore): LiveScore = s.copy(period = (s.period - 1).coerceAtLeast(1))

    fun setTeams(s: LiveScore, homeName: String, homeColor: Long, awayName: String, awayColor: Long, periodMinutes: Int): LiveScore {
        val mins = periodMinutes.coerceIn(1, 20)
        val resetClock = !s.running && s.clockBaseSeconds == s.periodMinutes * 60
        return s.copy(
            home = s.home.copy(name = teamName(homeName, "LOC"), color = homeColor),
            away = s.away.copy(name = teamName(awayName, "VIS"), color = awayColor),
            periodMinutes = mins,
            clockBaseSeconds = if (resetClock) mins * 60 else s.clockBaseSeconds,
        )
    }

    fun newGame(s: LiveScore): LiveScore = LiveScore(
        home = s.home.copy(points = 0, fouls = 0), away = s.away.copy(points = 0, fouls = 0),
        periodMinutes = s.periodMinutes, clockBaseSeconds = s.periodMinutes * 60, visible = s.visible,
    )

    /** Nombre corto en mayúsculas (máx. 4) para que quepa en el marcador. */
    fun teamName(raw: String, fallback: String): String =
        raw.trim().uppercase().filter { !it.isISOControl() }.take(4).ifBlank { fallback }
}
