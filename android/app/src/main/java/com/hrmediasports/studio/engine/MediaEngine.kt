package com.hrmediasports.studio.engine

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

/**
 * Capa 5 (captura, composición, codificación, grabación, transmisión).
 * La interfaz solo conoce estas capacidades; nunca presenta como operativo lo que
 * el motor activo no declare. Las capacidades reales se verifican con las pruebas
 * técnicas de docs/MATRIZ-FUNCIONES.md antes de implementarlas.
 */
data class EngineCapabilities(
    val canReceiveRemoteCameras: Boolean,
    val canRecord: Boolean,
    val canStream: Boolean,
    val canBufferReplays: Boolean,
    val canRecognizeScoreboard: Boolean,
    val canSendScoreboard: Boolean,
)

interface MediaEngine {
    val capabilities: EngineCapabilities
}

/** Motor del modo demostración: no hace nada y lo declara. */
object DemoMediaEngine : MediaEngine {
    override val capabilities = EngineCapabilities(
        canReceiveRemoteCameras = false,
        canRecord = false,
        canStream = false,
        canBufferReplays = false,
        canRecognizeScoreboard = false,
        canSendScoreboard = false,
    )
}

/** Foto del estado de la pizarra entregada por una fuente (capa 4 → UI). */
data class ScoreboardSnapshot(
    /** null si nunca se recibió ningún dato. */
    val match: MatchState?,
    val origin: ReadingOrigin,
    val link: LinkState,
    /** Instante de recepción de la última lectura; null si nunca hubo. */
    val lastReceivedAtMillis: Long?,
    val confidence: Float?,
)

/** Capa 4 (reconocimiento de pizarra): la UI solo consume el resultado. */
interface ScoreboardSource {
    val snapshot: StateFlow<ScoreboardSnapshot>
}

/** Datos de ejemplo fijos; no hay lector ni conexión. */
object DemoScoreboardSource : ScoreboardSource {
    override val snapshot: StateFlow<ScoreboardSnapshot> = MutableStateFlow(
        ScoreboardSnapshot(
            match = MatchState(
                homePoints = 48,
                awayPoints = 45,
                clockSeconds = 3 * 60 + 24,
                period = 3,
                possessionSeconds = 18,
            ),
            origin = ReadingOrigin.Demo,
            link = LinkState.NotConnected,
            lastReceivedAtMillis = null,
            confidence = null,
        ),
    )
}
