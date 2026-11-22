package com.hrmediasports.studio.board

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.ZoneTemplate
import com.hrmediasports.studio.domain.ZoneTemplateStore
import com.hrmediasports.studio.link.ClientStatus
import com.hrmediasports.studio.link.Frame
import com.hrmediasports.studio.link.FrameType
import com.hrmediasports.studio.link.ScoreMessage
import com.hrmediasports.studio.live.LiveScore
import com.hrmediasports.studio.live.ScoreEditor
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

enum class BoardMode { Manual, Auto }

/** Hacia dónde se manda el marcador (el cliente LAN real o uno falso en pruebas). */
interface ScoreSink {
    val status: StateFlow<ClientStatus>
    fun send(msg: ScoreMessage)
}

data class BoardUiState(
    val mode: BoardMode = BoardMode.Manual,
    val manual: LiveScore = LiveScore(),
    val zones: ZoneTemplate = ZoneTemplate.Default,
    val selectedZone: ZoneId? = null,
    val editingZones: Boolean = false,
    val usePossession: Boolean = false,
    val zoneStates: Map<ZoneId, ZoneState> = emptyMap(),
    /** Lo último que se envió (lo que ve el Estudio). */
    val lastSent: ScoreMessage? = null,
    val sending: Boolean = false,
    val now: Long = 0L,
    val message: String? = null,
)

class BoardViewModel(
    private val sink: ScoreSink,
    private val zoneStore: ZoneTemplateStore,
    private val clock: () -> Long,
    tickMillis: Long = 1_000,
) : ViewModel() {
    private val _ui = MutableStateFlow(BoardUiState(zones = zoneStore.load() ?: ZoneTemplate.Default, now = clock()))
    val ui: StateFlow<BoardUiState> = _ui.asStateFlow()
    val link: StateFlow<ClientStatus> get() = sink.status
    private var seq = 0L
    private var fusion = BoardFusion(false)

    init {
        if (tickMillis > 0) viewModelScope.launch { while (isActive) { delay(tickMillis); heartbeat() } }
    }

    /** Cada segundo: el reloj manual avanza y se reenvía el marcador (el Estudio sabe que la pizarra sigue viva). */
    fun heartbeat() {
        val now = clock()
        _ui.update { it.copy(now = now, manual = ScoreEditor.tick(it.manual, now)) }
        when (_ui.value.mode) {
            BoardMode.Manual -> sendManual()
            BoardMode.Auto -> fusion.current(now, ++seq)?.let(::emit)
        }
    }

    private fun emit(msg: ScoreMessage) {
        sink.send(msg)
        _ui.update { it.copy(lastSent = msg, sending = true) }
    }

    private fun sendManual() {
        val s = _ui.value.manual; val now = clock()
        val m = MatchState(s.home.points, s.away.points, s.remainingSeconds(now), s.period, 0)
        emit(ScoreMessage(m, null, auto = false, seq = ++seq, clockRunning = s.running))
    }

    fun setMode(m: BoardMode) {
        fusion = BoardFusion(_ui.value.usePossession)
        _ui.update { it.copy(mode = m, editingZones = m == BoardMode.Auto && it.editingZones, zoneStates = emptyMap()) }
    }

    // ---- Manual ----
    private fun editManual(f: (LiveScore, Long) -> LiveScore) {
        val now = clock()
        _ui.update { it.copy(manual = f(it.manual, now), now = now) }
        if (_ui.value.mode == BoardMode.Manual) sendManual()
    }
    fun points(home: Boolean, d: Int) = editManual { s, _ -> ScoreEditor.addPoints(s, home, d) }
    fun toggleClock() = editManual { s, now -> if (s.running) ScoreEditor.stopClock(s, now) else ScoreEditor.startClock(s, now) }
    fun adjustClock(d: Int) = editManual { s, now -> ScoreEditor.adjustClock(s, now, d) }
    fun nextPeriod() = editManual { s, _ -> ScoreEditor.nextPeriod(s) }
    fun previousPeriod() = editManual { s, _ -> ScoreEditor.previousPeriod(s) }
    fun setMinutes(m: Int) = editManual { s, _ -> ScoreEditor.setTeams(s, s.home.name, s.home.color, s.away.name, s.away.color, m) }
    fun newGame() = editManual { s, _ -> ScoreEditor.newGame(s) }

    // ---- Automático ----
    /** Llega desde el analizador de cámara (otro hilo) unas 4 veces por segundo. */
    fun onFrameRead(reads: Map<ZoneId, ZoneRead>) {
        if (_ui.value.mode != BoardMode.Auto || _ui.value.editingZones) return
        val now = clock()
        val before = fusion.current(now, seq)?.match
        fusion.offer(reads, now)
        val cur = fusion.current(now, seq + 1)
        _ui.update { it.copy(zoneStates = fusion.zones.toMap()) }
        // Un cambio aceptado se manda enseguida (no espera al latido).
        if (cur != null && cur.match != before) { seq++; emit(cur) }
    }

    fun toggleEditZones() = _ui.update { s ->
        val editing = !s.editingZones
        if (!editing) zoneStore.save(s.zones)
        s.copy(editingZones = editing, selectedZone = if (editing) s.selectedZone ?: ZoneId.Home else null,
            message = if (!editing) "Zonas guardadas." else null)
    }
    fun selectZone(id: ZoneId) = _ui.update { it.copy(selectedZone = id) }
    fun moveZone(id: ZoneId, dx: Float, dy: Float) = _ui.update { it.copy(zones = it.zones.with(id, it.zones[id].moved(dx, dy)), selectedZone = id) }
    fun resizeZone(id: ZoneId, dw: Float, dh: Float) = _ui.update { s ->
        val r = s.zones[id]
        val nr = com.hrmediasports.studio.domain.NormRect(r.left, r.top, (r.right + dw).coerceIn(minOf(1f, r.left + com.hrmediasports.studio.domain.NormRect.MIN_SIZE), 1f), (r.bottom + dh).coerceIn(minOf(1f, r.top + com.hrmediasports.studio.domain.NormRect.MIN_SIZE), 1f))
        s.copy(zones = s.zones.with(id, nr), selectedZone = id)
    }
    fun togglePossession() { _ui.update { it.copy(usePossession = !it.usePossession) }; fusion = BoardFusion(_ui.value.usePossession) }
    fun consumeMessage() = _ui.update { it.copy(message = null) }
}

/** Para reenviar el último marcador apenas se reconecta. */
fun stickyScore(msg: ScoreMessage) = Frame(FrameType.SCORE, msg.encode())
