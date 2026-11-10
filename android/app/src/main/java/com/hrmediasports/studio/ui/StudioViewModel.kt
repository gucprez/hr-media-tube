package com.hrmediasports.studio.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.hrmediasports.studio.content.AudioRules
import com.hrmediasports.studio.content.BreakPlayer
import com.hrmediasports.studio.content.Compat
import com.hrmediasports.studio.content.ContentEditor
import com.hrmediasports.studio.content.ContentState
import com.hrmediasports.studio.content.ContentStore
import com.hrmediasports.studio.content.InMemoryContentStore
import com.hrmediasports.studio.content.MatchAudioPolicy
import com.hrmediasports.studio.content.MediaItem
import com.hrmediasports.studio.content.OverlayKind
import com.hrmediasports.studio.content.PlayerEvent
import com.hrmediasports.studio.content.Playlist
import com.hrmediasports.studio.content.Step
import com.hrmediasports.studio.content.Template
import com.hrmediasports.studio.domain.MatchState
import java.util.UUID
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
    private val contentStore: ContentStore = InMemoryContentStore(),
    private val newId: () -> String = { UUID.randomUUID().toString() },
) : ViewModel() {

    private val breakPlayer = BreakPlayer { id -> _state.value.content.media(id) }

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
                tickBreak(AGE_TICK_MILLIS)
            }
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
            content = contentStore.load(),
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
    fun selectSource(id: String) {
        // DESCANSO no se prepara directo: abre el panel de contenidos. La salida al aire no cambia.
        if (id == SOURCE_BREAK) return openContentPanel()
        _state.update { s -> if (s.sources.any { it.id == id }) s.copy(previewSourceId = id) else s }
    }

    /** Acción explícita: envía a la salida lo que está en Vista previa. */
    fun takeToAir() {
        val s = _state.value
        if (!s.canTakeToAir) return
        if (s.previewSourceId == SOURCE_BREAK) startBreak(s.preparedPlaylist ?: return)
        else {
            // Poner otra fuente al aire termina el descanso y restaura el audio.
            _state.update { endBreak(it).copy(programSourceId = it.previewSourceId, monitorView = MonitorView.Program) }
        }
    }

    // ---------- Contenido para descansos ----------

    fun openContentPanel() = _state.update { it.copy(contentPanelOpen = true) }

    fun closeContentPanel() = _state.update { it.copy(contentPanelOpen = false, editingPlaylistId = null) }

    fun setContentTab(tab: ContentTab) = _state.update { it.copy(contentTab = tab, editingPlaylistId = null) }

    fun editPlaylist(id: String?) = _state.update { it.copy(editingPlaylistId = id) }

    /** Aplica un cambio a la biblioteca/listas y lo guarda. Solo cambian referencias: nunca archivos. */
    private fun editContent(f: (ContentState) -> ContentState) {
        _state.update { it.copy(content = f(it.content)) }
        contentStore.save(_state.value.content)
    }

    fun importMedia(items: List<MediaItem>) {
        if (items.isEmpty()) return
        val before = _state.value.content.library.size
        editContent { ContentEditor.addMedia(it, items) }
        val added = _state.value.content.library.size - before
        val bad = items.count { it.compat != Compat.Ok }
        post(buildString {
            append(if (added == 1) "1 archivo añadido a la biblioteca." else "$added archivos añadidos a la biblioteca.")
            if (added < items.size) append(" ${items.size - added} ya estaban.")
            if (bad > 0) append(" $bad no son compatibles: no podrán mostrarse.")
        })
    }

    fun removeMedia(id: String) = editContent { ContentEditor.removeMedia(it, id) }

    fun createPlaylist(template: Template) {
        val id = newId()
        editContent { ContentEditor.createFromTemplate(it, template, id) }
        _state.update { it.copy(editingPlaylistId = id, contentTab = ContentTab.Lists) }
    }

    fun removePlaylist(id: String) {
        editContent { ContentEditor.removePlaylist(it, id) }
        _state.update { it.copy(editingPlaylistId = null, preparedPlaylistId = it.preparedPlaylistId.takeIf { p -> p != id }) }
    }

    fun renamePlaylist(id: String, name: String) = editContent { ContentEditor.rename(it, id, name) }
    fun addEntry(playlistId: String, mediaId: String) = editContent { ContentEditor.addEntry(it, playlistId, mediaId, newId()) }
    fun removeEntry(playlistId: String, entryId: String) = editContent { ContentEditor.removeEntry(it, playlistId, entryId) }
    fun moveEntry(playlistId: String, entryId: String, delta: Int) = editContent { ContentEditor.moveEntry(it, playlistId, entryId, delta) }
    fun toggleEntryScoreboard(playlistId: String, entryId: String) = editContent { ContentEditor.toggleScoreboard(it, playlistId, entryId) }
    fun setImageSeconds(playlistId: String, seconds: Int) = editContent { ContentEditor.setImageSeconds(it, playlistId, seconds) }
    fun setLoop(playlistId: String, on: Boolean) = editContent { ContentEditor.setLoop(it, playlistId, on) }
    fun setAutoReturn(playlistId: String, on: Boolean) = editContent { ContentEditor.setAutoReturn(it, playlistId, on) }
    fun setSlateText(playlistId: String, text: String) = editContent { ContentEditor.setSlateText(it, playlistId, text) }
    fun addOverlay(mediaId: String, kind: OverlayKind) = editContent { ContentEditor.addOverlay(it, mediaId, kind, newId()) }
    fun toggleOverlay(id: String) = editContent { ContentEditor.toggleOverlay(it, id) }
    fun removeOverlay(id: String) = editContent { ContentEditor.removeOverlay(it, id) }

    fun setAudioPolicy(policy: MatchAudioPolicy) {
        editContent { ContentEditor.setAudioPolicy(it, policy) }
        // Si hay un descanso al aire, la nueva política se aplica sobre la mezcla guardada.
        _state.update { s -> if (s.breakOnAir && s.savedMix != null) s.copy(audioMix = AudioRules.duringBreak(s.savedMix, policy)) else s }
    }

    /** Cámara a la que vuelve «Volver al partido». */
    fun setReturnSource(id: String) = _state.update { s ->
        if (s.sources.any { it.id == id && it.kind == SourceKind.Camera }) s.copy(returnSourceId = id) else s
    }

    /** Seleccionar una lista la PREPARA en Vista previa. No cambia lo que está al aire ni envía audio. */
    fun preparePlaylist(id: String) {
        val s = _state.value
        val p = s.content.playlist(id) ?: return
        val issues = ContentEditor.issues(s.content, p)
        _state.update {
            it.copy(
                preparedPlaylistId = id, previewSourceId = SOURCE_BREAK, contentPanelOpen = false, editingPlaylistId = null,
                monitorView = MonitorView.Preview,
                message = if (issues.isEmpty()) "«${p.name}» preparada en Vista previa." else
                    "«${p.name}» preparada con ${issues.size} aviso(s): ${issues.first()}",
            )
        }
    }

    private fun startBreak(playlist: Playlist) {
        val step = breakPlayer.start(playlist)
        _state.update { s ->
            // Si en este momento hay una cámara al aire, es a la que se volverá.
            val program = s.sources.firstOrNull { it.id == s.programSourceId }
            val back = if (program?.kind == SourceKind.Camera) program.id else s.returnSourceId
            val previous = s.savedMix ?: s.audioMix          // no se pisa la mezcla guardada si ya había un descanso
            s.copy(
                programSourceId = SOURCE_BREAK, monitorView = MonitorView.Program, returnSourceId = back,
                breakState = step.state, breakNextTitle = breakPlayer.nextTitle(step.state),
                savedMix = previous, audioMix = AudioRules.duringBreak(previous, s.content.audioPolicy),
            )
        }
        handle(step.events)
    }

    /** Estado sin descanso: detiene la secuencia (y con ella el audio del anuncio) y restaura la mezcla anterior. */
    private fun endBreak(s: StudioUiState): StudioUiState =
        s.copy(breakState = null, breakNextTitle = "", audioMix = s.savedMix ?: s.audioMix, savedMix = null)

    /** Volver al partido: regresa a la cámara elegida, detiene el anuncio y restaura el audio. */
    fun backToMatch() = _state.update { s ->
        if (s.programSourceId != SOURCE_BREAK) s
        else endBreak(s).copy(programSourceId = s.returnSourceId, monitorView = MonitorView.Program)
    }

    private fun applyStep(step: Step) {
        _state.update { s -> if (s.breakState == null) s else s.copy(breakState = step.state, breakNextTitle = breakPlayer.nextTitle(step.state)) }
        handle(step.events)
    }

    private fun handle(events: List<PlayerEvent>) {
        events.forEach { e ->
            when (e) {
                is PlayerEvent.Notice -> post(e.text)
                PlayerEvent.AutoReturn -> backToMatch()
            }
        }
    }

    private fun tickBreak(dtMs: Long) {
        val b = _state.value.breakState ?: return
        if (!_state.value.breakOnAir) return
        applyStep(breakPlayer.tick(b, dtMs))
    }

    fun breakTogglePause() { _state.value.breakState?.let { applyStep(breakPlayer.togglePause(it)) } }
    fun breakNext() { _state.value.breakState?.let { applyStep(breakPlayer.next(it)) } }
    fun breakStop() { _state.value.breakState?.let { applyStep(breakPlayer.stop(it)) } }

    /** El motor audiovisual (entrega 2) avisará así de un archivo que falló en plena reproducción. */
    fun breakItemFailed(reason: String) { _state.value.breakState?.let { applyStep(breakPlayer.itemFailed(it, reason)) } }

    fun setMonitorView(view: MonitorView) = _state.update { it.copy(monitorView = view) }

    fun toggleReplaysTray() = _state.update { it.copy(replaysTrayOpen = !it.replaysTrayOpen) }

    fun openPanel(panel: StudioPanel) = _state.update { it.copy(openPanel = panel) }

    fun closePanel() = _state.update { it.copy(openPanel = null) }

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
