package com.hrmediasports.studio.ui

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.domain.parseClock
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin

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

data class ScoreboardUiState(
    val match: MatchState,
    val origin: ReadingOrigin,
    val link: LinkState,
    /** Antigüedad de la última lectura en ms; null si no hay lecturas. */
    val ageMillis: Long?,
    val confidence: Float?,
    /** Hay una lectura del lector posterior a la corrección manual vigente. */
    val newerReadingAvailable: Boolean = false,
)

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
        fun from(match: MatchState) = CorrectionDraft(
            home = match.homePoints,
            away = match.awayPoints,
            clockText = formatClock(match.clockSeconds),
            period = match.period,
            possession = match.possessionSeconds,
        )
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
    val message: String? = null,
) {
    val previewSource: VideoSource? get() = sources.firstOrNull { it.id == previewSourceId }
    val programSource: VideoSource? get() = sources.firstOrNull { it.id == programSourceId }
    val canTakeToAir: Boolean get() = previewSourceId != null && previewSourceId != programSourceId
}
