package com.hrmediasports.studio.camera

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.viewModelScope
import com.hrmediasports.link.Answer
import com.hrmediasports.link.IceCandidateMsg
import com.hrmediasports.link.LinkMessage
import com.hrmediasports.link.Offer
import com.hrmediasports.link.OnAir
import com.hrmediasports.link.PairingInfo
import com.hrmediasports.link.Telemetry
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import org.webrtc.IceCandidate
import org.webrtc.PeerConnection

enum class Phase { NeedPairing, Scanning, Connecting, WaitingAcceptance, Ready, Streaming }

enum class LinkStatus(val label: String) {
    Disconnected("Sin conexión"),
    Connecting("Conectando…"),
    Connected("Conectado al estudio"),
    Reconnecting("Reconectando…"),
}

data class CameraUiState(
    val phase: Phase = Phase.NeedPairing,
    val link: LinkStatus = LinkStatus.Disconnected,
    val studio: String? = null,
    val deviceName: String = "",
    /** AL AIRE: aviso recibido del estudio (la fuente de este teléfono está en la salida). */
    val onAir: Boolean = false,
    val quality: Quality = Quality.Unknown,
    val stats: SendStats = SendStats(),
    val hardwareEncoder: Boolean? = null,
    val lens: Lens = Lens.Back,
    val caps: CameraCaps = CameraCaps(),
    val zoom: Float = 1f,
    val exposure: Int = 0,
    val audioOn: Boolean = true,
    val notice: String? = null,
    val error: String? = null,
)

class RemoteCameraViewModel(app: Application) : AndroidViewModel(app) {
    private val store = DeviceStore(app)
    private val link = LinkClient(viewModelScope, store.deviceId, store.deviceName)
    val sender = CameraSender(app)

    private val _state = MutableStateFlow(CameraUiState(deviceName = store.deviceName))
    val state: StateFlow<CameraUiState> = _state.asStateFlow()

    private var tickJob: Job? = null
    private var restartJob: Job? = null
    private var owner: LifecycleOwner? = null
    private var linkAuthenticated = false

    init {
        viewModelScope.launch { link.events.collect(::onLinkEvent) }
        // Ya vinculado: se reconecta solo, con el mismo deviceId (el estudio no crea otra fuente).
        store.binding?.let {
            _state.update { s -> s.copy(phase = Phase.Ready, link = LinkStatus.Connecting, studio = "${it.host}:${it.port}") }
            link.connectWithToken(it)
        }
    }

    // ---------- Vinculación ----------

    fun startScanning() = _state.update { it.copy(phase = Phase.Scanning, error = null, notice = null) }

    fun cancelScanning() = _state.update { if (it.phase == Phase.Scanning) it.copy(phase = Phase.NeedPairing) else it }

    /** Texto de un QR o enlace pegado a mano. */
    fun onPairingText(text: String) {
        val p = PairingInfo.parse(text.trim())
        if (p == null) {
            _state.update { it.copy(phase = Phase.NeedPairing, error = "Ese código no es un QR de vinculación de HR Media Sports.") }
        } else onPairing(p)
    }

    fun onPairing(p: PairingInfo) {
        _state.update { it.copy(phase = Phase.Connecting, link = LinkStatus.Connecting, studio = "${p.host}:${p.port}", error = null, notice = null) }
        link.connectWithPairing(p.host, p.port, p.secret)
    }

    fun rename(name: String) {
        store.deviceName = name
        _state.update { it.copy(deviceName = store.deviceName) }
    }

    /** Olvida el estudio: hará falta escanear otro QR. */
    fun unpair() {
        stopStreaming()
        link.close()
        store.binding = null
        linkAuthenticated = false
        _state.update { CameraUiState(deviceName = store.deviceName, notice = "Dispositivo desvinculado.") }
    }

    private fun onLinkEvent(e: LinkEvent) {
        when (e) {
            is LinkEvent.Authenticated -> {
                linkAuthenticated = true
                _state.update {
                    it.copy(
                        link = LinkStatus.Connected, error = null,
                        phase = when {
                            e.state != "accepted" -> Phase.WaitingAcceptance
                            it.phase == Phase.Streaming -> Phase.Streaming
                            else -> Phase.Ready
                        },
                    )
                }
                // Tras una reconexión durante la transmisión se hace una oferta nueva: el estudio cierra la anterior.
                if (e.state == "accepted" && _state.value.phase == Phase.Streaming) beginPeer()
            }
            LinkEvent.Accepted -> {
                link.derivedBinding()?.let { store.binding = it }      // se guarda el token derivado (nunca se transmitió)
                // La oferta de media ya se hizo al autenticar (reconexión) o se hará al iniciar el envío (primer vínculo).
                _state.update { it.copy(phase = if (it.phase == Phase.Streaming) Phase.Streaming else Phase.Ready, notice = "El estudio aceptó este dispositivo.") }
            }
            is LinkEvent.Rejected -> {
                store.binding = null
                stopStreaming()
                _state.update { CameraUiState(deviceName = store.deviceName, error = "El estudio desconectó este dispositivo. Escanea un QR para vincularlo de nuevo.") }
            }
            is LinkEvent.Lost -> {
                linkAuthenticated = false
                _state.update { it.copy(link = LinkStatus.Reconnecting, onAir = false) }
            }
            is LinkEvent.Fatal -> {
                linkAuthenticated = false
                val unpair = e.code in setOf("unknown_device", "bad_proof", "pairing_expired", "device_exists", "locked")
                if (unpair) { store.binding = null; stopStreaming() }
                _state.update {
                    CameraUiState(deviceName = store.deviceName, error = fatalText(e.code)).let { fresh ->
                        if (unpair) fresh else it.copy(link = LinkStatus.Disconnected, error = fatalText(e.code))
                    }
                }
            }
            is LinkEvent.Message -> onMessage(e.message)
        }
    }

