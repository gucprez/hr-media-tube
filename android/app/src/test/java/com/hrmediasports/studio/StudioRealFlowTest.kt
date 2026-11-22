package com.hrmediasports.studio

import com.hrmediasports.studio.board.BoardFusion
import com.hrmediasports.studio.board.BoardMode
import com.hrmediasports.studio.board.BoardViewModel
import com.hrmediasports.studio.board.ScoreSink
import com.hrmediasports.studio.board.ZoneRead
import com.hrmediasports.studio.domain.InMemoryZoneTemplateStore
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.ScoreboardSnapshot
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.link.ClientStatus
import com.hrmediasports.studio.link.RemoteCamera
import com.hrmediasports.studio.link.ScoreMessage
import com.hrmediasports.studio.live.EngineStatus
import com.hrmediasports.studio.live.InMemoryLiveSettingsStore
import com.hrmediasports.studio.live.LinkStatus
import com.hrmediasports.studio.live.LiveDestination
import com.hrmediasports.studio.live.LiveScore
import com.hrmediasports.studio.live.LiveSettings
import com.hrmediasports.studio.live.LiveSettingsStore
import com.hrmediasports.studio.live.Platform
import com.hrmediasports.studio.live.Slate
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.ui.RecordingState
import com.hrmediasports.studio.ui.SOURCE_BREAK
import com.hrmediasports.studio.ui.SOURCE_CAMERA_1
import com.hrmediasports.studio.ui.SOURCE_CAMERA_2
import com.hrmediasports.studio.ui.SOURCE_RESULT
import com.hrmediasports.studio.ui.StreamingState
import com.hrmediasports.studio.ui.StudioDialog
import com.hrmediasports.studio.ui.StudioLive
import com.hrmediasports.studio.ui.StudioViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class FakeStudioLive(override val settingsStore: LiveSettingsStore) : StudioLive {
    override val engine = MutableStateFlow(EngineStatus(prepared = true))
    override val cameras = MutableStateFlow(List(2) { RemoteCamera(it) })
    var video = 0; var shownSlate = Slate.None; var lastOverlay: LiveScore? = null; var remoteAge: Long? = null
    var goLiveWith: LiveSettings? = null; var stopped = false; var recording = false
    override fun selectVideo(index: Int): Boolean { video = index; return true }
    override fun setSlate(slate: Slate) { shownSlate = slate }
    override fun setOverlay(score: LiveScore) { lastOverlay = score }
    override fun goLive(settings: LiveSettings): String? {
        goLiveWith = settings
        engine.value = engine.value.copy(links = engine.value.links + (Platform.YouTube to LinkStatus.Live)); return null
    }
    override fun stopLive() { stopped = true; engine.value = EngineStatus(prepared = true) }
    override fun startRecording(): String? { recording = true; engine.value = engine.value.copy(recording = true); return null }
    override fun stopRecording() { recording = false; engine.value = engine.value.copy(recording = false) }
    override fun remoteAgeMs(slot: Int) = remoteAge
}

class FakeBoardSource : ScoreboardSource {
    override val snapshot = MutableStateFlow(ScoreboardSnapshot(null, ReadingOrigin.Remote, LinkState.NotConnected, null, null))
    fun receive(h: Int, a: Int, at: Long) { snapshot.value = ScoreboardSnapshot(MatchState(h, a, 300, 2, 0), ReadingOrigin.Remote, LinkState.Connected, at, 0.9f) }
}

@OptIn(ExperimentalCoroutinesApi::class)
class StudioRealFlowTest {
    @Before fun setUp() { Dispatchers.setMain(UnconfinedTestDispatcher()) }
    @After fun tearDown() { Dispatchers.resetMain() }

    private var now = 1_000_000L
    private val ticks = MutableSharedFlow<Unit>(extraBufferCapacity = 10)
    private val ready = LiveSettings(homeName = "TIGR", awayName = "LEON")
        .withDestination(LiveDestination(Platform.YouTube, enabled = true, key = "yt"))

