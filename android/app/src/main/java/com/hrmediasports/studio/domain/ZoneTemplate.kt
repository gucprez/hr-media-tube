package com.hrmediasports.studio.domain


/** Zonas de la pizarra que el lector debe observar. */
enum class ZoneId(val key: String, val label: String) {
    Home("home", "Local"),
    Clock("clock", "Tiempo"),
    Away("away", "Visita"),
    Period("period", "Cuarto"),
    Possession("possession", "Posesión"),
}

/** Rectángulo normalizado (0..1) respecto del encuadre de la cámara. */
data class NormRect(val left: Float, val top: Float, val right: Float, val bottom: Float) {
    init {
        require(left >= 0f && top >= 0f && right <= 1f && bottom <= 1f) { "Zona fuera del encuadre" }
        require(right - left >= MIN_SIZE - EPS && bottom - top >= MIN_SIZE - EPS) { "Zona demasiado pequeña" }
    }

    val width: Float get() = right - left
    val height: Float get() = bottom - top

    /** Desplaza sin salirse del encuadre y conservando el tamaño. */
    fun moved(dx: Float, dy: Float): NormRect {
        val l = (left + dx).coerceIn(0f, 1f - width)
        val t = (top + dy).coerceIn(0f, 1f - height)
        return NormRect(l, t, l + width, t + height)
    }

    /** Escala alrededor del centro; el resultado queda dentro del encuadre y con tamaño mínimo. */
    fun scaled(factor: Float): NormRect {
        val w = (width * factor).coerceIn(MIN_SIZE, 1f)
        val h = (height * factor).coerceIn(MIN_SIZE, 1f)
        val l = ((left + right) / 2f - w / 2f).coerceIn(0f, 1f - w)
        val t = ((top + bottom) / 2f - h / 2f).coerceIn(0f, 1f - h)
        return NormRect(l, t, l + w, t + h)
    }

    companion object {
        const val MIN_SIZE = 0.05f
        private const val EPS = 1e-4f
    }
}

/** Plantilla de zonas: una por cada [ZoneId]. Es la única parte del lector que ya es funcional. */
data class ZoneTemplate(val zones: Map<ZoneId, NormRect>) {
    init {
        require(zones.keys == ZoneId.entries.toSet()) { "La plantilla debe tener las cinco zonas" }
    }

    operator fun get(id: ZoneId): NormRect = zones.getValue(id)

    fun with(id: ZoneId, rect: NormRect): ZoneTemplate = ZoneTemplate(zones + (id to rect))

    /** Texto estable y sin dependencia de idioma: `home=l,t,r,b;clock=...`. */
    fun encode(): String = ZoneId.entries.joinToString(";") { id ->
        val r = this[id]
        "${id.key}=${r.left},${r.top},${r.right},${r.bottom}"
    }

    companion object {
        /** Posiciones de partida tomadas del diseño de referencia. */
        val Default = ZoneTemplate(
            mapOf(
                ZoneId.Home to NormRect(0.10f, 0.15f, 0.32f, 0.42f),
                ZoneId.Clock to NormRect(0.35f, 0.16f, 0.67f, 0.34f),
                ZoneId.Away to NormRect(0.71f, 0.15f, 0.92f, 0.42f),
                ZoneId.Period to NormRect(0.41f, 0.40f, 0.60f, 0.58f),
                ZoneId.Possession to NormRect(0.41f, 0.75f, 0.59f, 0.92f),
            ),
        )

        /** Devuelve null si el texto está incompleto o es inválido (nunca lanza). */
        fun decode(text: String?): ZoneTemplate? {
            if (text.isNullOrBlank()) return null
            return try {
                val map = text.split(";").associate { part ->
                    val (key, value) = part.split("=", limit = 2)
                    val id = ZoneId.entries.first { it.key == key }
                    val n = value.split(",").map { it.toFloat() }
                    require(n.size == 4)
                    id to NormRect(n[0], n[1], n[2], n[3])
                }
                ZoneTemplate(map)
            } catch (e: Exception) {
                null
            }
        }
    }
}

/** Dónde se guarda la plantilla (capa de datos; en Android, SharedPreferences). */
interface ZoneTemplateStore {
    fun load(): ZoneTemplate?

    /** Devuelve false si no se pudo guardar. */
    fun save(template: ZoneTemplate): Boolean
}

class InMemoryZoneTemplateStore(private var current: ZoneTemplate? = null, private val failSaves: Boolean = false) :
    ZoneTemplateStore {
    override fun load() = current
    override fun save(template: ZoneTemplate): Boolean {
        if (failSaves) return false
        current = template
        return true
    }
}

