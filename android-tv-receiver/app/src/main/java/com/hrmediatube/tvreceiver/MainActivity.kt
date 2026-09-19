package com.hrmediatube.tvreceiver

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import com.hrmediatube.tvreceiver.util.Prefs

/**
 * Punto de entrada: si ya conocemos un PC de una sesión anterior, conectamos directo
 * al reproductor. Si no, vamos a la pantalla de configuración.
 */
class MainActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val prefs = Prefs(this)
        val savedIp = prefs.serverIp

        val intent = if (savedIp != null) {
            Intent(this, PlayerActivity::class.java).apply {
                putExtra(PlayerActivity.EXTRA_IP, savedIp)
                putExtra(PlayerActivity.EXTRA_PORT, prefs.serverPort)
                putExtra(PlayerActivity.EXTRA_PATH, prefs.streamPath)
                putExtra(PlayerActivity.EXTRA_LOW_LATENCY, prefs.lowLatencyMode)
            }
        } else {
            Intent(this, SetupActivity::class.java)
        }

        startActivity(intent)
        finish()
    }
}
