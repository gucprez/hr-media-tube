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
            ageTicks.collect { if (latest.lastReceivedAtMillis != null) refreshScoreboard() }
        }
    }

    private fun initialState(): StudioUiState {
        val caps = engine.capabilities
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

    private fun refreshScoreboard() = _state.update { it.copy(scoreboard = buildScoreboard()) }

    fun navigate(destination: Destination) = _state.update { it.copy(destination = destination) }

    /** Seleccionar una fuente SOLO la prepara en Vista previa. */
    fun selectSource(id: String) = _state.update { s ->
        if (s.sources.any { it.id == id }) s.copy(previewSourceId = id) else s
    }

    /** Acción explícita: envía a la salida lo que está en Vista previa. */
    fun takeToAir() = _state.update { s ->
        if (s.canTakeToAir) s.copy(programSourceId = s.previewSourceId) else s
    }

    fun onRecordClick() {
        if (!engine.capabilities.canRecord) {
            post("Grabación no disponible: esta versión es solo una demostración de la interfaz.")
        }
    }

    fun onStreamClick() {
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
    }

    fun discardCorrection() {
        manualMatch = null
        _state.update { it.copy(correctionDraft = null, scoreboard = buildScoreboard()) }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }

    private fun post(text: String) = _state.update { it.copy(message = text) }
}
