package com.hrmediasports.studio.camera

import android.content.Context
import androidx.lifecycle.LifecycleOwner
import org.webrtc.AudioTrack
import org.webrtc.DefaultVideoDecoderFactory
import org.webrtc.DefaultVideoEncoderFactory
import org.webrtc.EglBase
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStreamTrack
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpParameters
import org.webrtc.RtpSender
import org.webrtc.RtpTransceiver
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import org.webrtc.SurfaceTextureHelper
import org.webrtc.VideoSource
import org.webrtc.VideoTrack

/** Resolución y tasa objetivo de la primera versión. */
const val TARGET_WIDTH = 1280
const val TARGET_HEIGHT = 720
const val TARGET_FPS = 30
const val MAX_BITRATE_BPS = 2_500_000

/**
 * Emisor WebRTC: captura (CameraX), codifica (preferentemente por hardware vía MediaCodec) y envía.
 * No usa servidores STUN/TURN: en la misma red local bastan los candidatos de host.
 */
class CameraSender(context: Context) {
    private val appContext = context.applicationContext
    val eglBase: EglBase = EglBase.create()
    private val factory: PeerConnectionFactory
    private var helper: SurfaceTextureHelper? = null
    private var videoSource: VideoSource? = null
    var videoTrack: VideoTrack? = null
        private set
    private var audioTrack: AudioTrack? = null
    private var capturer: CameraXCapturer? = null
    private var pc: PeerConnection? = null
    private var videoSender: RtpSender? = null

    @Volatile
    var hardwareH264Available: Boolean = false
        private set

