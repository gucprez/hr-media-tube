package com.hrmediatube.tvreceiver

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.KeyEvent
import android.view.WindowManager
import android.widget.LinearLayout
import android.widget.TextView
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.rtsp.RtspMediaSource
import androidx.media3.ui.PlayerView
import com.hrmediatube.tvreceiver.util.Prefs

/**
 * Reproduce el stream RTSP del PC a pantalla completa.
 *
 * Pensado para redes WiFi domésticas donde el WiFi puede tener microcortes:
 * - Usa RTSP sobre TCP (más resistente a paquetes perdidos que UDP).
 * - Buffer más grande de lo normal para absorber jitter de la red.
 * - Si la conexión se cae, reintenta solo de forma indefinida con backoff exponencial,
 *   mostrando en pantalla que está "reconectando" en vez de congelarse o cerrar la app.
 */
class PlayerActivity : Activity() {

    private lateinit var playerView: PlayerView
    private lateinit var overlayStatus: LinearLayout
    private lateinit var textStatus: TextView

    private var player: ExoPlayer? = null
    private lateinit var streamUrl: String
    private var lowLatencyMode: Boolean = true

    private val retryHandler = Handler(Looper.getMainLooper())
    private var retryDelayMs = INITIAL_RETRY_DELAY_MS
    private var retryRunnable: Runnable? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_player)

        playerView = findViewById(R.id.player_view)
        overlayStatus = findViewById(R.id.overlay_status)
        textStatus = findViewById(R.id.text_status)

        val ip = intent.getStringExtra(EXTRA_IP)
        if (ip.isNullOrEmpty()) {
            // No hay servidor configurado: volvemos a configuración.
            startActivity(Intent(this, SetupActivity::class.java))
            finish()
            return
        }
        val port = intent.getIntExtra(EXTRA_PORT, Prefs.DEFAULT_PORT)
        val path = intent.getStringExtra(EXTRA_PATH) ?: Prefs.DEFAULT_PATH
        lowLatencyMode = intent.getBooleanExtra(EXTRA_LOW_LATENCY, true)
        streamUrl = "rtsp://$ip:$port/$path"
    }

    override fun onStart() {
        super.onStart()
        initializePlayer()
    }

    override fun onStop() {
        super.onStop()
        cancelPendingRetry()
        releasePlayer()
    }

    private fun initializePlayer() {
        if (player != null) return

        showStatus(getString(R.string.status_connecting))

        val loadControl = if (lowLatencyMode) {
            DefaultLoadControl.Builder()
                .setBufferDurationsMs(
                    LOW_LATENCY_MIN_BUFFER_MS,
                    LOW_LATENCY_MAX_BUFFER_MS,
                    LOW_LATENCY_BUFFER_FOR_PLAYBACK_MS,
                    LOW_LATENCY_BUFFER_FOR_PLAYBACK_AFTER_REBUFFER_MS
                )
                .build()
        } else {
            DefaultLoadControl.Builder()
                .setBufferDurationsMs(
                    STABLE_MIN_BUFFER_MS,
                    STABLE_MAX_BUFFER_MS,
                    STABLE_BUFFER_FOR_PLAYBACK_MS,
                    STABLE_BUFFER_FOR_PLAYBACK_AFTER_REBUFFER_MS
                )
                .build()
        }

        val exoPlayer = ExoPlayer.Builder(this)
            .setLoadControl(loadControl)
            .build()
        exoPlayer.setWakeMode(C.WAKE_MODE_NETWORK)
        exoPlayer.addListener(playerListener)
        playerView.player = exoPlayer
        player = exoPlayer

        startPlayback()
    }

    private fun startPlayback() {
        val exoPlayer = player ?: return

        val mediaSource = RtspMediaSource.Factory()
            .setForceUseRtpTcp(true) // TCP: evita artefactos/cortes por paquetes UDP perdidos en WiFi
            .createMediaSource(MediaItem.fromUri(streamUrl))

        exoPlayer.setMediaSource(mediaSource)
        exoPlayer.prepare()
        exoPlayer.playWhenReady = true
    }

    private val playerListener = object : Player.Listener {
        override fun onPlaybackStateChanged(playbackState: Int) {
            when (playbackState) {
                Player.STATE_READY -> {
                    hideStatus()
                    retryDelayMs = INITIAL_RETRY_DELAY_MS // la conexión se recuperó: reseteamos el backoff
                }
                Player.STATE_BUFFERING -> showStatus(getString(R.string.status_buffering))
                else -> Unit
            }
        }

        override fun onPlayerError(error: PlaybackException) {
            scheduleReconnect()
        }
    }

    private fun scheduleReconnect() {
        showStatus(getString(R.string.status_reconnecting))
        cancelPendingRetry()

        val runnable = Runnable {
            player?.let {
                it.stop()
                startPlayback()
            }
        }
        retryRunnable = runnable
        retryHandler.postDelayed(runnable, retryDelayMs)

        retryDelayMs = (retryDelayMs * 2).coerceAtMost(MAX_RETRY_DELAY_MS)
    }

    private fun cancelPendingRetry() {
        retryRunnable?.let { retryHandler.removeCallbacks(it) }
        retryRunnable = null
    }

    private fun showStatus(message: String) {
        textStatus.text = message
        overlayStatus.visibility = LinearLayout.VISIBLE
    }

    private fun hideStatus() {
        overlayStatus.visibility = LinearLayout.GONE
    }

    private fun releasePlayer() {
        player?.let {
            it.removeListener(playerListener)
            it.release()
        }
        player = null
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        if (keyCode == KeyEvent.KEYCODE_MENU) {
            startActivity(Intent(this, SetupActivity::class.java))
            finish()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    companion object {
        const val EXTRA_IP = "extra_ip"
        const val EXTRA_PORT = "extra_port"
        const val EXTRA_PATH = "extra_path"
        const val EXTRA_LOW_LATENCY = "extra_low_latency"

        private const val INITIAL_RETRY_DELAY_MS = 2000L
        private const val MAX_RETRY_DELAY_MS = 15000L

        // Modo llamadas: casi tiempo real. Con WiFi inestable puede notarse más
        // el tartamudeo antes de recuperar, pero es lo que se necesita para hablar
        // por videollamada sin sentir retraso.
        private const val LOW_LATENCY_MIN_BUFFER_MS = 300
        private const val LOW_LATENCY_MAX_BUFFER_MS = 1000
        private const val LOW_LATENCY_BUFFER_FOR_PLAYBACK_MS = 150
        private const val LOW_LATENCY_BUFFER_FOR_PLAYBACK_AFTER_REBUFFER_MS = 300

        // Modo película: buffers generosos para absorber cortes/jitter típicos de
        // WiFi doméstico a costa de 2-5s de retraso respecto al PC.
        private const val STABLE_MIN_BUFFER_MS = 5000
        private const val STABLE_MAX_BUFFER_MS = 30000
        private const val STABLE_BUFFER_FOR_PLAYBACK_MS = 2500
        private const val STABLE_BUFFER_FOR_PLAYBACK_AFTER_REBUFFER_MS = 5000
    }
}
