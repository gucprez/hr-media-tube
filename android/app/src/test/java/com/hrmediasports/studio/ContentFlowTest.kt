package com.hrmediasports.studio

import com.hrmediasports.studio.content.AudioMix
import com.hrmediasports.studio.content.AudioRules
import com.hrmediasports.studio.content.BreakPlayer
import com.hrmediasports.studio.content.Compat
import com.hrmediasports.studio.content.ContentState
import com.hrmediasports.studio.content.InMemoryContentStore
import com.hrmediasports.studio.content.MatchAudioPolicy
import com.hrmediasports.studio.content.MediaItem
import com.hrmediasports.studio.content.MediaType
import com.hrmediasports.studio.content.Showing
import com.hrmediasports.studio.content.Template
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.DemoScoreboardSource
import com.hrmediasports.studio.ui.AGE_TICK_MILLIS
import com.hrmediasports.studio.ui.SOURCE_BREAK
import com.hrmediasports.studio.ui.SOURCE_CAMERA_1
import com.hrmediasports.studio.ui.SOURCE_CAMERA_2
import com.hrmediasports.studio.ui.StudioViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableSharedFlow
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

/** Operación del descanso desde el Estudio: preparar, poner al aire, interrumpir, volver al partido, fallos. */
@OptIn(ExperimentalCoroutinesApi::class)
class ContentFlowTest {
    @Before fun setUp() = Dispatchers.setMain(UnconfinedTestDispatcher())
    @After fun tearDown() = Dispatchers.resetMain()

    private val ticks = MutableSharedFlow<Unit>(extraBufferCapacity = 4096)
    private val store = InMemoryContentStore()
    private var n = 0

    private fun vm() = StudioViewModel(DemoMediaEngine, DemoScoreboardSource, { 0L }, ticks, store) { "id${++n}" }

    private fun StudioViewModel.advance(ms: Long) = repeat((ms / AGE_TICK_MILLIS).toInt()) { ticks.tryEmit(Unit) }

    private fun img(id: String, compat: Compat = Compat.Ok) = MediaItem(id, "content://$id", "$id.png", MediaType.Image, compat = compat)
    private fun vid(id: String, ms: Long = 10_000, compat: Compat = Compat.Ok, note: String? = null) =
        MediaItem(id, "content://$id", "$id.mp4", MediaType.Video, durationMs = ms, compat = compat, compatNote = note)

    /** Estudio con una lista «Tiempo muerto» mixta: imagen (6 s), video (10 s), imagen (6 s). Devuelve el id de la lista. */
    private fun StudioViewModel.withMixedList(vararg media: MediaItem = arrayOf(img("a"), vid("v"), img("b"))): String {
        importMedia(media.toList())
        consumeMessage()
        createPlaylist(Template.Timeout)
        val id = state.value.editingPlaylistId!!
        media.forEach { addEntry(id, it.id) }
        return id
    }

