package com.hrmediasports.studio.live

import android.content.Context
import android.media.MediaCodec
import com.pedro.common.AudioCodec
import com.pedro.common.ConnectChecker
import com.pedro.common.VideoCodec
import com.pedro.encoder.input.sources.audio.MicrophoneSource
import com.pedro.encoder.input.sources.video.Camera2Source
import com.pedro.library.base.StreamBase
import com.pedro.library.util.streamclient.RtmpStreamClient
import com.pedro.library.util.streamclient.StreamBaseClient
import com.pedro.library.util.streamclient.StreamClientListener
import com.pedro.rtmp.rtmp.RtmpClient
import java.nio.ByteBuffer

/**
 * Codifica UNA vez (cámara + micrófono, codificador por hardware) y envía el mismo video a varios
 * servidores RTMP/RTMPS. Cada destino se conecta, reintenta y se detiene por separado: si Facebook
 * se cae, YouTube sigue. Los codificadores solo se detienen cuando no queda ningún destino activo.
 *
 * El cliente RTMP copia cada cuadro antes de encolarlo, así que compartir el búfer es seguro.
 */
class MultiRtmpStream(context: Context, checkers: List<ConnectChecker>) :
    StreamBase(context, Camera2Source(context), MicrophoneSource()) {

    private val keyframeListener = object : StreamClientListener {
        override fun onRequestKeyframe() = requestKeyframe()
    }
    private val clients: List<RtmpClient> = checkers.map { RtmpClient(it) }
    private val streamClients = clients.map { RtmpStreamClient(it, keyframeListener) }
    private val active = BooleanArray(clients.size)

    // Al detener un destino, el cliente RTMP borra SPS/PPS e info de audio. Se guardan aquí para
    // poder reconectar ese destino más tarde sin reiniciar los codificadores (ni cortar al otro).
    @Volatile private var videoInfo: Triple<ByteBuffer, ByteBuffer?, ByteBuffer?>? = null
    @Volatile private var audioInfo: Pair<Int, Boolean>? = null

    private fun copyOf(b: ByteBuffer?): ByteBuffer? = b?.let {
        val d = it.duplicate(); d.rewind()
        ByteBuffer.allocate(d.remaining()).put(d).also { c -> c.flip() }
    }

    val size: Int get() = clients.size
    val camera: Camera2Source get() = videoSource as Camera2Source
    val microphone: MicrophoneSource get() = audioSource as MicrophoneSource

    fun client(i: Int): StreamBaseClient = streamClients[i]
    fun isActive(i: Int): Boolean = synchronized(active) { active[i] }
    fun anyActive(): Boolean = synchronized(active) { active.any { it } }

    /** Conecta el destino [i]. Arranca los codificadores si es el primero. */
    fun start(i: Int, endpoint: String) {
        synchronized(active) {
            if (active[i]) return
            active[i] = true
        }
        if (!isStreaming) startStream("") else requestKeyframe()
        val c = clients[i]
        val res = getVideoResolution()
        c.setVideoResolution(res.width, res.height)
        c.setFps(getVideoFps())
        c.setReTries(RECONNECT_TRIES)
        audioInfo?.let { (rate, stereo) -> c.setAudioInfo(rate, stereo) }
        videoInfo?.let { (sps, pps, vps) -> c.setVideoInfo(sps.duplicate(), pps?.duplicate(), vps?.duplicate()) }
        c.connect(endpoint)
    }

    /** Desconecta el destino [i]. Detiene los codificadores si era el último (y no se graba). */
    fun stop(i: Int) {
        val last: Boolean
        synchronized(active) {
            if (!active[i]) return
            active[i] = false
            last = active.none { it }
        }
        clients[i].disconnect()
        if (last && isStreaming) stopStream()
    }

    fun stopAll() { for (i in clients.indices) stop(i) }

    override fun getStreamClient(): StreamBaseClient = streamClients.first()

    override fun setVideoCodecImp(codec: VideoCodec) = clients.forEach { it.setVideoCodec(codec) }
    override fun setAudioCodecImp(codec: AudioCodec) = clients.forEach { it.setAudioCodec(codec) }
    override fun onAudioInfoImp(sampleRate: Int, isStereo: Boolean) {
        audioInfo = sampleRate to isStereo
        clients.forEach { it.setAudioInfo(sampleRate, isStereo) }
    }

    // La conexión de cada destino la hace start(i); el arranque base solo enciende los codificadores.
    override fun startStreamImp(endPoint: String) {}
    override fun stopStreamImp() {}

    override fun onVideoInfoImp(sps: ByteBuffer, pps: ByteBuffer?, vps: ByteBuffer?) {
        val saved = Triple(copyOf(sps)!!, copyOf(pps), copyOf(vps))
        videoInfo = saved
        clients.forEach { it.setVideoInfo(saved.first.duplicate(), saved.second?.duplicate(), saved.third?.duplicate()) }
    }

    override fun getVideoDataImp(videoBuffer: ByteBuffer, info: MediaCodec.BufferInfo) {
        for (i in clients.indices) if (isActive(i)) clients[i].sendVideo(videoBuffer, info)
    }

    override fun getAudioDataImp(audioBuffer: ByteBuffer, info: MediaCodec.BufferInfo) {
        for (i in clients.indices) if (isActive(i)) clients[i].sendAudio(audioBuffer, info)
    }
}
