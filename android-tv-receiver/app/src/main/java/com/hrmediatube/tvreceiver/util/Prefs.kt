package com.hrmediatube.tvreceiver.util

import android.content.Context

/** Guarda la dirección del PC emisor para reconectar automáticamente al iniciar la app. */
class Prefs(context: Context) {

    private val prefs = context.getSharedPreferences("tv_receiver_prefs", Context.MODE_PRIVATE)

    var serverIp: String?
        get() = prefs.getString(KEY_IP, null)
        set(value) = prefs.edit().putString(KEY_IP, value).apply()

    var serverPort: Int
        get() = prefs.getInt(KEY_PORT, DEFAULT_PORT)
        set(value) = prefs.edit().putInt(KEY_PORT, value).apply()

    var streamPath: String
        get() = prefs.getString(KEY_PATH, DEFAULT_PATH) ?: DEFAULT_PATH
        set(value) = prefs.edit().putString(KEY_PATH, value).apply()

    /** true = modo llamadas (baja latencia), false = modo película (más buffer, más estable). */
    var lowLatencyMode: Boolean
        get() = prefs.getBoolean(KEY_LOW_LATENCY, true)
        set(value) = prefs.edit().putBoolean(KEY_LOW_LATENCY, value).apply()

    fun save(ip: String, port: Int, path: String, lowLatency: Boolean) {
        prefs.edit()
            .putString(KEY_IP, ip)
            .putInt(KEY_PORT, port)
            .putString(KEY_PATH, path)
            .putBoolean(KEY_LOW_LATENCY, lowLatency)
            .apply()
    }

    fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_IP = "server_ip"
        private const val KEY_PORT = "server_port"
        private const val KEY_PATH = "stream_path"
        private const val KEY_LOW_LATENCY = "low_latency_mode"
        const val DEFAULT_PORT = 8554
        const val DEFAULT_PATH = "pc"
    }
}