    init {
        PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(appContext).createInitializationOptions())
        // enableIntelVp8Encoder/H264HighProfile = true: el codificador de hardware se usa siempre que el
        // chip lo ofrezca; si no, WebRTC cae a software (VP8/VP9) automáticamente.
        val encoderFactory = DefaultVideoEncoderFactory(eglBase.eglBaseContext, true, true)
        hardwareH264Available = encoderFactory.supportedCodecs.any { it.name.equals("H264", ignoreCase = true) }
        factory = PeerConnectionFactory.builder()
            .setVideoEncoderFactory(encoderFactory)
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(eglBase.eglBaseContext))
            .createPeerConnectionFactory()
    }

    /** Inicia captura local (vista previa + pista de video; la de audio solo si se pidió). */
    fun startCapture(owner: LifecycleOwner, lens: Lens, audio: Boolean, onCaps: (CameraCaps) -> Unit) {
        stopCapture()
        val h = SurfaceTextureHelper.create("capture", eglBase.eglBaseContext)
        helper = h
        val src = factory.createVideoSource(false)
        videoSource = src
        val cap = CameraXCapturer(owner, lens, onCaps)
        cap.initialize(h, appContext, src.capturerObserver)
        cap.startCapture(TARGET_WIDTH, TARGET_HEIGHT, TARGET_FPS)
        capturer = cap
        videoTrack = factory.createVideoTrack("video0", src).apply { setEnabled(true) }
        if (audio) enableAudioTrack(true)
    }

    private fun enableAudioTrack(on: Boolean) {
        if (audioTrack == null && on) {
            val src = factory.createAudioSource(MediaConstraints())
            audioTrack = factory.createAudioTrack("audio0", src)
        }
        audioTrack?.setEnabled(on)
    }

    /** Silencia o activa el micrófono; si aún no había pista, se crea (requiere permiso concedido). */
    fun setAudioEnabled(on: Boolean) = enableAudioTrack(on)

    val hasAudioTrack get() = audioTrack != null

    fun switchLens(lens: Lens) = capturer?.switchLens(lens)
    fun setZoom(ratio: Float) = capturer?.setZoomRatio(ratio)
    fun setExposure(index: Int) = capturer?.setExposureIndex(index)
    fun focusAt(x: Float, y: Float) = capturer?.focusAt(x, y)
    val currentLens: Lens? get() = capturer?.lens

    interface PeerEvents {
        fun onOffer(sdp: String)
        fun onIce(c: IceCandidate)
        fun onConnectionState(state: PeerConnection.PeerConnectionState)
    }

    /** Crea una conexión nueva y su oferta. Reemplaza cualquier conexión anterior (sin dejar duplicados). */
    fun startPeer(events: PeerEvents) {
        closePeer()
        val config = PeerConnection.RTCConfiguration(emptyList()).apply {
            sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN
            continualGatheringPolicy = PeerConnection.ContinualGatheringPolicy.GATHER_CONTINUALLY
        }
        val observer = object : PeerConnection.Observer {
            override fun onIceCandidate(c: IceCandidate) = events.onIce(c)
            override fun onConnectionChange(newState: PeerConnection.PeerConnectionState) = events.onConnectionState(newState)
            override fun onSignalingChange(p0: PeerConnection.SignalingState?) {}
            override fun onIceConnectionChange(p0: PeerConnection.IceConnectionState?) {}
            override fun onIceConnectionReceivingChange(p0: Boolean) {}
            override fun onIceGatheringChange(p0: PeerConnection.IceGatheringState?) {}
            override fun onIceCandidatesRemoved(p0: Array<out IceCandidate>?) {}
            override fun onAddStream(p0: org.webrtc.MediaStream?) {}
            override fun onRemoveStream(p0: org.webrtc.MediaStream?) {}
            override fun onDataChannel(p0: org.webrtc.DataChannel?) {}
            override fun onRenegotiationNeeded() {}
        }
        val conn = factory.createPeerConnection(config, observer) ?: return
        pc = conn

        videoTrack?.let { t ->
            videoSender = conn.addTrack(t, listOf("stream0"))
            applyVideoParameters(videoSender)
            preferH264IfHardware(conn)
        }
        audioTrack?.let { conn.addTrack(it, listOf("stream0")) }

        conn.createOffer(object : SimpleSdp() {
            override fun onCreateSuccess(desc: SessionDescription) {
                conn.setLocalDescription(object : SimpleSdp() {
                    override fun onSetSuccess() = events.onOffer(desc.description)
                }, desc)
            }
        }, MediaConstraints())
    }

    /** 720p/30 sostenido: tasa máxima explícita y mantener la resolución (bajar fps antes que píxeles). */
    private fun applyVideoParameters(sender: RtpSender?) {
        val s = sender ?: return
        val p = s.parameters
        p.encodings.firstOrNull()?.let {
            it.maxBitrateBps = MAX_BITRATE_BPS
            it.maxFramerate = TARGET_FPS
        }
        p.degradationPreference = RtpParameters.DegradationPreference.MAINTAIN_RESOLUTION
        s.parameters = p
    }

    /** Si el chip ofrece H.264 por hardware, se prefiere (el estudio en Chrome lo decodifica). */
    private fun preferH264IfHardware(conn: PeerConnection) {
        if (!hardwareH264Available) return
        val tr: RtpTransceiver = conn.transceivers.firstOrNull { it.mediaType == MediaStreamTrack.MediaType.MEDIA_TYPE_VIDEO } ?: return
        val caps = factory.getRtpSenderCapabilities(MediaStreamTrack.MediaType.MEDIA_TYPE_VIDEO).codecs
        val h264 = caps.filter { it.name.equals("H264", ignoreCase = true) }
        val rest = caps.filterNot { it.name.equals("H264", ignoreCase = true) }
        if (h264.isNotEmpty()) runCatching { tr.setCodecPreferences(h264 + rest) }
    }

    fun setAnswer(sdp: String) {
        pc?.setRemoteDescription(SimpleSdp(), SessionDescription(SessionDescription.Type.ANSWER, sdp))
    }

    fun addRemoteIce(candidate: String, mid: String?, index: Int) {
        pc?.addIceCandidate(IceCandidate(mid, index, candidate))
    }

    /** Estadísticas del emisor para la calidad y el registro de validación. */
    fun collectStats(onStats: (SendStats) -> Unit) {
        val conn = pc ?: return onStats(SendStats())
        conn.getStats { report ->
            var out = SendStats()
            var rtt: Int? = null
            var loss: Double? = null
            report.statsMap.values.forEach { st ->
                val m = st.members
                when (st.type) {
                    "outbound-rtp" -> if (m["kind"] == "video") {
                        out = out.copy(
                            width = (m["frameWidth"] as? Number)?.toInt() ?: 0,
                            height = (m["frameHeight"] as? Number)?.toInt() ?: 0,
                            fps = (m["framesPerSecond"] as? Number)?.toDouble() ?: 0.0,
                            encoder = (m["encoderImplementation"] as? String).orEmpty(),
                            limitation = m["qualityLimitationReason"] as? String,
                        )
                        lastBytes((m["bytesSent"] as? Number)?.toLong())?.let { out = out.copy(bitrateKbps = it) }
                    }
                    "remote-inbound-rtp" -> if (m["kind"] == "video") {
                        loss = (m["fractionLost"] as? Number)?.toDouble()?.times(100)
                        (m["roundTripTime"] as? Number)?.toDouble()?.let { rtt = (it * 1000).toInt() }
                    }
                    "candidate-pair" -> if (m["state"] == "succeeded" && m["nominated"] == true && rtt == null) {
                        (m["currentRoundTripTime"] as? Number)?.toDouble()?.let { rtt = (it * 1000).toInt() }
                    }
                }
            }
            onStats(out.copy(rttMs = rtt, lossPct = loss))
        }
    }

    private var prevBytes = -1L
    private var prevAt = 0L

    private fun lastBytes(now: Long?): Int? {
        now ?: return null
        val t = System.nanoTime()
        val kbps = if (prevBytes >= 0 && t > prevAt) ((now - prevBytes) * 8_000_000L / (t - prevAt)).toInt() else null
        prevBytes = now; prevAt = t
        return kbps
    }

    fun closePeer() {
        pc?.close()
        pc = null
        videoSender = null
        prevBytes = -1
    }

    fun stopCapture() {
        capturer?.stopCapture()
        capturer = null
        videoTrack?.dispose(); videoTrack = null
        audioTrack?.dispose(); audioTrack = null
        videoSource?.dispose(); videoSource = null
        helper?.dispose(); helper = null
    }

    fun release() {
        closePeer()
        stopCapture()
        factory.dispose()
        eglBase.release()
    }
}

open class SimpleSdp : SdpObserver {
    override fun onCreateSuccess(desc: SessionDescription) {}
    override fun onSetSuccess() {}
    override fun onCreateFailure(error: String?) {}
    override fun onSetFailure(error: String?) {}
}
