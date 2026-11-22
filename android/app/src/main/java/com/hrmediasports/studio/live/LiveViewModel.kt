package com.hrmediasports.studio.live

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** Lo que la pantalla necesita del motor; permite probar el flujo sin cámara ni red. */
interface LiveControl {
    val status: StateFlow<EngineStatus>
    fun prepare(q: Quality): Boolean
    fun goLive(destinations: List<LiveDestination>, record: Boolean): String?
    fun startDestination(d: LiveDestination): String?
    fun stopDestination(p: Platform)
    fun stopAll()
    fun setScore(s: LiveScore)
    fun currentScore(): LiveScore?
    fun setMuted(muted: Boolean)
    fun switchCamera()
    fun clearError()
}

object EngineControl : LiveControl {
    override val status get() = LiveEngine.status
    override fun prepare(q: Quality) = LiveEngine.prepare(q)
    override fun goLive(destinations: List<LiveDestination>, record: Boolean) = LiveEngine.goLive(destinations, record)
    override fun startDestination(d: LiveDestination) = LiveEngine.startDestination(d)
    override fun stopDestination(p: Platform) = LiveEngine.stopDestination(p)
    override fun stopAll() = LiveEngine.stopAll()
    override fun setScore(s: LiveScore) = LiveEngine.setScore(s)
    override fun currentScore(): LiveScore? = LiveEngine.currentScore()
    override fun setMuted(muted: Boolean) = LiveEngine.setMuted(muted)
    override fun switchCamera() = LiveEngine.switchCamera()
    override fun clearError() = LiveEngine.clearError()
}

enum class LivePanel { None, Setup, ConfirmStop, ConfirmNewGame, Clock }

data class LiveUiState(
    val settings: LiveSettings = LiveSettings(),
    val score: LiveScore = LiveScore(),
    val panel: LivePanel = LivePanel.None,
    val saver: Boolean = false,
    val muted: Boolean = false,
    val message: String? = null,
    val now: Long = 0L,
)

class LiveViewModel(
    private val control: LiveControl,
    private val store: LiveSettingsStore,
    private val clock: () -> Long,
    tickMillis: Long = 250,
) : ViewModel() {
    private val _ui = MutableStateFlow(LiveUiState())
    val ui: StateFlow<LiveUiState> = _ui.asStateFlow()
    val status: StateFlow<EngineStatus> get() = control.status

    init {
        val s = store.load()
        val score = control.currentScore() ?: ScoreEditor.setTeams(LiveScore(), s.homeName, s.homeColor, s.awayName, s.awayColor, s.periodMinutes)
        _ui.value = LiveUiState(settings = s, score = score, now = clock(),
            // Primera vez sin destinos: se abre la configuración.
            panel = if (s.readyCount == 0 && !control.status.value.anyOn) LivePanel.Setup else LivePanel.None)
        control.setScore(score)
        if (tickMillis > 0) viewModelScope.launch {
            while (isActive) { delay(tickMillis); tick() }
        }
    }

    fun tick() {
        val now = clock()
        _ui.update { st ->
            val sc = ScoreEditor.tick(st.score, now)
            if (sc != st.score) control.setScore(sc)
            st.copy(now = now, score = sc)
        }
    }

    private fun editScore(f: (LiveScore, Long) -> LiveScore) {
        val now = clock()
        _ui.update { st -> val sc = f(st.score, now); control.setScore(sc); st.copy(score = sc, now = now) }
    }

    // ---- Marcador ----
    fun points(home: Boolean, delta: Int) = editScore { s, _ -> ScoreEditor.addPoints(s, home, delta) }
    fun foul(home: Boolean, delta: Int) = editScore { s, _ -> ScoreEditor.addFoul(s, home, delta) }
    fun toggleClock() = editScore { s, now -> if (s.running) ScoreEditor.stopClock(s, now) else ScoreEditor.startClock(s, now) }
    fun adjustClock(delta: Int) = editScore { s, now -> ScoreEditor.adjustClock(s, now, delta) }
    fun setClock(seconds: Int) = editScore { s, now -> ScoreEditor.setClock(s, now, seconds) }
    fun nextPeriod() = editScore { s, _ -> ScoreEditor.nextPeriod(s) }
    fun previousPeriod() = editScore { s, _ -> ScoreEditor.previousPeriod(s) }
    fun toggleScoreboard() = editScore { s, _ -> s.copy(visible = !s.visible) }
    fun newGame() { editScore { s, _ -> ScoreEditor.newGame(s) }; closePanel() }

    // ---- Paneles ----
    fun openPanel(p: LivePanel) = _ui.update { it.copy(panel = p) }
    fun closePanel() = _ui.update { it.copy(panel = LivePanel.None) }
    fun consumeMessage() = _ui.update { it.copy(message = null) }
    fun toggleSaver() = _ui.update { it.copy(saver = !it.saver) }

    // ---- Configuración ----
    fun saveSettings(s: LiveSettings) {
        val old = _ui.value.settings
        store.save(s)
        val qualityChanged = s.quality != old.quality
        _ui.update { st ->
            st.copy(settings = s, panel = LivePanel.None,
                score = ScoreEditor.setTeams(st.score, s.homeName, s.homeColor, s.awayName, s.awayColor, s.periodMinutes)
                    .also { control.setScore(it) },
                message = if (qualityChanged && control.status.value.anyOn) "La nueva calidad se aplicará en la próxima transmisión." else null)
        }
    }

    /** true si hay que volver a preparar la cámara por cambio de calidad (solo fuera del aire). */
    fun needsPrepare(): Boolean = !control.status.value.anyOn

    fun prepare() { if (!control.status.value.anyOn) control.prepare(_ui.value.settings.quality) }

    // ---- Transmisión ----
    fun goLive() {
        val s = _ui.value.settings
        val problem = s.destinations.firstNotNullOfOrNull { d -> d.problem()?.let { "${d.platform.label}: $it" } }
        if (problem != null) { _ui.update { it.copy(message = problem, panel = LivePanel.Setup) }; return }
        val err = control.goLive(s.destinations, s.record)
        if (err != null) _ui.update { it.copy(message = err) }
    }

    fun requestStop() = openPanel(LivePanel.ConfirmStop)

    fun confirmStop() {
        control.stopAll()
        _ui.update { it.copy(panel = LivePanel.None, saver = false) }
    }

    /** Toque en un destino: si está al aire o reintentando, se detiene; si no, se (re)conecta. */
    fun toggleDestination(p: Platform) {
        val st = control.status.value.links[p] ?: LinkStatus.Off
        if (st == LinkStatus.Off || st is LinkStatus.Failed) {
            if (!control.status.value.anyOn) return // fuera del aire se usa el botón principal
            val d = _ui.value.settings.destination(p)
            val err = if (!d.ready) "${p.label}: ${d.problem() ?: "actívalo en Configurar"}" else control.startDestination(d)
            if (err != null) _ui.update { it.copy(message = err) }
        } else control.stopDestination(p)
    }

    fun toggleMute() {
        val m = !_ui.value.muted
        control.setMuted(m)
        _ui.update { it.copy(muted = m) }
    }

    fun switchCamera() = control.switchCamera()
    fun clearError() = control.clearError()
}
