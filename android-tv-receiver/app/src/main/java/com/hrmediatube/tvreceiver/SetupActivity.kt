package com.hrmediatube.tvreceiver

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import com.hrmediatube.tvreceiver.discovery.ServerDiscovery
import com.hrmediatube.tvreceiver.util.Prefs

/**
 * Pantalla de configuración: permite escribir la IP del PC manualmente o esperar
 * a que el script del PC se anuncie por broadcast UDP en la red local.
 */
class SetupActivity : Activity() {

    private lateinit var editIp: EditText
    private lateinit var editPort: EditText
    private lateinit var editPath: EditText
    private lateinit var textDiscovery: TextView
    private lateinit var prefs: Prefs

    private var discovery: ServerDiscovery? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_setup)

        prefs = Prefs(this)

        editIp = findViewById(R.id.edit_ip)
        editPort = findViewById(R.id.edit_port)
        editPath = findViewById(R.id.edit_path)
        textDiscovery = findViewById(R.id.text_discovery)
        val buttonConnect = findViewById<Button>(R.id.button_connect)

        prefs.serverIp?.let { editIp.setText(it) }
        editPort.setText(prefs.serverPort.toString())
        editPath.setText(prefs.streamPath)

        buttonConnect.setOnClickListener { connect() }

        discovery = ServerDiscovery { ip, port, path ->
            textDiscovery.text = getString(R.string.server_found, ip)
            editIp.setText(ip)
            editPort.setText(port.toString())
            editPath.setText(path)
        }
    }

    override fun onResume() {
        super.onResume()
        discovery?.start()
    }

    override fun onPause() {
        super.onPause()
        discovery?.stop()
    }

    private fun connect() {
        val ip = editIp.text.toString().trim()
        val port = editPort.text.toString().trim().toIntOrNull() ?: Prefs.DEFAULT_PORT
        val path = editPath.text.toString().trim().ifEmpty { Prefs.DEFAULT_PATH }

        if (ip.isEmpty()) {
            Toast.makeText(this, getString(R.string.label_ip), Toast.LENGTH_SHORT).show()
            return
        }

        prefs.save(ip, port, path)

        val intent = Intent(this, PlayerActivity::class.java).apply {
            putExtra(PlayerActivity.EXTRA_IP, ip)
            putExtra(PlayerActivity.EXTRA_PORT, port)
            putExtra(PlayerActivity.EXTRA_PATH, path)
        }
        startActivity(intent)
        finish()
    }
}