    private fun studio(settings: LiveSettings = ready): Triple<StudioViewModel, FakeStudioLive, FakeBoardSource> {
        val live = FakeStudioLive(InMemoryLiveSettingsStore(settings)); val board = FakeBoardSource()
        return Triple(StudioViewModel(DemoMediaEngine, board, { now }, ticks, live), live, board)
    }

    private fun connectCam2(live: FakeStudioLive) {
        live.cameras.value = listOf(RemoteCamera(0, connected = true, name = "S21+", hasVideo = true), RemoteCamera(1))
        live.remoteAge = 100
    }

    @Test fun realStudioIsNotDemoAndStartsWithThisPhonesCameraOnAir() {
        val (vm, _, _) = studio()
        val s = vm.state.value
        assertFalse(s.isDemo); assertTrue(s.live)
        assertEquals(SOURCE_CAMERA_1, s.programSourceId)
        assertEquals(StreamingState.Offline, s.streaming)
        assertEquals(RecordingState.Stopped, s.recording)
    }

    @Test fun boardReadingsReachTheVideoScoreboardWithTeamNames() {
        val (_, live, board) = studio()
        assertFalse(live.lastOverlay!!.visible)                               // sin datos: oculto
        board.receive(54, 49, now)
        assertEquals(54, live.lastOverlay!!.home.points)
        assertEquals("TIGR", live.lastOverlay!!.home.name)
        assertEquals(300, live.lastOverlay!!.clockBaseSeconds)
        assertTrue(live.lastOverlay!!.visible)
    }

    @Test fun manualCorrectionOverridesTheVideoScoreboard() {
        val (vm, live, board) = studio()
        board.receive(10, 10, now)
        vm.openCorrection(); vm.updateDraft { it.copy(home = 12) }; vm.applyCorrection()
        assertEquals(12, live.lastOverlay!!.home.points)
        vm.discardCorrection()
        assertEquals(10, live.lastOverlay!!.home.points)
    }

    @Test fun takeToAirSwitchesRealSourcesAndSlates() {
        val (vm, live, _) = studio()
        vm.selectSource(SOURCE_CAMERA_2); vm.takeToAir()                      // sin conectar: no cambia
        assertEquals(SOURCE_CAMERA_1, vm.state.value.programSourceId)
        assertTrue(vm.state.value.message!!.contains("no está conectada"))
        connectCam2(live)
        vm.takeToAir()
        assertEquals(1, live.video); assertEquals(SOURCE_CAMERA_2, vm.state.value.programSourceId)
        vm.selectSource(SOURCE_BREAK); vm.takeToAir()
        assertEquals(Slate.Break, live.shownSlate)
        vm.selectSource(SOURCE_RESULT); vm.takeToAir()
        assertEquals(Slate.Result, live.shownSlate)
        vm.selectSource(SOURCE_CAMERA_1); vm.takeToAir()
        assertEquals(0, live.video); assertEquals(Slate.None, live.shownSlate)
    }

    @Test fun frozenRemoteCameraOnAirFallsBackToCamera1() {
        val (vm, live, _) = studio()
        connectCam2(live)
        vm.selectSource(SOURCE_CAMERA_2); vm.takeToAir()
        ticks.tryEmit(Unit)
        assertEquals(SOURCE_CAMERA_2, vm.state.value.programSourceId)          // llega imagen: sigue
        live.remoteAge = 3_500                                                 // 3,5 s sin imagen
        ticks.tryEmit(Unit)
        assertEquals(SOURCE_CAMERA_1, vm.state.value.programSourceId)
        assertEquals(0, live.video)
        assertTrue(vm.state.value.message!!.contains("volví a la Cámara 1"))
    }

    @Test fun streamButtonUsesSavedDestinationsOrOpensSetup() {
        val (vm, live, _) = studio(LiveSettings())
        vm.onStreamClick()
        assertEquals(StudioDialog.StreamSetup, vm.state.value.dialog)
        assertNull(live.goLiveWith)
        vm.saveLiveSettings(ready)
        vm.onStreamClick()
        assertNotNull(live.goLiveWith)
        assertEquals(StreamingState.Live, vm.state.value.streaming)
        vm.onStreamClick()                                                     // al aire: pide confirmación
        assertEquals(StudioDialog.ConfirmStopStream, vm.state.value.dialog)
        assertFalse(live.stopped)
        vm.confirmStopStream()
        assertTrue(live.stopped)
    }

