package com.hrmediasports.studio.data

import android.content.Context
import com.hrmediasports.studio.domain.ZoneTemplate
import com.hrmediasports.studio.domain.ZoneTemplateStore

/** Guarda la plantilla de zonas localmente en el dispositivo; no sale a ninguna red. */
class SharedPrefsZoneTemplateStore(context: Context) : ZoneTemplateStore {
    private val prefs = context.applicationContext.getSharedPreferences("reader", Context.MODE_PRIVATE)

    override fun load(): ZoneTemplate? = ZoneTemplate.decode(prefs.getString(KEY, null))

    override fun save(template: ZoneTemplate): Boolean = prefs.edit().putString(KEY, template.encode()).commit()

    private companion object {
        const val KEY = "zones_v1"
    }
}
