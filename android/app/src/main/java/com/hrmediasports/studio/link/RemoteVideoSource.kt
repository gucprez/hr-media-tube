package com.hrmediasports.studio.link

import android.graphics.SurfaceTexture
import android.media.MediaCodec
import android.media.MediaFormat
import android.os.SystemClock
import android.util.Log
import android.view.Surface
import com.pedro.encoder.input.sources.OrientationForced
import com.pedro.encoder.input.sources.video.VideoSource
import java.nio.ByteBuffer
import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.TimeUnit

/**
 * Fuente de video del Estudio que muestra una CÁMARA REMOTA: decodifica por hardware el H.264 que
 * llega por wifi y lo dibuja en la misma superficie OpenGL que la cámara local. Así el marcador,
 * el logo y la transmisión funcionan igual con cualquier cámara.
 *
 * Si se pierden cuadros, espera el siguiente cuadro clave en vez de mostrar imagen rota.
 */
class RemoteVideoSource : VideoSource() {
    private val queue = ArrayBlockingQueue<VideoFrame>(90)
    @Volatile private var config: VideoConfig? = null
    @Volatile private var configChanged = false
    @Volatile private var running = false
    @Volatile private var needKey = true
    private var thread: Thread? = null
    private var surface: Surface? = null

    /** Último cuadro mostrado (SystemClock.elapsedRealtime), 0 si ninguno. */
    @Volatile var lastFrameAt = 0L; private set
    @Volatile var decodedFrames = 0L; private set

    fun setConfig(c: VideoConfig) {
        if (c == config) return
        config = c; configChanged = true; needKey = true
    }

    fun push(f: VideoFrame) {
        if (!running) return
        if (!queue.offer(f)) { queue.clear(); needKey = true } // retraso acumulado: se descarta y se espera clave
    }

    override fun create(width: Int, height: Int, fps: Int, rotation: Int): Boolean = true

    override fun start(surfaceTexture: SurfaceTexture) {
        this.surfaceTexture = surfaceTexture
        surface = Surface(surfaceTexture)
        queue.clear(); needKey = true; configChanged = true
        running = true
        thread = Thread({ decodeLoop() }, "remote-decoder").apply { isDaemon = true; start() }
    }

    override fun stop() {
        running = false
        thread?.join(1_000)
        thread = null
        runCatching { surface?.release() }
        surface = null
    }

    override fun release() { if (running) stop() }
    override fun isRunning(): Boolean = running
    override fun getOrientationConfig(): OrientationForced = OrientationForced.LANDSCAPE

    private fun decodeLoop() {
        var codec: MediaCodec? = null
        val info = MediaCodec.BufferInfo()
        try {
            while (running) {
                if (configChanged || codec == null) {
                    val c = config
                    if (c == null) { Thread.sleep(50); continue }
                    codec?.let { runCatching { it.stop(); it.release() } }
                    codec = createDecoder(c) ?: run { Thread.sleep(500); null }
                    configChanged = false
                    if (codec == null) continue
                }
                val f = queue.poll(50, TimeUnit.MILLISECONDS)
                if (f != null && !(needKey && !f.keyFrame)) {
                    needKey = false
                    val idx = codec.dequeueInputBuffer(10_000)
                    if (idx >= 0) {
                        val buf = codec.getInputBuffer(idx)!!
                        buf.clear()
                        if (f.data.size <= buf.capacity()) {
                            buf.put(f.data)
                            codec.queueInputBuffer(idx, 0, f.data.size, f.ptsUs, if (f.keyFrame) MediaCodec.BUFFER_FLAG_KEY_FRAME else 0)
                        } else codec.queueInputBuffer(idx, 0, 0, f.ptsUs, 0)
                    }
                }
                // Se dibuja todo lo decodificado de inmediato: menor retraso posible.
                while (true) {
                    val out = codec.dequeueOutputBuffer(info, 0)
                    if (out >= 0) {
                        codec.releaseOutputBuffer(out, true)
                        lastFrameAt = SystemClock.elapsedRealtime(); decodedFrames++
                    } else break
                }
            }
        } catch (e: Exception) {
            Log.w("RemoteVideoSource", "decodificador", e)
        } finally {
            codec?.let { runCatching { it.stop(); it.release() } }
        }
    }

    private fun createDecoder(c: VideoConfig): MediaCodec? = runCatching {
        val fmt = MediaFormat.createVideoFormat(MediaFormat.MIMETYPE_VIDEO_AVC, c.width, c.height)
        fmt.setByteBuffer("csd-0", ByteBuffer.wrap(withStartCode(c.sps)))
        fmt.setByteBuffer("csd-1", ByteBuffer.wrap(withStartCode(c.pps)))
        fmt.setInteger(MediaFormat.KEY_MAX_INPUT_SIZE, 1 shl 20)
        surfaceTexture?.setDefaultBufferSize(c.width, c.height)
        MediaCodec.createDecoderByType(MediaFormat.MIMETYPE_VIDEO_AVC).apply {
            configure(fmt, surface, null, 0)
            start()
        }
    }.onFailure { Log.w("RemoteVideoSource", "no se pudo crear el decodificador", it) }.getOrNull()

    companion object {
        fun withStartCode(b: ByteArray): ByteArray =
            if (b.size >= 4 && b[0] == 0.toByte() && b[1] == 0.toByte() && (b[2] == 1.toByte() || (b[2] == 0.toByte() && b[3] == 1.toByte()))) b
            else byteArrayOf(0, 0, 0, 1) + b
    }
}
