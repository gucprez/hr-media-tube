package com.hrmediasports.studio.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.DemoScoreboardSource
import com.hrmediasports.studio.engine.MediaEngine
import com.hrmediasports.studio.engine.ScoreboardSnapshot
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.protocol.readingAgeMillis
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

const val SOURCE_CAMERA_1 = "camera-1"
const val SOURCE_CAMERA_2 = "camera-2"
const val SOURCE_BREAK = "break"
const val SOURCE_RESULT = "result"

/** Pulso con el que se refresca la antigüedad de la última lectura. */
const val AGE_TICK_MILLIS = 250L

fun defaultAgeTicks(): Flow<Unit> = flow {
    while (true) {
        delay(AGE_TICK_MILLIS)
        emit(Unit)
    }
}

class StudioViewModel(
    private val engine: MediaEngine = DemoMediaEngine,
    private val scoreboardSource: ScoreboardSource = DemoScoreboardSource,
    private val clock: () -> Long = System::currentTimeMillis,
    ageTicks: Flow<Unit> = defaultAgeTicks(),
    /** null = interfaz de demostración; con valor = Estudio real (cámara, enlace y transmisión). */
    private val live: StudioLive? = null,
) : ViewModel() {

    private var latest: ScoreboardSnapshot = scoreboardSource.snapshot.value
    private var manualMatch: MatchState? = null
    private var manualSinceMillis: Long = 0L

    private val _state = MutableStateFlow(initialState())
    val state: StateFlow<StudioUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            scoreboardSource.snapshot.collect { snap ->
                latest = snap
                refreshScoreboard()
            }
        }
        // La antigüedad avanza aunque no lleguen datos: así "desactualizada" aparece sola.
        viewModelScope.launch {
            ageTicks.collect {
                if (latest.lastReceivedAtMillis != null) refreshScoreboard()
                if (live != null) watchRemoteOnAir()
            }
        }
        if (live != null) {
            // Sin lectura todavía: el marcador del video queda oculto (nunca un 0–0 inventado).
            pushOverlay()
            viewModelScope.launch {
                live.engine.collect { st ->
                    _state.update { it.copy(engine = st, streaming = st.streamingState(),
                        recording = if (st.recording) RecordingState.Recording else RecordingState.Stopped) }
                }
            }
            viewModelScope.launch {
                live.cameras.collect { cams ->
                    _state.update { s -> s.copy(remoteCameras = cams, connectedCameras = cams.count { it.connected }) }
                }
            }
        }
    }

    // ---------------- Estudio real ----------------

    private fun pushOverlay() {
        val l = live ?: return
        val st = _state.value
        l.setOverlay(overlayScore(st.scoreboard.match, st.liveSettings ?: l.settingsStore.load(), st.overlayVisible))
    }

    /** Cámara remota al aire sin imagen → vuelve sola a la Cámara 1 y avisa (nunca queda una imagen congelada al aire). */
    private fun watchRemoteOnAir() {
        val l = live ?: return
        val st = _state.value
        if (st.programSourceId != SOURCE_CAMERA_2) return
        val cam = st.remoteCameras.getOrNull(0)
        val age = l.remoteAgeMs(0)
        if (cam?.connected != true || age == null || age > REMOTE_FREEZE_FALLBACK_MS) {
            l.selectVideo(0)
            l.setSlate(com.hrmediasports.studio.live.Slate.None)
            _state.update { it.copy(programSourceId = SOURCE_CAMERA_1, message = "Se cortó la Cámara 2: volví a la Cámara 1 automáticamente.") }
        }
    }

    fun toggleOverlay() { _state.update { it.copy(overlayVisible = !it.overlayVisible) }; pushOverlay() }
    fun openDialog(d: StudioDialog) = _state.update { it.copy(dialog = d) }
    fun closeDialog() = _state.update { it.copy(dialog = null) }

    fun saveLiveSettings(s: com.hrmediasports.studio.live.LiveSettings) {
        val l = live ?: return
        l.settingsStore.save(s)
        _state.update { it.copy(liveSettings = s, dialog = null) }
        pushOverlay()
    }

    fun confirmStopStream() {
        live?.stopLive()
        _state.update { it.copy(dialog = null) }
    }

    private fun initialState(): StudioUiState {
        val caps = engine.capabilities
        if (live != null) return StudioUiState(
            sources = listOf(
                VideoSource(SOURCE_CAMERA_1, SourceKind.Camera, "Cámara 1"),
                VideoSource(SOURCE_CAMERA_2, SourceKind.Camera, "Cámara 2"),
                VideoSource(SOURCE_BREAK, SourceKind.Break, "Descanso"),
                VideoSource(SOURCE_RESULT, SourceKind.Result, "Resultado"),
            ),
            previewSourceId = SOURCE_CAMERA_1,
            // La cámara de este teléfono sale al aire desde el inicio (es lo que verá quien entre a la transmisión).
            programSourceId = SOURCE_CAMERA_1,
            scoreboard = buildScoreboard(),
            recording = RecordingState.Stopped,
            streaming = StreamingState.Offline,
            replays = emptyList(),
            replayBufferAvailable = false,
            connectedCameras = 0,
            remoteCamerasAvailable = true,
            isDemo = false,
            live = true,
            liveSettings = live.settingsStore.load(),
        )
        return StudioUiState(
            sources = listOf(
                VideoSource(SOURCE_CAMERA_1, SourceKind.Camera, "Cámara 1"),
                VideoSource(SOURCE_CAMERA_2, SourceKind.Camera, "Cámara 2"),
                VideoSource(SOURCE_BREAK, SourceKind.Break, "Descanso"),
                VideoSource(SOURCE_RESULT, SourceKind.Result, "Resultado"),
            ),
            previewSourceId = SOURCE_CAMERA_1,
            programSourceId = null,
            scoreboard = buildScoreboard(),
            recording = if (caps.canRecord) RecordingState.Stopped else RecordingState.Unavailable,
            streaming = if (caps.canStream) StreamingState.Offline else StreamingState.Unavailable,
            replays = emptyList(),
            replayBufferAvailable = caps.canBufferReplays,
            // Sin recepción remota no puede haber cámaras conectadas.
            connectedCameras = 0,
            remoteCamerasAvailable = caps.canReceiveRemoteCameras,
            isDemo = true,
        )
    }

    private fun buildScoreboard(): ScoreboardUiState {
        val manual = manualMatch
        return ScoreboardUiState(
            match = manual ?: latest.match,
            origin = if (manual != null) ReadingOrigin.Manual else latest.origin,
            link = latest.link,
            ageMillis = latest.lastReceivedAtMillis?.let { readingAgeMillis(clock(), it) },
            confidence = if (manual != null) null else latest.confidence,
            newerReadingAvailable = manual != null &&
                (latest.lastReceivedAtMillis ?: Long.MIN_VALUE) > manualSinceMillis,
        )
    }

    private fun refreshScoreboard() {
        val before = _state.value.scoreboard.match
        _state.update { it.copy(scoreboard = buildScoreboard()) }
        if (live != null && _state.value.scoreboard.match != before) pushOverlay()
    }

    fun navigate(destination: Destination) = _state.update { it.copy(destination = destination) }

    /** Seleccionar una fuente SOLO la prepara en Vista previa. */
    fun selectSource(id: String) = _state.update { s ->
        if (s.sources.any { it.id == id }) s.copy(previewSourceId = id) else s
    }

    /** Acción explícita: envía a la salida lo que está en Vista previa. */
    fun takeToAir() {
        val s = _state.value
        if (!s.canTakeToAir) return
        val l = live
        if (l != null) {
            when (s.previewSourceId) {
                SOURCE_CAMERA_1 -> { l.selectVideo(0); l.setSlate(com.hrmediasports.studio.live.Slate.None) }
                SOURCE_CAMERA_2 -> {
                    val cam = s.remoteCameras.getOrNull(0)
                    if (cam?.connected != true || !cam.hasVideo) { post("La Cámara 2 no está conectada. Conéctala con el QR (botón CONECTAR)."); return }
                    if (!l.selectVideo(1)) { post("No se pudo cambiar a la Cámara 2."); return }
                    l.setSlate(com.hrmediasports.studio.live.Slate.None)
                }
                SOURCE_BREAK -> l.setSlate(com.hrmediasports.studio.live.Slate.Break)
                SOURCE_RESULT -> l.setSlate(com.hrmediasports.studio.live.Slate.Result)
            }
        }
        _state.update { it.copy(programSourceId = it.previewSourceId, monitorView = MonitorView.Program) }
    }

    fun setMonitorView(view: MonitorView) = _state.update { it.copy(monitorView = view) }

    fun toggleReplaysTray() = _state.update { it.copy(replaysTrayOpen = !it.replaysTrayOpen) }

    fun openPanel(panel: StudioPanel) = _state.update { it.copy(openPanel = panel) }

    fun closePanel() = _state.update { it.copy(openPanel = null) }

    fun onRecordClick() {
        val l = live
        if (l != null) {
            if (_state.value.recording == RecordingState.Recording) l.stopRecording()
            else l.startRecording()?.let(::post)
            return
        }
        if (!engine.capabilities.canRecord) {
            post("Grabación no disponible: esta versión es solo una demostración de la interfaz.")
        }
    }

    fun onStreamClick() {
        val l = live
        if (l != null) {
            val st = _state.value
            if (st.streaming == StreamingState.Live || st.streaming == StreamingState.Connecting) { openDialog(StudioDialog.ConfirmStopStream); return }
            val settings = st.liveSettings ?: l.settingsStore.load()
            val problem = settings.destinations.firstNotNullOfOrNull { d -> d.problem()?.let { "${d.platform.label}: $it" } }
            if (settings.readyCount == 0 || problem != null) {
                _state.update { it.copy(dialog = StudioDialog.StreamSetup, message = problem ?: "Configura YouTube y/o Facebook para transmitir.") }
                return
            }
            l.goLive(settings)?.let(::post)
            return
        }
        if (!engine.capabilities.canStream) {
            post("Transmisión no disponible: esta versión es solo una demostración de la interfaz.")
        }
    }

    fun onSaveReplayClick() {
        if (!engine.capabilities.canBufferReplays) {
            post("Repeticiones no disponibles: el búfer de video aún no está implementado.")
        }
    }

    fun openCorrection() = _state.update {
        it.copy(correctionDraft = CorrectionDraft.from(it.scoreboard.match))
    }

    fun updateDraft(transform: (CorrectionDraft) -> CorrectionDraft) = _state.update { s ->
        s.correctionDraft?.let { s.copy(correctionDraft = transform(it)) } ?: s
    }

    fun dismissCorrection() = _state.update { it.copy(correctionDraft = null) }

    fun applyCorrection() {
        val match = _state.value.correctionDraft?.toMatchState() ?: return
        manualMatch = match
        manualSinceMillis = clock()
        _state.update { it.copy(correctionDraft = null, scoreboard = buildScoreboard()) }
        pushOverlay()
    }

    fun discardCorrection() {
        manualMatch = null
        _state.update { it.copy(correctionDraft = null, scoreboard = buildScoreboard()) }
        pushOverlay()
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }

    private fun post(text: String) = _state.update { it.copy(message = text) }
}
