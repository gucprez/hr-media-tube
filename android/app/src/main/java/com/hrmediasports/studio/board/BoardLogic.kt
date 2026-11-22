package com.hrmediasports.studio.board

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.NormRect
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.ZoneTemplate
import com.hrmediasports.studio.link.ScoreMessage

/** Lectura cruda de una zona en un cuadro. */
data class ZoneRead(val digits: String?, val confidence: Float)

/** Estado de una zona en pantalla: lo aceptado, lo que se ve ahora y si hay duda. */
data class ZoneState(val accepted: Int? = null, val raw: String? = null, val doubtful: Boolean = true, val lastOkAt: Long = 0L)

/**
 * Combina las lecturas automáticas de las zonas en un marcador que se puede enviar.
 * Reglas de seguridad (nunca mandar un número dudoso al aire):
 *  - cada zona necesita 3 lecturas iguales seguidas;
 *  - un salto de puntos mayor a 4 necesita 8 lecturas iguales (un cambio real se sostiene; un error no);
 *  - si una zona obligatoria no se lee hace más de [staleMs], no se envía nada (el Estudio lo marca desactualizado).
 */
class BoardFusion(private val usePossession: Boolean = false, private val staleMs: Long = 2_000) {
    private val stab = ZoneId.entries.associateWith { Stabilizer<Int>(3) }
    private val bigJump = mapOf(ZoneId.Home to Stabilizer<Int>(8), ZoneId.Away to Stabilizer<Int>(8))
    private val confs = mutableMapOf<ZoneId, Float>()
    val zones = ZoneId.entries.associateWith { ZoneState() }.toMutableMap()
    private var lastClock: Int? = null
    private var lastClockChangeAt = 0L

    private fun parse(id: ZoneId, d: String): Int? = when (id) {
        ZoneId.Home, ZoneId.Away -> BoardParse.points(d)
        ZoneId.Clock -> BoardParse.clockSeconds(d)
        ZoneId.Period -> BoardParse.period(d)
        ZoneId.Possession -> BoardParse.possession(d)
    }

    fun offer(reads: Map<ZoneId, ZoneRead>, now: Long) {
        for ((id, r) in reads) {
            val value = r.digits?.let { parse(id, it) }
            val prev = zones.getValue(id)
            var accepted = stab.getValue(id).offer(value)
            val big = bigJump[id]
            if (big != null) {
                val slow = big.offer(value)
                val old = prev.accepted
                // Salto grande (p. ej. 45 → 85 por un dígito mal leído): solo con lectura muy sostenida.
                if (accepted != null && old != null && kotlin.math.abs(accepted - old) > 4) accepted = if (slow == accepted) accepted else old
            }
            if (value != null) confs[id] = r.confidence
            zones[id] = ZoneState(accepted, r.digits, value == null, if (value != null) now else prev.lastOkAt)
        }
        val clock = zones.getValue(ZoneId.Clock).accepted
        if (clock != null && clock != lastClock) { lastClock = clock; lastClockChangeAt = now }
    }

    private val required get() = listOfNotNull(ZoneId.Home, ZoneId.Away, ZoneId.Clock, ZoneId.Period, if (usePossession) ZoneId.Possession else null)

    /** Marcador listo para enviar, o null si falta algo o está viejo. */
    fun current(now: Long, seq: Long): ScoreMessage? {
        if (required.any { now - zones.getValue(it).lastOkAt > staleMs || zones.getValue(it).accepted == null }) return null
        val m = runCatching {
            MatchState(
                zones.getValue(ZoneId.Home).accepted!!, zones.getValue(ZoneId.Away).accepted!!,
                zones.getValue(ZoneId.Clock).accepted!!, zones.getValue(ZoneId.Period).accepted!!,
                if (usePossession) zones.getValue(ZoneId.Possession).accepted ?: 0 else 0,
            )
        }.getOrNull() ?: return null
        val conf = required.mapNotNull { confs[it] }.minOrNull() ?: 0f
        return ScoreMessage(m, conf, auto = true, seq = seq, clockRunning = now - lastClockChangeAt < 1_500)
    }
}

/** Píxeles de una zona normalizada dentro de una imagen de w×h. */
fun NormRect.toPixels(w: Int, h: Int): IntArray =
    intArrayOf((left * w).toInt(), (top * h).toInt(), (right * w).toInt().coerceAtLeast((left * w).toInt() + 2), (bottom * h).toInt().coerceAtLeast((top * h).toInt() + 2))

/** Lee todas las zonas de un cuadro ya girado y derecho. */
fun readZones(frame: Gray, template: ZoneTemplate, usePossession: Boolean): Map<ZoneId, ZoneRead> =
    ZoneId.entries.filter { usePossession || it != ZoneId.Possession }.associateWith { id ->
        val (l, t, r, b) = template[id].toPixels(frame.width, frame.height).toList()
        val crop = frame.crop(l, t, r, b).downscale(240)
        val res = runCatching { SevenSegment.read(crop) }.getOrNull()
        ZoneRead(res?.digits, res?.confidence ?: 0f)
    }
