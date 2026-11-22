package com.hrmediasports.studio

import com.hrmediasports.studio.live.EngineStatus
import com.hrmediasports.studio.live.InMemoryLiveSettingsStore
import com.hrmediasports.studio.live.LiveControl
import com.hrmediasports.studio.live.LiveDestination
import com.hrmediasports.studio.live.LivePanel
import com.hrmediasports.studio.live.LiveScore
import com.hrmediasports.studio.live.LiveSettings
import com.hrmediasports.studio.live.LiveViewModel
import com.hrmediasports.studio.live.LinkStatus
import com.hrmediasports.studio.live.Platform
import com.hrmediasports.studio.live.Quality
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Motor falso: registra lo que pide la pantalla y permite simular cortes de un destino. */
class FakeControl : LiveControl {
    override val status = MutableStateFlow(EngineStatus(prepared = true))
    val started = mutableListOf<String>()
    val stopped = mutableListOf<Platform>()
    var lastScore: LiveScore? = null
    var prepared: Quality? = null
    var recorded: Boolean? = null

    private fun set(p: Platform, s: LinkStatus) { status.value = status.value.copy(links = status.value.links + (p to s)) }
    fun drop(p: Platform, reason: String) = set(p, LinkStatus.Failed(reason))

    override fun prepare(q: Quality): Boolean { prepared = q; return true }
    override fun goLive(destinations: List<LiveDestination>, record: Boolean): String? {
        val ready = destinations.filter { it.ready }
        if (ready.isEmpty()) return "Activa al menos un destino con su clave."
        recorded = record
        ready.forEach { started += it.endpoint()!!; set(it.platform, LinkStatus.Live) }
        return null
    }
    override fun startDestination(d: LiveDestination): String? { started += d.endpoint()!!; set(d.platform, LinkStatus.Live); return null }
    override fun stopDestination(p: Platform) { stopped += p; set(p, LinkStatus.Off) }
    override fun stopAll() { Platform.entries.forEach { set(it, LinkStatus.Off) } }
    override fun setScore(s: LiveScore) { lastScore = s }
    override fun currentScore(): LiveScore? = lastScore
    override fun setMuted(muted: Boolean) {}
    override fun switchCamera() {}
    override fun clearError() {}
}

@OptIn(ExperimentalCoroutinesApi::class)
class LiveFlowTest {
    @Before fun setUp() { Dispatchers.setMain(UnconfinedTestDispatcher()) }
    @After fun tearDown() { Dispatchers.resetMain() }

    private var now = 0L
    private fun both() = LiveSettings()
        .withDestination(LiveDestination(Platform.YouTube, enabled = true, key = "yt-key"))
        .withDestination(LiveDestination(Platform.Facebook, enabled = true, key = "fb-key"))

    private fun vm(control: FakeControl = FakeControl(), settings: LiveSettings = both()) =
        LiveViewModel(control, InMemoryLiveSettingsStore(settings), { now }, tickMillis = 0)

    @Test fun firstTimeWithoutKeysOpensSetup() {
        assertEquals(LivePanel.Setup, vm(settings = LiveSettings()).ui.value.panel)
        assertEquals(LivePanel.None, vm().ui.value.panel)
    }

    @Test fun goLiveSendsToYoutubeAndFacebookAtOnceWithBackupRecording() {
        val c = FakeControl(); val v = vm(c)
        v.goLive()
        assertEquals(listOf("rtmps://a.rtmps.youtube.com:443/live2/yt-key", "rtmps://live-api-s.facebook.com:443/rtmp/fb-key"), c.started)
        assertEquals(true, c.recorded)
        assertTrue(c.status.value.anyLive)
    }

    @Test fun incompleteDestinationBlocksAndExplainsInsteadOfFailingOnAir() {
        val c = FakeControl()
        val v = vm(c, both().withDestination(LiveDestination(Platform.Facebook, enabled = true, key = "")))
        v.goLive()
        assertTrue(c.started.isEmpty())
        assertTrue(v.ui.value.message!!.startsWith("Facebook"))
        assertEquals(LivePanel.Setup, v.ui.value.panel)
    }

    @Test fun oneDestinationFailingDoesNotCutTheOtherAndCanBeReconnected() {
        val c = FakeControl(); val v = vm(c)
        v.goLive()
        c.drop(Platform.Facebook, "se cortó la conexión")
        assertEquals(LinkStatus.Live, c.status.value.links[Platform.YouTube])
        assertTrue(c.status.value.anyOn)
        v.toggleDestination(Platform.Facebook)                       // toque: reconectar solo Facebook
        assertEquals(LinkStatus.Live, c.status.value.links[Platform.Facebook])
        assertEquals(3, c.started.size)
        v.toggleDestination(Platform.YouTube)                        // toque: detener solo YouTube
        assertEquals(listOf(Platform.YouTube), c.stopped)
        assertEquals(LinkStatus.Live, c.status.value.links[Platform.Facebook])
    }

    @Test fun stoppingNeedsConfirmation() {
        val c = FakeControl(); val v = vm(c)
        v.goLive()
        v.requestStop()
        assertEquals(LivePanel.ConfirmStop, v.ui.value.panel)
        assertTrue(c.status.value.anyLive)                           // todavía al aire
        v.confirmStop()
        assertTrue(!c.status.value.anyOn)
    }

    @Test fun scoreChangesReachTheVideoOverlay() {
        val c = FakeControl(); val v = vm(c)
        v.points(home = true, delta = 3)
        v.foul(home = false, delta = 1)
        now = 1_000; v.toggleClock()
        now = 11_000; v.tick()
        assertEquals(3, c.lastScore!!.home.points)
        assertEquals(1, c.lastScore!!.away.fouls)
        assertEquals("09:50", v.ui.value.score.clockText(now))
        v.toggleScoreboard()
        assertTrue(!c.lastScore!!.visible)
    }

    @Test fun scoreSurvivesReturningToTheScreen() {
        val c = FakeControl()
        vm(c).points(home = false, delta = 2)
        val again = vm(c)                                            // p. ej. volver desde la notificación
        assertEquals(2, again.ui.value.score.away.points)
    }

    @Test fun savingSettingsAppliesTeamsAndWarnsQualityOnAir() {
        val c = FakeControl(); val v = vm(c)
        v.goLive()
        v.saveSettings(both().copy(homeName = "LEON", quality = Quality.Saver720))
        assertEquals("LEON", c.lastScore!!.home.name)
        assertNotNull(v.ui.value.message)
        assertEquals(null, c.prepared)                               // no se toca la cámara al aire
    }
}
