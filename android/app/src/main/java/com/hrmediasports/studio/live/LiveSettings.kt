package com.hrmediasports.studio.live

import android.content.Context

/** Configuración guardada de la transmisión. */
data class LiveSettings(
    val destinations: List<LiveDestination> = Platform.entries.map { LiveDestination(it) },
    val quality: Quality = Quality.Hd720,
    val record: Boolean = true,
    val logoUri: String? = null,
    val homeName: String = "LOC",
    val awayName: String = "VIS",
    val homeColor: Long = TEAM_COLORS[0],
    val awayColor: Long = TEAM_COLORS[1],
    val periodMinutes: Int = 10,
) {
    fun destination(p: Platform): LiveDestination = destinations.first { it.platform == p }
    fun withDestination(d: LiveDestination): LiveSettings = copy(destinations = destinations.map { if (it.platform == d.platform) d else it })
    val readyCount: Int get() = destinations.count { it.ready }
}

interface LiveSettingsStore {
    fun load(): LiveSettings
    fun save(s: LiveSettings)
}

class InMemoryLiveSettingsStore(private var s: LiveSettings = LiveSettings()) : LiveSettingsStore {
    override fun load() = s
    override fun save(s: LiveSettings) { this.s = s }
}

/**
 * Guarda en el almacenamiento privado de la app (sin copia de seguridad: allowBackup=false).
 * Las claves de transmisión nunca salen del teléfono ni se escriben en registros.
 */
class PrefsLiveSettingsStore(context: Context) : LiveSettingsStore {
    private val p = context.applicationContext.getSharedPreferences("transmision", Context.MODE_PRIVATE)

    override fun load(): LiveSettings {
        val def = LiveSettings()
        return LiveSettings(
            destinations = Platform.entries.map { pl ->
                LiveDestination(
                    platform = pl,
                    enabled = p.getBoolean("${pl.name}.on", false),
                    server = p.getString("${pl.name}.server", null) ?: pl.defaultServer,
                    key = p.getString("${pl.name}.key", null) ?: "",
                )
            },
            quality = runCatching { Quality.valueOf(p.getString("quality", null) ?: "") }.getOrDefault(def.quality),
            record = p.getBoolean("record", def.record),
            logoUri = p.getString("logo", null),
            homeName = p.getString("home", null) ?: def.homeName,
            awayName = p.getString("away", null) ?: def.awayName,
            homeColor = p.getLong("homeColor", def.homeColor),
            awayColor = p.getLong("awayColor", def.awayColor),
            periodMinutes = p.getInt("minutes", def.periodMinutes),
        )
    }

    override fun save(s: LiveSettings) {
        val e = p.edit()
        s.destinations.forEach { d ->
            e.putBoolean("${d.platform.name}.on", d.enabled)
            e.putString("${d.platform.name}.server", d.server.trim())
            e.putString("${d.platform.name}.key", d.key.trim())
        }
        e.putString("quality", s.quality.name).putBoolean("record", s.record).putString("logo", s.logoUri)
            .putString("home", s.homeName).putString("away", s.awayName)
            .putLong("homeColor", s.homeColor).putLong("awayColor", s.awayColor).putInt("minutes", s.periodMinutes)
            .apply()
    }
}
