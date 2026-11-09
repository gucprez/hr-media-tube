package com.hrmediasports.studio.protocol

/**
 * Capa 3 (protocolo entre dispositivos): estado de enlace y reglas de antigüedad del protocolo v1.
 * La especificación del cable (JSON, transporte, versionado) está en docs/protocolo-pizarra.md.
 * En esta entrega NO existe transporte ni serialización: solo los tipos de estado y las reglas de
 * antigüedad que consume la UI.
 */
/** Tiempo sin lecturas a partir del cual el dato se considera desactualizado. */
const val STALE_AFTER_MILLIS = 3_000L

/** Quién produjo el dato mostrado. */
enum class ReadingOrigin { Demo, Remote, Manual }

/** Estado del enlace con el dispositivo lector (otro celular). */
enum class LinkState { NotConnected, Connecting, Connected }

/** Milisegundos transcurridos desde la recepción; nunca negativo. */
fun readingAgeMillis(nowMillis: Long, receivedAtMillis: Long): Long =
    (nowMillis - receivedAtMillis).coerceAtLeast(0L)

fun isStale(ageMillis: Long): Boolean = ageMillis > STALE_AFTER_MILLIS

/** "hace 0,2 s", "hace 12 s", "hace 3 min". */
fun formatAge(ageMillis: Long): String {
    val ms = ageMillis.coerceAtLeast(0L)
    return when {
        ms < 10_000L -> {
            val tenths = ms / 100
            "hace ${tenths / 10},${tenths % 10} s"
        }
        ms < 60_000L -> "hace ${ms / 1000} s"
        else -> "hace ${ms / 60_000} min"
    }
}
