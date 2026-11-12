package com.hrmediasports.studio.ui

import com.hrmediasports.studio.content.AudioMix
import com.hrmediasports.studio.content.BreakState
import com.hrmediasports.studio.content.ContentState
import com.hrmediasports.studio.content.MediaType
import com.hrmediasports.studio.content.Showing
import com.hrmediasports.studio.content.Playlist
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.domain.parseClock
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.protocol.isStale

enum class Destination(val title: String) {
    Studio("Estudio"),
    Cameras("Cámaras"),
    Graphics("Gráficos"),
    Replays("Repeticiones"),
    Settings("Ajustes"),
}

enum class SourceKind { Camera, Break, Result }

data class VideoSource(val id: String, val kind: SourceKind, val label: String)

enum class RecordingState { Unavailable, Stopped, Recording, Error }

enum class StreamingState { Unavailable, Offline, Connecting, Live, Error }

data class ReplayClip(val id: String, val label: String, val durationSeconds: Int)

enum class MonitorView { Preview, Program }

/** Paneles específicos que se abren aparte cuando la ventana es baja. */
enum class StudioPanel { Replays, Scoreboard }

data class ScoreboardUiState(
    /** null = nunca se recibieron datos (se muestra «—»). */
    val match: MatchState?,
    val origin: ReadingOrigin,
    val link: LinkState,
    /** Antigüedad de la última lectura en ms; null si no hay lecturas. */
    val ageMillis: Long?,
    val confidence: Float?,
    /** Hay una lectura del lector posterior a la corrección manual vigente. */
    val newerReadingAvailable: Boolean = false,
)

/** Rótulo que acompaña a los valores del marcador para que nunca parezcan datos vivos si no lo son. */
fun ScoreboardUiState.dataLabel(): String? = when {
    origin == ReadingOrigin.Manual -> "CORREGIDO A MANO"
    origin == ReadingOrigin.Demo -> "DATOS DEMO"
    ageMillis != null && (link != LinkState.Connected || isStale(ageMillis)) -> "DESACTUALIZADO"
    else -> null
}

/** Borrador editable de la corrección manual; se valida al aplicar. */
data class CorrectionDraft(
    val home: Int,
    val away: Int,
    val clockText: String,
    val period: Int,
    val possession: Int,
) {
    val clockSeconds: Int? get() = parseClock(clockText)
    val isValid: Boolean get() = clockSeconds != null

    fun toMatchState(): MatchState? {
        val clock = clockSeconds ?: return null
        return MatchState(
            homePoints = home.coerceIn(0, MatchState.MAX_POINTS),
            awayPoints = away.coerceIn(0, MatchState.MAX_POINTS),
            clockSeconds = clock,
            period = period.coerceIn(1, MatchState.MAX_PERIOD),
            possessionSeconds = possession.coerceIn(0, MatchState.MAX_POSSESSION_SECONDS),
        )
    }

    companion object {
        fun from(match: MatchState?): CorrectionDraft {
            val m = match ?: MatchState(0, 0, 0, 1, 0)
            return CorrectionDraft(
                home = m.homePoints,
                away = m.awayPoints,
                clockText = formatClock(m.clockSeconds),
                period = m.period,
                possession = m.possessionSeconds,
            )
        }
    }
}

