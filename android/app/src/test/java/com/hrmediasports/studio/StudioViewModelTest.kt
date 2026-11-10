package com.hrmediasports.studio

import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.DemoScoreboardSource
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.ui.RecordingState
import com.hrmediasports.studio.ui.SOURCE_RESULT
import com.hrmediasports.studio.ui.SOURCE_CAMERA_1
import com.hrmediasports.studio.ui.SOURCE_CAMERA_2
import com.hrmediasports.studio.ui.StreamingState
import com.hrmediasports.studio.ui.StudioViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.emptyFlow
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

@OptIn(ExperimentalCoroutinesApi::class)
class StudioViewModelTest {
    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    private fun vm() = StudioViewModel(DemoMediaEngine, DemoScoreboardSource, { 0L }, emptyFlow())

    @Test fun selectingSourceOnlyPreparesPreview() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_2)
        assertEquals(SOURCE_CAMERA_2, vm.state.value.previewSourceId)
        assertNull(vm.state.value.programSourceId)
    }

    @Test fun takeToAirSendsPreviewToProgram() {
        val vm = vm()
        vm.selectSource(SOURCE_RESULT)
        assertTrue(vm.state.value.canTakeToAir)
        vm.takeToAir()
        assertEquals(SOURCE_RESULT, vm.state.value.programSourceId)
        assertFalse(vm.state.value.canTakeToAir)
        // Seleccionar otra no cambia lo que está al aire.
        vm.selectSource(SOURCE_CAMERA_1)
        assertEquals(SOURCE_RESULT, vm.state.value.programSourceId)
    }

    @Test fun unknownSourceIsIgnored() {
        val vm = vm()
        vm.selectSource("nope")
        assertEquals(SOURCE_CAMERA_1, vm.state.value.previewSourceId)
    }

    @Test fun demoDoesNotPretendOutputsWork() {
        val vm = vm()
        val s = vm.state.value
        assertTrue(s.isDemo)
        assertEquals(RecordingState.Unavailable, s.recording)
        assertEquals(StreamingState.Unavailable, s.streaming)
        assertTrue(s.replays.isEmpty())
        assertFalse(s.replayBufferAvailable)
        assertEquals(0, s.connectedCameras)
        assertEquals(ReadingOrigin.Demo, s.scoreboard.origin)
        assertNull(s.scoreboard.ageMillis)

        vm.onRecordClick()
        assertNotNull(vm.state.value.message)
        assertEquals(RecordingState.Unavailable, vm.state.value.recording)
        vm.consumeMessage()
        assertNull(vm.state.value.message)
        vm.onStreamClick()
        assertNotNull(vm.state.value.message)
        vm.consumeMessage()
        vm.onSaveReplayClick()
        assertNotNull(vm.state.value.message)
        assertTrue(vm.state.value.replays.isEmpty())
    }

    @Test fun manualCorrectionAppliesAndCanBeDiscarded() {
        val vm = vm()
        vm.openCorrection()
        vm.updateDraft { it.copy(home = 50, clockText = "02:10") }
        vm.applyCorrection()
        val s = vm.state.value
        assertNull(s.correctionDraft)
        assertEquals(50, s.scoreboard.match!!.homePoints)
        assertEquals(130, s.scoreboard.match!!.clockSeconds)
        assertEquals(ReadingOrigin.Manual, s.scoreboard.origin)

        vm.discardCorrection()
        assertEquals(48, vm.state.value.scoreboard.match!!.homePoints)
        assertEquals(ReadingOrigin.Demo, vm.state.value.scoreboard.origin)
    }

    @Test fun invalidClockDoesNotApply() {
        val vm = vm()
        vm.openCorrection()
        vm.updateDraft { it.copy(home = 99, clockText = "xx") }
        vm.applyCorrection()
        assertNotNull(vm.state.value.correctionDraft)
        assertEquals(48, vm.state.value.scoreboard.match!!.homePoints)
    }
}
