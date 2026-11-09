package com.hrmediasports.studio.ui.reader

import androidx.lifecycle.ViewModel
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.ZoneTemplate
import com.hrmediasports.studio.domain.ZoneTemplateStore
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.DemoScoreboardSource
import com.hrmediasports.studio.engine.MediaEngine
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.protocol.LinkState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

enum class TemplateStatus { Default, Saved, Unsaved }

data class ReaderUiState(
    val zones: ZoneTemplate,
    val selectedZone: ZoneId?,
    val editing: Boolean,
    val templateStatus: TemplateStatus,
    /** Valores de ejemplo: no hay reconocimiento real. */
    val sample: MatchState,
    val canRecognize: Boolean,
    val canSend: Boolean,
    val link: LinkState,
    val isDemo: Boolean,
    val message: String? = null,
)

class ReaderViewModel(
    private val store: ZoneTemplateStore,
    private val engine: MediaEngine = DemoMediaEngine,
    scoreboardSource: ScoreboardSource = DemoScoreboardSource,
) : ViewModel() {

    private var saved: ZoneTemplate? = store.load()
    private val sample = scoreboardSource.snapshot.value.match

    private val _state = MutableStateFlow(
        ReaderUiState(
            zones = saved ?: ZoneTemplate.Default,
            selectedZone = null,
            editing = false,
            templateStatus = if (saved != null) TemplateStatus.Saved else TemplateStatus.Default,
            sample = sample,
            canRecognize = engine.capabilities.canRecognizeScoreboard,
            canSend = engine.capabilities.canSendScoreboard,
            link = LinkState.NotConnected,
            isDemo = true,
        ),
    )
    val state: StateFlow<ReaderUiState> = _state.asStateFlow()

    private fun statusOf(zones: ZoneTemplate): TemplateStatus = when {
        zones == saved -> TemplateStatus.Saved
        saved == null && zones == ZoneTemplate.Default -> TemplateStatus.Default
        else -> TemplateStatus.Unsaved
    }

    private fun updateZones(transform: (ZoneTemplate) -> ZoneTemplate) = _state.update { s ->
        val zones = transform(s.zones)
        s.copy(zones = zones, templateStatus = statusOf(zones))
    }

    fun toggleEditing() = _state.update { s ->
        val editing = !s.editing
        s.copy(editing = editing, selectedZone = if (editing) s.selectedZone ?: ZoneId.Home else null)
    }

    fun selectZone(id: ZoneId) = _state.update { if (it.editing) it.copy(selectedZone = id) else it }

    /** Desplaza la zona seleccionada; dx/dy son fracciones del encuadre. Solo en modo ajuste. */
    fun moveSelected(dx: Float, dy: Float) {
        val s = _state.value
        val id = s.selectedZone ?: return
        if (!s.editing) return
        updateZones { it.with(id, it[id].moved(dx, dy)) }
    }

    fun resizeSelected(factor: Float) {
        val s = _state.value
        val id = s.selectedZone ?: return
        if (!s.editing) return
        updateZones { it.with(id, it[id].scaled(factor)) }
    }

    fun resetZones() = updateZones { ZoneTemplate.Default }

    fun saveTemplate() {
        val zones = _state.value.zones
        if (store.save(zones)) {
            saved = zones
            _state.update { it.copy(templateStatus = TemplateStatus.Saved, message = "Plantilla guardada en este dispositivo.") }
        } else {
            post("No se pudo guardar la plantilla.")
        }
    }

    fun onStartSendClick() {
        val caps = engine.capabilities
        if (!caps.canSendScoreboard || !caps.canRecognizeScoreboard) {
            post("Envío no disponible: el reconocimiento de pizarra y la conexión con el estudio aún no están implementados.")
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }

    private fun post(text: String) = _state.update { it.copy(message = text) }
}