    @Test fun breakButtonOpensThePanelWithoutTouchingTheOutput() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_1); vm.takeToAir()
        vm.selectSource(SOURCE_BREAK)
        val s = vm.state.value
        assertTrue(s.contentPanelOpen)
        assertEquals(SOURCE_CAMERA_1, s.programSourceId)      // la salida no cambió
        assertEquals(SOURCE_CAMERA_1, s.previewSourceId)      // ni la vista previa
        assertNull(s.breakState)
    }

    @Test fun selectingAListOnlyPreparesItInPreviewAndSendsNoAudio() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_1); vm.takeToAir()
        val id = vm.withMixedList()
        vm.preparePlaylist(id)
        val s = vm.state.value
        assertEquals(SOURCE_BREAK, s.previewSourceId)
        assertEquals(id, s.preparedPlaylistId)
        assertEquals(SOURCE_CAMERA_1, s.programSourceId)      // sigue la cámara al aire
        assertNull(s.breakState)                               // nada se reproduce todavía
        assertEquals(AudioMix(1f, 0f), s.audioMix)             // la vista previa no aporta audio a la salida
        assertFalse(s.contentPanelOpen)
        assertTrue(s.canTakeToAir)
    }

    @Test fun takeToAirStartsFromTheBeginningAndAppliesAudioPolicy() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_2); vm.takeToAir()
        val id = vm.withMixedList()
        vm.preparePlaylist(id); vm.takeToAir()
        val s = vm.state.value
        assertEquals(SOURCE_BREAK, s.programSourceId)
        assertTrue(s.breakOnAir)
        assertEquals("a.png", s.breakState!!.showing.title)
        assertEquals(0L, s.breakState!!.elapsedMs)
        assertEquals("v.mp4", s.breakNextTitle)
        assertEquals(SOURCE_CAMERA_2, s.returnSourceId)        // volverá a la cámara que estaba al aire
        assertEquals(AudioMix(1f, 0f), s.savedMix)
        assertEquals(AudioMix(AudioRules.REDUCED_GAIN, 1f), s.audioMix)   // política por defecto: reducir el partido
        assertFalse(s.canTakeToAir)                            // la misma lista ya está al aire
    }

    @Test fun mixedListAdvancesWithTimeAndShowsRemainingAndNext() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.preparePlaylist(id); vm.takeToAir()
        vm.advance(4_000)
        assertEquals(2_000L, vm.state.value.breakState!!.remainingMs)
        vm.advance(2_000)
        assertEquals("v.mp4", vm.state.value.breakState!!.showing.title)
        assertEquals("b.png", vm.state.value.breakNextTitle)
        vm.advance(10_000)
        assertEquals("b.png", vm.state.value.breakState!!.showing.title)
        assertEquals("Placa de espera", vm.state.value.breakNextTitle)
        vm.advance(6_000)
        // Fin de la lista sin retorno automático: queda la placa de espera AL AIRE.
        val s = vm.state.value
        assertTrue(s.breakState!!.showing is Showing.Hold)
        assertEquals(SOURCE_BREAK, s.programSourceId)
    }

    @Test fun interruptingTheAdReturnsToTheCameraStopsItAndRestoresAudio() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_2); vm.takeToAir()
        val id = vm.withMixedList()
        vm.setAudioPolicy(MatchAudioPolicy.Mute)
        vm.preparePlaylist(id); vm.takeToAir()
        vm.advance(8_000)                                      // en pleno video
        assertEquals("v.mp4", vm.state.value.breakState!!.showing.title)
        assertEquals(AudioMix(0f, 1f), vm.state.value.audioMix)

        vm.backToMatch()
        val s = vm.state.value
        assertEquals(SOURCE_CAMERA_2, s.programSourceId)       // regresa a la cámara elegida
        assertNull(s.breakState)                               // el anuncio (y su audio) se detiene
        assertEquals(AudioMix(1f, 0f), s.audioMix)             // mezcla anterior restaurada
        assertNull(s.savedMix)
        vm.advance(60_000)                                     // el reloj ya no mueve nada
        assertNull(vm.state.value.breakState)
        // Volver a ponerla al aire empieza otra vez desde el comienzo.
        vm.takeToAir()
        assertEquals("a.png", vm.state.value.breakState!!.showing.title)
    }

    @Test fun returnCameraCanBeChosenAndBackToMatchIsNoOpWithoutBreak() {
        val vm = vm()
        vm.backToMatch()
        assertNull(vm.state.value.programSourceId)
        val id = vm.withMixedList()
        vm.setReturnSource(SOURCE_CAMERA_2)
        vm.setReturnSource(SOURCE_BREAK)                       // no es una cámara: se ignora
        vm.preparePlaylist(id); vm.takeToAir()                 // no había cámara al aire: usa la elegida
        vm.backToMatch()
        assertEquals(SOURCE_CAMERA_2, vm.state.value.programSourceId)
    }

    @Test fun puttingACameraOnAirDuringABreakAlsoEndsItAndRestoresAudio() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.preparePlaylist(id); vm.takeToAir()
        vm.selectSource(SOURCE_CAMERA_1)
        assertTrue(vm.state.value.breakOnAir)                  // seleccionar no interrumpe
        vm.takeToAir()
        val s = vm.state.value
        assertEquals(SOURCE_CAMERA_1, s.programSourceId)
        assertNull(s.breakState)
        assertEquals(AudioMix(1f, 0f), s.audioMix)
    }

    @Test fun autoReturnGoesBackToCameraWhenTheListEnds() {
        val vm = vm()
        vm.selectSource(SOURCE_CAMERA_1); vm.takeToAir()
        val id = vm.withMixedList()
        vm.setAutoReturn(id, true)
        vm.preparePlaylist(id); vm.takeToAir()
        vm.advance(22_000)
        val s = vm.state.value
        assertEquals(SOURCE_CAMERA_1, s.programSourceId)
        assertNull(s.breakState)
        assertEquals(AudioMix(1f, 0f), s.audioMix)
    }

    @Test fun incompatibleFileWarnsShowsFallbackAndDoesNotBlock() {
        val vm = vm()
        val id = vm.withMixedList(img("a"), vid("roto", compat = Compat.Unsupported, note = "códec no soportado"), img("b"))
        vm.preparePlaylist(id)
        assertTrue(vm.state.value.message!!.contains("1 aviso"))           // se valida ANTES de salir al aire
        vm.consumeMessage()
        vm.takeToAir()
        vm.advance(6_000)
        val s = vm.state.value
        assertTrue(s.breakState!!.showing is Showing.Fallback)
        assertTrue(s.message!!.contains("roto.mp4") && s.message!!.contains("placa de respaldo"))
        vm.consumeMessage()
        vm.advance(BreakPlayer.FALLBACK_MS)
        assertEquals("b.png", vm.state.value.breakState!!.showing.title)   // la lista siguió sola
        // La aplicación sigue respondiendo: se puede volver al partido.
        vm.backToMatch()
        assertNull(vm.state.value.breakState)
    }

    @Test fun runtimeFailureReportedByTheEngineAlsoFallsBack() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.preparePlaylist(id); vm.takeToAir()
        vm.breakItemFailed("error de lectura")
        assertTrue(vm.state.value.breakState!!.showing is Showing.Fallback)
        assertTrue(vm.state.value.message!!.contains("error de lectura"))
    }

    @Test fun pauseNextStopControls() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.preparePlaylist(id); vm.takeToAir()
        vm.breakTogglePause()
        vm.advance(30_000)
        assertEquals("a.png", vm.state.value.breakState!!.showing.title)   // en pausa no avanza
        vm.breakTogglePause(); vm.breakNext()
        assertEquals("v.mp4", vm.state.value.breakState!!.showing.title)
        vm.breakStop()
        assertTrue(vm.state.value.breakState!!.showing is Showing.Hold)
        assertEquals(SOURCE_BREAK, vm.state.value.programSourceId)         // detener NO vuelve a cámara
    }

    @Test fun anotherListCanReplaceTheOneOnAirWithoutLosingTheSavedMix() {
        val vm = vm()
        val first = vm.withMixedList()
        vm.createPlaylist(Template.HalfTime)
        val second = vm.state.value.editingPlaylistId!!
        vm.addEntry(second, "b")
        vm.preparePlaylist(first); vm.takeToAir()
        vm.preparePlaylist(second)
        assertTrue(vm.state.value.canTakeToAir)
        assertEquals("a.png", vm.state.value.breakState!!.showing.title)   // preparar otra no interrumpe la que está al aire
        vm.takeToAir()
        assertEquals("b.png", vm.state.value.breakState!!.showing.title)
        assertEquals(AudioMix(1f, 0f), vm.state.value.savedMix)            // sigue guardada la mezcla del partido
        vm.backToMatch()
        assertEquals(AudioMix(1f, 0f), vm.state.value.audioMix)
    }

    @Test fun libraryAndListsAreSavedAndReloaded() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.setLoop(id, true)
        vm.moveEntry(id, vm.state.value.content.playlist(id)!!.entries.last().entryId, -1)
        assertTrue(store.saves > 0)
        val reloaded = StudioViewModel(DemoMediaEngine, DemoScoreboardSource, { 0L }, ticks, store)
        val p = reloaded.state.value.content.playlists.single()
        assertEquals(listOf("a", "b", "v"), p.entries.map { it.mediaId })
        assertTrue(p.loop)
        assertEquals(3, reloaded.state.value.content.library.size)
    }

    @Test fun removingAListOrFileKeepsTheStudioConsistent() {
        val vm = vm()
        val id = vm.withMixedList()
        vm.preparePlaylist(id)
        vm.removeMedia("v")
        assertEquals(listOf("a", "b"), vm.state.value.content.playlist(id)!!.entries.map { it.mediaId })
        vm.removePlaylist(id)
        assertNull(vm.state.value.preparedPlaylistId)
        assertFalse(vm.state.value.canTakeToAir)
        assertEquals(ContentState().playlists, vm.state.value.content.playlists)
        assertNotNull(vm.state.value.content.media("a"))                   // la biblioteca conserva lo demás
    }

    @Test fun importReportsDuplicatesAndIncompatibles() {
        val vm = vm()
        vm.importMedia(listOf(img("a"), vid("x", compat = Compat.Unsupported)))
        assertTrue(vm.state.value.message!!.contains("2 archivos") && vm.state.value.message!!.contains("1 no son compatibles"))
        vm.importMedia(listOf(img("a")))
        assertTrue(vm.state.value.message!!.contains("ya estaban"))
        assertEquals(2, vm.state.value.content.library.size)
    }
}
