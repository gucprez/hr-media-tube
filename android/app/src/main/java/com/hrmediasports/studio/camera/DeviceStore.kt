package com.hrmediasports.studio.camera

import android.content.Context
import android.os.Build
import com.hrmediasports.link.Crypto
import java.util.UUID

/** Estudio al que ya está vinculado este dispositivo (se guarda solo en el teléfono). */
data class StudioBinding(val host: String, val port: Int, val tokenB64: String) {
    val token: ByteArray get() = Crypto.fromB64url(tokenB64)
}

/**
 * Identidad estable del dispositivo + vínculo guardado. El `deviceId` no cambia entre
 * reconexiones: es lo que impide que el estudio cree una fuente duplicada.
 */
class DeviceStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences("remote_camera", Context.MODE_PRIVATE)

    val deviceId: String
        get() = prefs.getString("device_id", null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString("device_id", it).apply()
        }

    var deviceName: String
        get() = prefs.getString("device_name", null) ?: defaultName()
        set(value) = prefs.edit().putString("device_name", value.trim().take(40).ifBlank { defaultName() }).apply()

    var binding: StudioBinding?
        get() {
            val host = prefs.getString("host", null) ?: return null
            val token = prefs.getString("token", null) ?: return null
            return StudioBinding(host, prefs.getInt("port", 0), token)
        }
        set(value) {
            prefs.edit().apply {
                if (value == null) { remove("host"); remove("port"); remove("token") }
                else { putString("host", value.host); putInt("port", value.port); putString("token", value.tokenB64) }
            }.apply()
        }

    private fun defaultName() = (Build.MODEL ?: "Android").take(40)
}
