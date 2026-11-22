package com.hrmediasports.studio.live

import java.util.Locale

/**
 * Transmisión en vivo: lógica pura (sin Android) para poder probarla aislada.
 * Destinos RTMP/RTMPS, calidad, política de temperatura, marcador y estado de cada destino.
 */

enum class Platform(val label: String, val defaultServer: String) {
    YouTube("YouTube", "rtmps://a.rtmps.youtube.com:443/live2"),
    Facebook("Facebook", "rtmps://live-api-s.facebook.com:443/rtmp"),
    Custom("Otro (Restream, etc.)", ""),
}

/** Un destino de transmisión. La clave nunca se muestra completa ni se registra. */
data class LiveDestination(
    val platform: Platform,
    val enabled: Boolean = false,
    val server: String = platform.defaultServer,
    val key: String = "",
) {
    /** Dirección completa a la que se conecta el cliente RTMP, o null si falta algo. */
    fun endpoint(): String? {
        val s = server.trim().trimEnd('/')
        val k = key.trim().trimStart('/')
        if (!(s.startsWith("rtmp://") || s.startsWith("rtmps://"))) return null
        if (s.substringAfter("://").isBlank()) return null
        return when {
            k.isNotEmpty() -> "$s/$k"
            // "Otro": se admite pegar la dirección completa con la clave incluida.
            platform == Platform.Custom && s.substringAfter("://").count { it == '/' } >= 2 -> s
            else -> null
        }
    }

    fun problem(): String? = when {
        !enabled -> null
        server.isBlank() -> "falta la dirección del servidor"
        !(server.trim().startsWith("rtmp://") || server.trim().startsWith("rtmps://")) -> "la dirección debe empezar con rtmp:// o rtmps://"
        endpoint() == null -> "falta la clave de transmisión"
        key.any { it.isWhitespace() } -> "la clave tiene espacios; vuelve a copiarla"
        else -> null
    }

    val ready: Boolean get() = enabled && problem() == null
}

/** Muestra solo los últimos 4 caracteres de la clave. */
fun maskKey(key: String): String {
    val k = key.trim()
    if (k.isEmpty()) return "sin clave"
    if (k.length <= 4) return "••••"
    return "••••" + k.takeLast(4)
}

/** Calidades. 720p30 es la recomendada: buena imagen con poco calor y poca subida. */
enum class Quality(val label: String, val width: Int, val height: Int, val fps: Int, val videoBitrate: Int, val note: String) {
    Hd720("720p · 30 fps (recomendada)", 1280, 720, 30, 3_500_000, "Buena imagen con poco calor"),
    Saver720("720p · 30 fps ahorro", 1280, 720, 30, 2_500_000, "Para internet débil o mucho calor"),
    FullHd1080("1080p · 30 fps", 1920, 1080, 30, 5_500_000, "Más calor y más subida; solo con buena red y ventilación"),
    ;

    /** Subida necesaria aproximada (Mbps) para [destinations] destinos directos, con 30 % de margen. */
    fun uploadNeededMbps(destinations: Int): Double =
        destinations.coerceAtLeast(1) * (videoBitrate + AUDIO_BITRATE) * 1.3 / 1_000_000.0

    companion object { const val AUDIO_BITRATE = 128_000 }
}

/** Estado térmico tal como lo reporta Android (PowerManager.THERMAL_STATUS_*). */
enum class Thermal(val label: String) {
    Unknown("sin dato"), None("normal"), Light("tibio"), Moderate("caliente"), Severe("muy caliente"),
    Critical("crítico"), Emergency("emergencia"), Shutdown("apagándose");

    companion object {
        fun fromAndroid(status: Int): Thermal = when (status) {
            0 -> None; 1 -> Light; 2 -> Moderate; 3 -> Severe; 4 -> Critical; 5 -> Emergency; 6 -> Shutdown
            else -> Unknown
        }
    }
}

/**
 * Política de temperatura: en vez de dejar que el teléfono se corte, baja la calidad de forma
 * progresiva. Devuelve el tope de bitrate como fracción del máximo de la calidad elegida.
 */
fun thermalBitrateFactor(t: Thermal): Double = when (t) {
    Thermal.Unknown, Thermal.None, Thermal.Light -> 1.0
    Thermal.Moderate -> 0.75
    Thermal.Severe -> 0.55
    Thermal.Critical, Thermal.Emergency, Thermal.Shutdown -> 0.4
}

fun thermalAdvice(t: Thermal): String? = when (t) {
    Thermal.Moderate -> "Teléfono caliente: bajé la calidad. Quita la funda y aléjalo del sol."
    Thermal.Severe -> "Muy caliente: calidad reducida. Activa el modo ahorro de pantalla y dale aire."
    Thermal.Critical, Thermal.Emergency, Thermal.Shutdown ->
        "Temperatura crítica: Android puede cerrar la cámara. Activa el ahorro y enfría el teléfono ya."
    else -> null
}

/** Tope de bitrate según calidad y temperatura (nunca menos de 800 kbps). */
fun bitrateCap(q: Quality, t: Thermal): Int = (q.videoBitrate * thermalBitrateFactor(t)).toInt().coerceAtLeast(800_000)

/** Estado de un destino. */
sealed interface LinkStatus {
    data object Off : LinkStatus
    data object Connecting : LinkStatus
    data object Live : LinkStatus
    data class Reconnecting(val attempt: Int, val reason: String) : LinkStatus
    data class Failed(val reason: String) : LinkStatus
}

fun LinkStatus.label(): String = when (this) {
    LinkStatus.Off -> "APAGADO"
    LinkStatus.Connecting -> "CONECTANDO…"
    LinkStatus.Live -> "EN VIVO"
    is LinkStatus.Reconnecting -> "RECONECTANDO ($attempt)"
    is LinkStatus.Failed -> "ERROR"
}

/** Traduce los motivos técnicos del cliente RTMP a algo que el operador entienda. */
fun friendlyReason(reason: String): String {
    val r = reason.lowercase(Locale.ROOT)
    return when {
        "auth" in r || ("publish" in r && "bad" in r) -> "la plataforma rechazó la clave (revísala o crea una nueva)"
        "timeout" in r || "timed out" in r -> "sin respuesta del servidor (internet lento o caído)"
        "unknownhost" in r || "unable to resolve" in r || "no address" in r -> "sin internet o sin DNS"
        "refused" in r -> "el servidor rechazó la conexión"
        "malformed" in r -> "dirección mal escrita"
        "broken pipe" in r || "reset" in r || "closed" in r -> "se cortó la conexión"
        "ssl" in r || "tls" in r || "handshake" in r -> "falló la conexión segura (revisa fecha/hora del teléfono)"
        else -> reason.take(80)
    }
}

/** Cuántos reintentos de reconexión: prácticamente ilimitados mientras el operador no detenga. */
const val RECONNECT_TRIES = 10_000
const val RECONNECT_DELAY_MS = 3_000L
