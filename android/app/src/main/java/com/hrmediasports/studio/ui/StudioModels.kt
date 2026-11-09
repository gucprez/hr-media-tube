package com.hrmediasports.studio.ui

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
) {
    val previewSource: VideoSource? get() = sources.firstOrNull { it.id == previewSourceId }
    val programSource: VideoSource? get() = sources.firstOrNull { it.id == programSourceId }
    val canTakeToAir: Boolean get() = previewSourceId != null && previewSourceId != programSourceId
}