    private fun fatalText(code: String) = when (code) {
        "pairing_expired" -> "El QR venció. Pide al estudio uno nuevo."
        "unknown_device" -> "El estudio se reinició o no reconoce este dispositivo. Escanea el QR de nuevo."
        "device_exists" -> "Este dispositivo ya estaba vinculado y perdió su credencial. Quítalo en el estudio y escanea de nuevo."
        "locked" -> "Demasiados intentos fallidos. Crea el estudio de nuevo."
        "bad_proof" -> "La credencial no es válida. Escanea el QR de nuevo."
        "server_not_authentic" -> "El servidor no demostró ser el estudio. Conexión cancelada."
        "unsupported_version" -> "Esta app y el estudio usan versiones distintas del protocolo."
        else -> "No se pudo conectar ($code)."
    }

    private fun onMessage(m: LinkMessage) {
        when (m) {
            is Answer -> sender.setAnswer(m.sdp)
            is IceCandidateMsg -> sender.addRemoteIce(m.candidate, m.sdpMid, m.sdpMLineIndex)
            is OnAir -> _state.update { it.copy(onAir = m.active) }
            else -> Unit
        }
    }

    // ---------- Transmisión ----------

    /** Inicia captura y envío. Los permisos (cámara y, si se desea, micrófono) ya deben estar concedidos. */
    fun startStreaming(owner: LifecycleOwner, withAudio: Boolean) {
        this.owner = owner
        val s = _state.value
        if (s.phase != Phase.Ready) return
        _state.update { it.copy(phase = Phase.Streaming, audioOn = withAudio, notice = null) }
        sender.startCapture(owner, _state.value.lens, withAudio) { caps ->
            _state.update { it.copy(caps = caps, zoom = caps.minZoom, exposure = 0) }
        }
        if (linkAuthenticated) beginPeer()
        startTicker()
    }

    fun stopStreaming() {
        tickJob?.cancel(); restartJob?.cancel()
        sender.closePeer()
        sender.stopCapture()
        link.send(com.hrmediasports.link.Bye("stop"))
        _state.update { if (it.phase == Phase.Streaming) it.copy(phase = Phase.Ready, onAir = false, stats = SendStats(), quality = Quality.Unknown) else it }
    }

    private fun beginPeer() {
        sender.startPeer(object : CameraSender.PeerEvents {
            override fun onOffer(sdp: String) { link.send(Offer(sdp)) }
            override fun onIce(c: IceCandidate) { link.send(IceCandidateMsg(c.sdp, c.sdpMid, c.sdpMLineIndex)) }
            override fun onConnectionState(state: PeerConnection.PeerConnectionState) {
                // Si la conexión de media cae pero el canal de señalización sigue, se renegocia con una oferta nueva.
                if (state == PeerConnection.PeerConnectionState.FAILED) scheduleRestart()
            }
        })
    }

    private fun scheduleRestart() {
        restartJob?.cancel()
        restartJob = viewModelScope.launch {
            delay(1_000)
            if (linkAuthenticated && _state.value.phase == Phase.Streaming) beginPeer()
        }
    }

    private fun startTicker() {
        tickJob?.cancel()
        tickJob = viewModelScope.launch {
            var n = 0
            while (true) {
                delay(1_000)
                sender.collectStats { st ->
                    val hw = QualityMapper.isHardwareEncoder(st.encoder)
                    _state.update { it.copy(stats = st, quality = QualityMapper.quality(st, TARGET_FPS), hardwareEncoder = hw ?: it.hardwareEncoder) }
                }
                if (++n % 2 == 0) sendTelemetry()
            }
        }
    }

    private fun sendTelemetry() {
        val s = _state.value
        val h = DeviceHealth.read(getApplication())
        link.send(
            Telemetry(
                t = System.currentTimeMillis(), width = s.stats.width, height = s.stats.height, fps = s.stats.fps,
                bitrateKbps = s.stats.bitrateKbps, encoder = s.stats.encoder, hardwareEncoder = s.hardwareEncoder,
                rttMs = s.stats.rttMs, lossPct = s.stats.lossPct, limitation = s.stats.limitation,
                batteryPct = h.batteryPct, batteryTempC = h.batteryTempC, thermalStatus = h.thermalStatus,
                chargeCounterUah = h.chargeCounterUah, currentNowUa = h.currentNowUa,
                lens = s.lens.name, zoom = s.zoom.toDouble(), audioOn = s.audioOn,
            ),
        )
    }

    // ---------- Controles ----------

    fun setLens(lens: Lens) {
        sender.switchLens(lens)
        _state.update { it.copy(lens = lens, zoom = 1f, exposure = 0) }
    }

    fun setZoom(z: Float) { sender.setZoom(z); _state.update { it.copy(zoom = z) } }

    fun setExposure(i: Int) { sender.setExposure(i); _state.update { it.copy(exposure = i) } }

    fun focusAt(x: Float, y: Float) = sender.focusAt(x, y)

    fun setAudio(on: Boolean) { sender.setAudioEnabled(on); _state.update { it.copy(audioOn = on) } }

    fun consumeNotice() = _state.update { it.copy(notice = null) }

    override fun onCleared() {
        tickJob?.cancel()
        link.close()
        sender.release()
    }
}
