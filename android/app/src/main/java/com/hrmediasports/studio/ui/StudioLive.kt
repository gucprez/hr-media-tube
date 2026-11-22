package com.hrmediasports.studio.ui

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.link.RemoteCamera
import com.hrmediasports.studio.link.StudioLink
import com.hrmediasports.studio.live.EngineStatus
import com.hrmediasports.studio.live.LinkStatus
import com.hrmediasports.studio.live.LiveEngine
import com.hrmediasports.studio.live.LiveScore
import com.hrmediasports.studio.live.LiveSettings
import com.hrmediasports.studio.live.LiveSettingsStore
import com.hrmediasports.studio.live.ScoreEditor
import com.hrmediasports.studio.live.Slate
import kotlinx.coroutines.flow.StateFlow

/** Lo que el Estudio real necesita del motor y del enlace (interfaz para poder probarlo sin hardware). */
interface StudioLive {
    val engine: StateFlow<EngineStatus>
    val cameras: StateFlow<List<RemoteCamera>>
    val settingsStore: LiveSettingsStore
    fun selectVideo(index: Int): Boolean
    fun setSlate(slate: Slate)
    fun setOverlay(score: LiveScore)
    fun goLive(settings: LiveSettings): String?
    fun stopLive()
    fun startRecording(): String?
    fun stopRecording()
    /** ms desde el último cuadro de la cámara remota del lugar, o null. */
    fun remoteAgeMs(slot: Int): Long?
}

class EngineStudioLive(override val settingsStore: LiveSettingsStore) : StudioLive {
    override val engine get() = LiveEngine.status
    override val cameras get() = StudioLink.cameras
    override fun selectVideo(index: Int) = LiveEngine.selectVideo(index)
    override fun setSlate(slate: Slate) = LiveEngine.setSlate(slate)
    override fun setOverlay(score: LiveScore) = LiveEngine.setScore(score)
    override fun goLive(settings: LiveSettings) = LiveEngine.goLive(settings.destinations, settings.record)
    override fun stopLive() = LiveEngine.stopAll()
    override fun startRecording() = LiveEngine.startManualRecording()
    override fun stopRecording() = LiveEngine.stopManualRecording()
    override fun remoteAgeMs(slot: Int) = LiveEngine.remoteAgeMs(slot)
}

fun EngineStatus.streamingState(): StreamingState = when {
    anyLive -> StreamingState.Live
    anyOn -> StreamingState.Connecting
    links.values.any { it is LinkStatus.Failed } -> StreamingState.Error
    else -> StreamingState.Offline
}

/** Marcador del video a partir de la lectura de la pizarra (o la corrección manual). */
fun overlayScore(match: MatchState?, s: LiveSettings, visible: Boolean): LiveScore {
    val base = ScoreEditor.setTeams(LiveScore(), s.homeName, s.homeColor, s.awayName, s.awayColor, s.periodMinutes)
    if (match == null) return base.copy(visible = false)
    return base.copy(
        home = base.home.copy(points = match.homePoints),
        away = base.away.copy(points = match.awayPoints),
        period = match.period,
        clockBaseSeconds = match.clockSeconds,
        runningSince = null,
        visible = visible,
    )
}

/** Si la cámara remota al aire deja de mandar imagen este tiempo, se vuelve sola a la Cámara 1. */
const val REMOTE_FREEZE_FALLBACK_MS = 3_000L
