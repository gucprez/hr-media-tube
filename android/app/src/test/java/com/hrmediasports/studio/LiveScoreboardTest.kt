package com.hrmediasports.studio

import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.ScoreboardSnapshot
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.protocol.isStale
import com.hrmediasports.studio.ui.MonitorView
import com.hrmediasports.studio.ui.SOURCE_RESULT
import com.hrmediasports.studio.ui.StudioPanel
import com.hrmediasports.studio.ui.StudioViewModel
import com.hrmediasports.studio.ui.dataLabel
import kotlinx.coroutines.flow.emptyFlow
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Fuente falsa que permite probar lecturas remotas, antigüedad y corrección manual. */
private class FakeSource(initial: ScoreboardSnapshot) : ScoreboardSource {
    val flow = MutableStateFlow(initial)
    override val snapshot: StateFlow<ScoreboardSnapshot> = flow
}

@OptIn(ExperimentalCoroutinesApi::class)
class LiveScoreboardTest {
    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    private var now = 10_000L
    private val ticks = MutableSharedFlow<Unit>(extraBufferCapacity = 8)

    private fun match(home: Int) = MatchState(home, 40, 120, 2, 10)

    private fun snap(
        home: Int,
        link: LinkState = LinkState.Connected,
        receivedAt: Long? = 10_000L,
    ) = ScoreboardSnapshot(match(home), ReadingOrigin.Remote, link, receivedAt, 0.9f)

    private fun vm(source: FakeSource) = StudioViewModel(DemoMediaEngine, source, { now }, ticks)

    @Test fun newSnapshotUpdatesScoreboard() {
        val source = FakeSource(snap(10))
        val vm = vm(source)
        assertEquals(10, vm.state.value.scoreboard.match!!.homePoints)
        now = 10_200
        source.flow.value = snap(12, receivedAt = 10_100L)
        val sb = vm.state.value.scoreboard
        assertEquals(12, sb.match!!.homePoints)
        assertEquals(100L, sb.ageMillis)
        assertEquals(ReadingOrigin.Remote, sb.origin)
    }

    @Test fun ageKeepsGrowingWithoutNewData() {
        val vm = vm(FakeSource(snap(10)))
        assertEquals(0L, vm.state.value.scoreboard.ageMillis)
        now = 14_000
        ticks.tryEmit(Unit)
        val age = vm.state.value.scoreboard.ageMillis!!
        assertEquals(4_000L, age)
        assertTrue(isStale(age))
    }

    @Test fun connectedWithoutReadingsHasNoAge() {
        val vm = vm(FakeSource(snap(0, receivedAt = null)))
        val sb = vm.state.value.scoreboard
        assertEquals(LinkState.Connected, sb.link)
        assertNull(sb.ageMillis)
        ticks.tryEmit(Unit)
        assertNull(vm.state.value.scoreboard.ageMillis)
    }

    @Test fun manualCorrectionHoldsAndFlagsNewerReading() {
        val source = FakeSource(snap(10))
        val vm = vm(source)
        vm.openCorrection()
        vm.updateDraft { it.copy(home = 77) }
        vm.applyCorrection()
        assertFalse(vm.state.value.scoreboard.newerReadingAvailable)

        now = 10_500
        source.flow.value = snap(15, receivedAt = 10_400L)
        val sb = vm.state.value.scoreboard
        assertEquals(77, sb.match!!.homePoints)            // la corrección se mantiene
        assertEquals(ReadingOrigin.Manual, sb.origin)
        assertTrue(sb.newerReadingAvailable)             // y se avisa que hay datos nuevos

        vm.discardCorrection()
        assertEquals(15, vm.state.value.scoreboard.match!!.homePoints)
        assertFalse(vm.state.value.scoreboard.newerReadingAvailable)
    }

    @Test fun oldReadingDoesNotFlagNewer() {
        val vm = vm(FakeSource(snap(10, receivedAt = 9_000L)))
        now = 10_000
        vm.openCorrection()
        vm.applyCorrection()
        assertFalse(vm.state.value.scoreboard.newerReadingAvailable)
    }

    @Test fun neverReceivedShowsNoMatchAndNoLabel() {
        val vm = vm(FakeSource(ScoreboardSnapshot(null, ReadingOrigin.Remote, LinkState.NotConnected, null, null)))
        val sb = vm.state.value.scoreboard
        assertNull(sb.match)
        assertNull(sb.ageMillis)
        assertNull(sb.dataLabel())
    }

    @Test fun lostConnectionKeepsLastDataAndWarns() {
        val source = FakeSource(snap(10))
        val vm = vm(source)
        now = 15_000
        source.flow.value = snap(10, link = LinkState.NotConnected, receivedAt = 10_000L)
        val sb = vm.state.value.scoreboard
        assertEquals(10, sb.match!!.homePoints)           // se conservan los últimos datos
        assertEquals("DESACTUALIZADO", sb.dataLabel())    // y se advierte
    }

    @Test fun demoAndManualAreLabeled() {
        val demo = StudioViewModel(DemoMediaEngine, com.hrmediasports.studio.engine.DemoScoreboardSource, { 0L }, emptyFlow())
        assertEquals("DATOS DEMO", demo.state.value.scoreboard.dataLabel())
        demo.openCorrection(); demo.applyCorrection()
        assertEquals("CORREGIDO A MANO", demo.state.value.scoreboard.dataLabel())
    }

    @Test fun layoutStateActions() {
        val vm = vm(FakeSource(snap(10)))
        assertEquals(MonitorView.Program, vm.state.value.monitorView)
        vm.setMonitorView(MonitorView.Preview)
        assertEquals(MonitorView.Preview, vm.state.value.monitorView)
        vm.selectSource(SOURCE_RESULT)
        vm.takeToAir()                                      // al poner al aire se vuelve a ver la salida
        assertEquals(MonitorView.Program, vm.state.value.monitorView)

        assertFalse(vm.state.value.replaysTrayOpen)
        vm.toggleReplaysTray()
        assertTrue(vm.state.value.replaysTrayOpen)
        vm.openPanel(StudioPanel.Replays)
        assertEquals(StudioPanel.Replays, vm.state.value.openPanel)
        vm.closePanel()
        assertNull(vm.state.value.openPanel)
    }
}
