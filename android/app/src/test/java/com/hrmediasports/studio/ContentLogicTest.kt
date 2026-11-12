package com.hrmediasports.studio

import com.hrmediasports.studio.content.AudioMix
import com.hrmediasports.studio.content.AudioRules
import com.hrmediasports.studio.content.BreakPlayer
import com.hrmediasports.studio.content.Compat
import com.hrmediasports.studio.content.ContentEditor
import com.hrmediasports.studio.content.ContentJson
import com.hrmediasports.studio.content.ContentState
import com.hrmediasports.studio.content.MatchAudioPolicy
import com.hrmediasports.studio.content.MediaItem
import com.hrmediasports.studio.content.MediaType
import com.hrmediasports.studio.content.OverlayKind
import com.hrmediasports.studio.content.PlayerEvent
import com.hrmediasports.studio.content.Showing
import com.hrmediasports.studio.content.Template
import com.hrmediasports.studio.content.formatDuration
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ContentLogicTest {
    private fun img(id: String, compat: Compat = Compat.Ok) = MediaItem(id, "content://img/$id", "$id.png", MediaType.Image, compat = compat)
    private fun vid(id: String, ms: Long? = 15_000, compat: Compat = Compat.Ok, note: String? = null) =
        MediaItem(id, "content://vid/$id", "$id.mp4", MediaType.Video, durationMs = ms, compat = compat, compatNote = note)

    /** Lista mixta: imagen, video, imagen. */
    private fun mixed(loop: Boolean = false, autoReturn: Boolean = false): ContentState {
        var s = ContentEditor.addMedia(ContentState(), listOf(img("a"), vid("v"), img("b")))
        s = ContentEditor.createFromTemplate(s, Template.Timeout, "p")
        s = ContentEditor.addEntry(s, "p", "a", "e1")
        s = ContentEditor.addEntry(s, "p", "v", "e2")
        s = ContentEditor.addEntry(s, "p", "b", "e3")
        s = ContentEditor.setLoop(s, "p", loop)
        return ContentEditor.setAutoReturn(s, "p", autoReturn)
    }

    private fun player(s: ContentState) = BreakPlayer { s.media(it) }

    // ---------- Biblioteca y listas ----------

    @Test fun importDoesNotDuplicateTheSameFile() {
        var s = ContentEditor.addMedia(ContentState(), listOf(img("a"), img("a")))
        s = ContentEditor.addMedia(s, listOf(img("a"), vid("v")))
        assertEquals(listOf("a", "v"), s.library.map { it.id })
    }

    @Test fun removingFromLibraryOnlyRemovesReferences() {
        var s = mixed()
        s = ContentEditor.addOverlay(s, "a", OverlayKind.Logo, "o1")
        s = ContentEditor.removeMedia(s, "a")
        assertNull(s.media("a"))
        assertEquals(listOf("e2", "e3"), s.playlist("p")!!.entries.map { it.entryId })
        assertTrue(s.overlays.isEmpty())
        // La URI del archivo nunca se toca: solo desaparece la referencia. (No hay ninguna operación de borrado de archivos.)
    }

    @Test fun templatesHaveSensibleDefaultsAndUniqueNames() {
        var s = ContentEditor.createFromTemplate(ContentState(), Template.HalfTime, "h")
        s = ContentEditor.createFromTemplate(s, Template.HalfTime, "h2")
        s = ContentEditor.createFromTemplate(s, Template.Timeout, "t")
        s = ContentEditor.createFromTemplate(s, Template.BackSoon, "b")
        assertEquals(listOf("Medio tiempo", "Medio tiempo 2", "Tiempo muerto", "Volvemos en breve"), s.playlists.map { it.name })
        assertTrue(s.playlist("h")!!.loop)
        assertFalse(s.playlist("t")!!.loop)
        assertEquals("Volvemos en breve", s.playlist("b")!!.slateText)
    }

    @Test fun reorderAndRemoveEntries() {
        var s = mixed()
        s = ContentEditor.moveEntry(s, "p", "e3", -1)
        assertEquals(listOf("e1", "e3", "e2"), s.playlist("p")!!.entries.map { it.entryId })
        s = ContentEditor.moveEntry(s, "p", "e1", -1)          // ya es el primero: no cambia
        s = ContentEditor.moveEntry(s, "p", "e2", +1)          // ya es el último: no cambia
        assertEquals(listOf("e1", "e3", "e2"), s.playlist("p")!!.entries.map { it.entryId })
        s = ContentEditor.removeEntry(s, "p", "e3")
        assertEquals(listOf("e1", "e2"), s.playlist("p")!!.entries.map { it.entryId })
        assertEquals(3, s.library.size)                        // la biblioteca no cambia
    }

    @Test fun sameFileCanAppearTwiceInAList() {
        var s = mixed()
        s = ContentEditor.addEntry(s, "p", "a", "e4")
        assertEquals(listOf("a", "v", "b", "a"), s.playlist("p")!!.entries.map { it.mediaId })
        assertEquals(mixed(), ContentEditor.addEntry(mixed(), "p", "no-existe", "x"))   // media desconocida: se ignora
    }

    @Test fun settingsAreClampedAndPerEntryScoreboardToggles() {
        var s = mixed()
        assertEquals(120, ContentEditor.setImageSeconds(s, "p", 999).playlist("p")!!.imageSeconds)
        assertEquals(1, ContentEditor.setImageSeconds(s, "p", 0).playlist("p")!!.imageSeconds)
        s = ContentEditor.toggleScoreboard(s, "p", "e2")
        assertEquals(listOf(false, true, false), s.playlist("p")!!.entries.map { it.showScoreboard })
    }

    @Test fun overlaysMustBeImages() {
        val s = mixed()
        assertEquals(1, ContentEditor.addOverlay(s, "a", OverlayKind.Strip, "o").overlays.size)
        assertTrue(ContentEditor.addOverlay(s, "v", OverlayKind.Logo, "o").overlays.isEmpty())
        val on = ContentEditor.toggleOverlay(ContentEditor.addOverlay(s, "a", OverlayKind.Logo, "o"), "o")
        assertTrue(on.overlays.single().enabled)
    }

    @Test fun validationReportsProblemsBeforeGoingOnAir() {
        var s = ContentEditor.addMedia(ContentState(), listOf(img("a"), vid("roto", compat = Compat.Unsupported, note = "códec no soportado"), vid("sindur", ms = null), img("nuevo", Compat.Unchecked)))
        s = ContentEditor.createFromTemplate(s, Template.HalfTime, "p")
        assertTrue(ContentEditor.issues(s, s.playlist("p")!!).single().contains("vacía"))
        listOf("a", "roto", "sindur", "nuevo").forEachIndexed { i, m -> s = ContentEditor.addEntry(s, "p", m, "e$i") }
        val issues = ContentEditor.issues(s, s.playlist("p")!!)
        assertEquals(3, issues.size)
        assertTrue(issues.any { it.contains("roto.mp4") && it.contains("códec no soportado") })
        assertTrue(issues.any { it.contains("sindur.mp4") && it.contains("duración") })
        assertTrue(issues.any { it.contains("nuevo.png") && it.contains("validó") })
    }

    @Test fun totalDurationOfAMixedList() {
        val s = mixed()
        // Tiempo muerto: imágenes de 6 s + video de 15 s + imagen de 6 s
        assertEquals(27_000, ContentEditor.totalDurationMs(s, s.playlist("p")!!))
        assertEquals("0:27", formatDuration(27_000))
        assertEquals("1:05", formatDuration(64_200))
    }

    // ---------- Reproducción (secuencia) ----------

    @Test fun mixedListPlaysImagesAndVideosInOrderFromTheBeginning() {
        val s = mixed(); val p = player(s)
        var st = p.start(s.playlist("p")!!).state
        assertEquals("a.png", st.showing.title); assertEquals(6_000L, st.durationMs)
        assertEquals("v.mp4", p.nextTitle(st))
        st = p.tick(st, 5_999).state
        assertEquals("a.png", st.showing.title); assertEquals(1L, st.remainingMs)
        st = p.tick(st, 1).state
        assertEquals("v.mp4", st.showing.title); assertEquals(15_000L, st.durationMs)   // el video dura lo que dura el archivo
        st = p.tick(st, 15_000).state
        assertEquals("b.png", st.showing.title)
        assertEquals("Placa de espera", p.nextTitle(st))
        st = p.tick(st, 6_000).state
        assertTrue(st.showing is Showing.Hold)                                          // fin de lista → placa de espera
        assertEquals("Tiempo muerto", (st.showing as Showing.Hold).text)
        assertNull(st.durationMs)
        // Poner al aire otra vez empieza desde el comienzo.
        assertEquals("a.png", p.start(s.playlist("p")!!).state.showing.title)
    }

    @Test fun loopRestartsAndAutoReturnAsksToGoBackToCamera() {
        val loop = mixed(loop = true); val pl = player(loop)
        var st = pl.start(loop.playlist("p")!!).state
        st = pl.tick(st, 6_000).state; st = pl.tick(st, 15_000).state
        assertEquals("a.png", pl.nextTitle(st))
        st = pl.tick(st, 6_000).state
        assertEquals("a.png", st.showing.title)                                         // repite

        val auto = mixed(autoReturn = true); val pa = player(auto)
        var sa = pa.start(auto.playlist("p")!!).state
        sa = pa.tick(sa, 6_000).state; sa = pa.tick(sa, 15_000).state
        assertEquals("Volver a la cámara (automático)", pa.nextTitle(sa))
        val end = pa.tick(sa, 6_000)
        assertTrue(end.events.contains(PlayerEvent.AutoReturn))
        // Sin retorno automático no se pide volver.
        val manual = mixed(); val pm = player(manual)
        var sm = pm.start(manual.playlist("p")!!).state
        sm = pm.tick(sm, 6_000).state; sm = pm.tick(sm, 15_000).state
        assertTrue(pm.tick(sm, 6_000).events.none { it is PlayerEvent.AutoReturn })
    }

    @Test fun pauseNextAndStop() {
        val s = mixed(); val p = player(s)
        var st = p.start(s.playlist("p")!!).state
        st = p.togglePause(st).state
        assertEquals(0L, p.tick(st, 60_000).state.elapsedMs)                            // en pausa no avanza
        st = p.togglePause(st).state
        st = p.next(st).state
        assertEquals("v.mp4", st.showing.title); assertEquals(0L, st.elapsedMs)
        st = p.stop(st).state
        assertTrue(st.showing is Showing.Hold)                                          // detener deja la placa, no vuelve a cámara
        assertEquals("a.png", p.next(st).state.showing.title)                           // «Siguiente» desde la placa reinicia
    }

    @Test fun manualNextOnLastItemDoesNotTriggerAutoReturn() {
        val s = mixed(autoReturn = true); val p = player(s)
        var st = p.start(s.playlist("p")!!).state
        st = p.next(st).state; st = p.next(st).state
        val end = p.next(st)
        assertTrue(end.state.showing is Showing.Hold)
        assertTrue(end.events.none { it is PlayerEvent.AutoReturn })
    }

    @Test fun incompatibleFileShowsFallbackSlateWarnsAndContinues() {
        var s = ContentEditor.addMedia(ContentState(), listOf(img("a"), vid("roto", compat = Compat.Unsupported, note = "códec no soportado"), img("b")))
        s = ContentEditor.createFromTemplate(s, Template.Timeout, "p")
        listOf("a", "roto", "b").forEachIndexed { i, m -> s = ContentEditor.addEntry(s, "p", m, "e$i") }
        val p = player(s)
        var st = p.start(s.playlist("p")!!).state
        val step = p.tick(st, 6_000)
        st = step.state
        val fb = st.showing as Showing.Fallback
        assertEquals("roto.mp4", fb.mediaName)
        assertEquals(BreakPlayer.FALLBACK_MS, st.durationMs)
        val notice = step.events.filterIsInstance<PlayerEvent.Notice>().single().text
        assertTrue(notice.contains("roto.mp4") && notice.contains("códec no soportado") && notice.contains("continúa"))
        assertEquals("b.png", p.nextTitle(st))
        st = p.tick(st, BreakPlayer.FALLBACK_MS).state
        assertEquals("b.png", st.showing.title)                                         // no se bloquea: sigue con el siguiente
    }

    @Test fun missingRemovedAndRuntimeFailuresAlsoFallBack() {
        var s = ContentEditor.addMedia(ContentState(), listOf(img("falta", Compat.Missing), vid("v")))
        s = ContentEditor.createFromTemplate(s, Template.Timeout, "p")
        s = ContentEditor.addEntry(s, "p", "falta", "e1"); s = ContentEditor.addEntry(s, "p", "v", "e2")
        val p = player(s)
        val first = p.start(s.playlist("p")!!)
        assertTrue(first.state.showing is Showing.Fallback)
        assertEquals(1, first.events.size)
        var st = p.tick(first.state, BreakPlayer.FALLBACK_MS).state
        assertEquals("v.mp4", st.showing.title)
        // Fallo en plena reproducción, informado por el motor.
        val failed = p.itemFailed(st, "error de decodificación")
        assertTrue(failed.state.showing is Showing.Fallback)
        assertTrue((failed.events.single() as PlayerEvent.Notice).text.contains("error de decodificación"))
        st = p.tick(failed.state, BreakPlayer.FALLBACK_MS).state
        assertTrue(st.showing is Showing.Hold)
        // Un archivo quitado de la biblioteca después de armar la lista.
        val gone = ContentEditor.addEntry(s, "p", "v", "e3").let { it.copy(library = it.library.filterNot { m -> m.id == "v" }) }
        val pg = BreakPlayer { gone.media(it) }
        val st2 = pg.tick(pg.start(gone.playlist("p")!!).state, BreakPlayer.FALLBACK_MS)
        assertTrue(st2.state.showing is Showing.Fallback)
    }

    @Test fun emptyListShowsTheHoldSlate() {
        val s = ContentEditor.createFromTemplate(ContentState(), Template.BackSoon, "p")
        val p = player(s)
        val st = p.start(s.playlist("p")!!).state
        assertEquals("Volvemos en breve", (st.showing as Showing.Hold).text)
        assertEquals("—", p.nextTitle(st))
        assertTrue(p.next(st).state.showing is Showing.Hold)
    }

    // ---------- Audio ----------

    @Test fun audioPolicyDuringBreak() {
        val before = AudioMix(matchGain = 0.8f, adGain = 0f)
        assertEquals(AudioMix(0.8f, 1f), AudioRules.duringBreak(before, MatchAudioPolicy.Keep))
        assertEquals(AudioMix(AudioRules.REDUCED_GAIN, 1f), AudioRules.duringBreak(before, MatchAudioPolicy.Reduce))
        assertEquals(AudioMix(0f, 1f), AudioRules.duringBreak(before, MatchAudioPolicy.Mute))
        // «Reducir» nunca sube un partido que ya estaba más bajo.
        assertEquals(0.1f, AudioRules.duringBreak(AudioMix(0.1f, 0f), MatchAudioPolicy.Reduce).matchGain)
    }

    // ---------- Guardado ----------

    @Test fun savedStateRoundTripsAndCorruptDataDoesNotCrash() {
        val s = ContentEditor.addOverlay(mixed(loop = true), "a", OverlayKind.Strip, "o")
        assertEquals(s, ContentJson.decode(ContentJson.encode(s)))
        assertEquals(ContentState(), ContentJson.decode("{ esto no es json"))
        assertEquals(ContentState(), ContentJson.decode(null))
        // Campos desconocidos de una versión futura no rompen la carga.
        assertEquals(ContentState(), ContentJson.decode("""{"version":1,"campoNuevo":true}"""))
    }

    @Test fun nameKeepsWhatIsTypedAndHasADisplayFallback() {
        var s = mixed()
        s = ContentEditor.rename(s, "p", "Medio tiempo ")                    // el espacio final no se pierde al escribir
        assertEquals("Medio tiempo ", s.playlist("p")!!.name)
        assertEquals("Medio tiempo", ContentEditor.displayName(s.playlist("p")!!))
        s = ContentEditor.rename(s, "p", "")                                 // se puede borrar todo para reescribir
        assertEquals("", s.playlist("p")!!.name)
        assertEquals("Lista sin nombre", ContentEditor.displayName(s.playlist("p")!!))
        assertEquals(40, ContentEditor.rename(s, "p", "x".repeat(99)).playlist("p")!!.name.length)
    }

    @Test fun revalidationUpdatesFilesWithoutBreakingLists() {
        val s = mixed()
        val gone = s.media("v")!!.copy(compat = Compat.Missing, compatNote = "se perdió el permiso de lectura")
        val after = ContentEditor.updateMedia(s, listOf(gone, img("no-esta-en-biblioteca")))
        assertEquals(Compat.Missing, after.media("v")!!.compat)
        assertEquals(3, after.library.size)                                  // no añade archivos desconocidos
        assertEquals(s.playlist("p"), after.playlist("p"))                   // las listas no cambian
        assertTrue(ContentEditor.issues(after, after.playlist("p")!!).single().contains("no se encuentra"))
    }
}
