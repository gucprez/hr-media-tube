package com.hrmediasports.studio.camera

enum class Quality(val label: String) { Unknown("Midiendo…"), Good("Buena"), Fair("Regular"), Poor("Mala") }

/** Muestra de estadísticas del emisor (valores nulos = el sistema aún no los entrega). */
data class SendStats(
    val width: Int = 0,
    val height: Int = 0,
    val fps: Double = 0.0,
    val bitrateKbps: Int = 0,
    val encoder: String = "",
    val rttMs: Int? = null,
    val lossPct: Double? = null,
    val limitation: String? = null,
)

object QualityMapper {
    /** Heurística documentada en docs/PRUEBAS-TECNICAS.md; los umbrales se calibran con las pruebas reales. */
    fun quality(s: SendStats, targetFps: Int = 30): Quality {
        if (s.width == 0 || s.fps <= 0.0) return Quality.Unknown
        val limited = !s.limitation.isNullOrBlank() && s.limitation != "none"
        val loss = s.lossPct ?: 0.0
        val rtt = s.rttMs ?: 0
        return when {
            s.fps >= targetFps * 0.9 && loss < 2.0 && rtt < 100 && !limited -> Quality.Good
            s.fps >= targetFps * 0.5 && loss < 8.0 && rtt < 300 -> Quality.Fair
            else -> Quality.Poor
        }
    }

    /**
     * ¿Es codificación por hardware? WebRTC informa el nombre del codificador. Libvpx/OpenH264/SW son
     * software; los códecs de MediaCodec (c2.*, OMX.*) son del chip. Es una inferencia, no una garantía.
     */
    fun isHardwareEncoder(name: String): Boolean? {
        if (name.isBlank()) return null
        val n = name.lowercase()
        return when {
            "libvpx" in n || "openh264" in n || "software" in n || "libaom" in n || "ffmpeg" in n -> false
            "mediacodec" in n || n.startsWith("c2.") || n.startsWith("omx.") -> true
            else -> null
        }
    }
}