    @Test fun recordButtonRecordsWithoutStreaming() {
        val (vm, live, _) = studio()
        vm.onRecordClick(); assertTrue(live.recording); assertEquals(RecordingState.Recording, vm.state.value.recording)
        vm.onRecordClick(); assertFalse(live.recording)
    }

    @Test fun hidingTheScoreboardHidesItInTheVideo() {
        val (vm, live, board) = studio()
        board.receive(1, 0, now)
        vm.toggleOverlay()
        assertFalse(live.lastOverlay!!.visible)
    }

    // ---------------- Pizarra ----------------

    private class FakeSink : ScoreSink {
        override val status = MutableStateFlow<ClientStatus>(ClientStatus.Connected("Pizarra"))
        val sent = mutableListOf<ScoreMessage>()
        override fun send(msg: ScoreMessage) { sent += msg }
    }

    @Test fun manualBoardSendsEveryChangeAndHeartbeat() {
        val sink = FakeSink()
        val vm = BoardViewModel(sink, InMemoryZoneTemplateStore(), { now }, tickMillis = 0)
        vm.points(true, 3)
        assertEquals(3, sink.sent.last().match.homePoints); assertFalse(sink.sent.last().auto)
        vm.toggleClock(); now += 5_000; vm.heartbeat()
        assertEquals(595, sink.sent.last().match.clockSeconds)
        assertTrue(sink.sent.last().clockRunning)
    }

    @Test fun autoBoardSendsOnlyStableCompleteReadings() {
        val sink = FakeSink()
        val vm = BoardViewModel(sink, InMemoryZoneTemplateStore(), { now }, tickMillis = 0)
        vm.setMode(BoardMode.Auto)
        fun frame(h: String?, a: String?, c: String?, p: String?) {
            now += 250
            vm.onFrameRead(mapOf(ZoneId.Home to ZoneRead(h, 0.9f), ZoneId.Away to ZoneRead(a, 0.9f), ZoneId.Clock to ZoneRead(c, 0.8f), ZoneId.Period to ZoneRead(p, 0.95f)))
        }
        frame("45", "40", "0732", "3"); frame("45", "40", "0732", "3")
        assertTrue(sink.sent.isEmpty())                                         // aún no es estable
        frame("45", "40", "0731", "3"); frame("45", "40", "0731", "3"); frame("45", "40", "0731", "3")
        val m = sink.sent.last()
        assertEquals(45, m.match.homePoints); assertEquals(7 * 60 + 31, m.match.clockSeconds); assertTrue(m.auto)
        assertEquals(0.8f, m.confidence!!, 0.001f)
        // Un "85" mal leído (dígito) no salta al aire con 3 lecturas.
        repeat(4) { frame("85", "40", "0731", "3") }
        assertEquals(45, sink.sent.last().match.homePoints)
        // Si el marcador se tapa, no se manda nada nuevo.
        val before = sink.sent.size
        repeat(12) { frame(null, null, null, null) }
        vm.heartbeat()
        assertEquals(before, sink.sent.size)
    }

    @Test fun fusionAcceptsARealBigChangeWhenSustained() {
        val f = BoardFusion()
        var t = 0L
        fun offer(h: String) { t += 250; f.offer(mapOf(ZoneId.Home to ZoneRead(h, 1f), ZoneId.Away to ZoneRead("0", 1f), ZoneId.Clock to ZoneRead("1000", 1f), ZoneId.Period to ZoneRead("1", 1f)), t) }
        repeat(3) { offer("10") }
        assertEquals(10, f.current(t, 1)!!.match.homePoints)
        repeat(8) { offer("20") }                                              // p. ej. corrección de la mesa
        assertEquals(20, f.current(t, 2)!!.match.homePoints)
    }
}