data class StudioUiState(
    val destination: Destination = Destination.Studio,
    val sources: List<VideoSource>,
    /** Fuente preparada en Vista previa (selección de trabajo, borde naranja). */
    val previewSourceId: String?,
    /** Fuente en la salida (borde rojo). */
    val programSourceId: String?,
    val scoreboard: ScoreboardUiState,
    val recording: RecordingState,
    val streaming: StreamingState,
    val replays: List<ReplayClip>,
    val replayBufferAvailable: Boolean,
    val connectedCameras: Int,
    val remoteCamerasAvailable: Boolean,
    val isDemo: Boolean,
    val correctionDraft: CorrectionDraft? = null,
    /** Qué monitor se ve cuando la ventana no deja espacio para ambos. Al aire tiene prioridad. */
    val monitorView: MonitorView = MonitorView.Program,
    val replaysTrayOpen: Boolean = false,
    val openPanel: StudioPanel? = null,
    val message: String? = null,
    // ---- Contenido para descansos (módulo independiente del reconocimiento de pizarra) ----
    val content: ContentState = ContentState(),
    val contentPanelOpen: Boolean = false,
    val contentTab: ContentTab = ContentTab.Lists,
    val editingPlaylistId: String? = null,
    /** Lista preparada en Vista previa (aún NO está al aire). */
    val preparedPlaylistId: String? = null,
    /** No nulo mientras un descanso está al aire. */
    val breakState: BreakState? = null,
    /** Qué vendrá después de lo que se muestra (para el operador). */
    val breakNextTitle: String = "",
    /** Cámara a la que regresa «Volver al partido». */
    val returnSourceId: String = SOURCE_CAMERA_1,
    /** Mezcla de audio deseada hacia la salida. Sin motor audiovisual, es solo el estado que se aplicaría. */
    val audioMix: AudioMix = AudioMix(),
    /** Mezcla anterior al descanso, para restaurarla al volver al partido. */
    val savedMix: AudioMix? = null,
    /** false = no hay motor que reproduzca: la secuencia es una demostración y nada sale en la emisión. */
    val breakEngineAvailable: Boolean = false,
) {
    val previewSource: VideoSource? get() = sources.firstOrNull { it.id == previewSourceId }
    val programSource: VideoSource? get() = sources.firstOrNull { it.id == programSourceId }
    val preparedPlaylist: Playlist? get() = content.playlist(preparedPlaylistId)
    val breakOnAir: Boolean get() = breakState != null && programSourceId == SOURCE_BREAK
    val canTakeToAir: Boolean
        get() = when {
            previewSourceId == null -> false
            previewSourceId != SOURCE_BREAK -> previewSourceId != programSourceId
            preparedPlaylist == null -> false
            // Un descanso ya al aire solo se "retoma" si se preparó otra lista o si la misma cambió desde que salió.
            else -> programSourceId != SOURCE_BREAK || breakState?.playlist != preparedPlaylist
        }
}

enum class ContentTab(val label: String) { Lists("Listas"), Library("Biblioteca"), Sponsors("Patrocinadores"), Audio("Audio") }


/** Lo que un monitor muestra cuando su fuente es DESCANSO. */
data class BreakView(
    val headline: String,
    val detail: String,
    val uri: String? = null,
    val isVideo: Boolean = false,
    val slate: Boolean = false,
    val scoreboard: Boolean = false,
)

/** Vista previa del descanso: la lista preparada (aún no reproduce nada). */
fun StudioUiState.previewBreakView(): BreakView? {
    if (previewSourceId != SOURCE_BREAK) return null
    val p = preparedPlaylist ?: return BreakView("DESCANSO", "Elige una lista en el panel de contenidos", slate = true)
    val first = p.entries.firstOrNull()?.let { content.media(it.mediaId) }
    val n = if (p.entries.size == 1) "1 elemento" else "${p.entries.size} elementos"
    return BreakView(
        headline = p.name.trim().ifBlank { "Lista sin nombre" }.uppercase(),
        detail = if (first == null) "$n · placa «${p.slateText}»" else "$n · empieza con ${first.name}",
        uri = first?.uri, isVideo = first?.type == MediaType.Video, slate = first == null,
        scoreboard = p.entries.firstOrNull()?.showScoreboard == true,
    )
}

/** Al aire: el elemento actual de la secuencia, la placa de respaldo o la de espera. */
fun StudioUiState.programBreakView(): BreakView? {
    if (programSourceId != SOURCE_BREAK) return null
    val b = breakState ?: return BreakView("DESCANSO", "Sin lista", slate = true)
    return when (val s = b.showing) {
        is Showing.Item -> BreakView(s.media.name, s.media.type.label + if (b.paused) " · en pausa" else "", s.media.uri, s.media.type == MediaType.Video, scoreboard = s.entry.showScoreboard)
        is Showing.Fallback -> BreakView("PLACA DE RESPALDO", "«${s.mediaName}»: ${s.reason}", slate = true)
        is Showing.Hold -> BreakView(s.text.uppercase(), "Placa de espera", slate = true)
    }
}
