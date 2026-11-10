package com.hrmediasports.studio.shots

import android.graphics.Bitmap
import android.graphics.Canvas
import android.view.View
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.Composable
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.test.core.app.ActivityScenario
import com.hrmediasports.studio.content.Compat
import com.hrmediasports.studio.content.MediaItem
import com.hrmediasports.studio.content.MediaType
import com.hrmediasports.studio.content.OverlayKind
import com.hrmediasports.studio.content.Template
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.ui.ContentTab
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.engine.ScoreboardSnapshot
import com.hrmediasports.studio.engine.ScoreboardSource
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.ui.SOURCE_RESULT
import com.hrmediasports.studio.ui.SOURCE_CAMERA_2
import com.hrmediasports.studio.ui.StudioPanel
import com.hrmediasports.studio.ui.StudioApp
import com.hrmediasports.studio.ui.StudioViewModel
import com.hrmediasports.studio.ui.theme.HrTheme
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.emptyFlow
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.io.File

private class FixedSource(snapshot: ScoreboardSnapshot) : ScoreboardSource {
    override val snapshot: StateFlow<ScoreboardSnapshot> = MutableStateFlow(snapshot)
}

/**
 * Genera capturas del Estudio en distintos tamaños (en dp), densidades y escalas de texto.
 * Se renderiza con Robolectric (gráficos nativos) y no sustituye a una prueba en dispositivo real.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34])
class ScreenshotTest {
    @get:Rule val rule = createEmptyComposeRule()

    private fun vm(
        source: ScoreboardSource? = null,
        setup: StudioViewModel.() -> Unit = {},
    ): StudioViewModel {
        val src = source ?: com.hrmediasports.studio.engine.DemoScoreboardSource
        return StudioViewModel(DemoMediaEngine, src, { 100_000L }, emptyFlow()).apply(setup)
    }

    private fun shot(
        name: String,
        wDp: Int,
        hDp: Int,
        dpi: Int = 160,
        fontScale: Float = 1f,
        viewModel: StudioViewModel = vm(),
        portrait: Boolean = false,
        manualClock: Boolean = false,
        content: (@Composable () -> Unit)? = null,
    ) {
        RuntimeEnvironment.setQualifiers("w${wDp}dp-h${hDp}dp-${if (portrait) "port" else "land"}-${dpi}dpi")
        // Con un campo de texto enfocado el cursor parpadea sin fin y Compose nunca queda «en reposo»: se avanza el reloj a mano.
        if (manualClock) rule.mainClock.autoAdvance = false
        // Escala de texto REAL del sistema (como en Ajustes ▸ Pantalla): también llega a diálogos y ventanas.
        RuntimeEnvironment.setFontScale(fontScale)
        val scenario = ActivityScenario.launch(ComponentActivity::class.java)
        scenario.onActivity { activity ->
            activity.setContent { HrTheme { if (content != null) content() else StudioApp(viewModel) } }
        }
        if (manualClock) rule.mainClock.advanceTimeBy(600) else rule.waitForIdle()
        scenario.onActivity { activity ->
            val metrics = activity.resources.displayMetrics
            val w = metrics.widthPixels
            val h = metrics.heightPixels
            val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
            val canvas = Canvas(bmp)
            // Se dibujan TODAS las ventanas de la app (actividad + diálogos), de atrás hacia delante.
            val roots = rootViews()
            val activityRoot = activity.window.decorView
            (listOf(activityRoot) + roots.filter { it !== activityRoot }).forEachIndexed { i, root ->
                val dialog = i > 0
                val lp = root.layoutParams as? WindowManager.LayoutParams
                root.measure(
                    View.MeasureSpec.makeMeasureSpec(w, if (dialog && lp?.width != WindowManager.LayoutParams.MATCH_PARENT) View.MeasureSpec.AT_MOST else View.MeasureSpec.EXACTLY),
                    View.MeasureSpec.makeMeasureSpec(h, if (dialog && lp?.height != WindowManager.LayoutParams.MATCH_PARENT) View.MeasureSpec.AT_MOST else View.MeasureSpec.EXACTLY),
                )
                val vw = root.measuredWidth
                val vh = root.measuredHeight
                val left = if (dialog) (w - vw) / 2 else 0
                val top = if (dialog) (h - vh) / 2 else 0
                root.layout(left, top, left + vw, top + vh)
                if (dialog && lp != null && (lp.flags and WindowManager.LayoutParams.FLAG_DIM_BEHIND) != 0) {
                    canvas.drawColor(android.graphics.Color.argb((lp.dimAmount * 255).toInt(), 0, 0, 0))
                }
                canvas.save()
                canvas.translate(left.toFloat(), top.toFloat())
                root.draw(canvas)
                canvas.restore()
            }
            val dir = File("build/screenshots").also { it.mkdirs() }
            File(dir, "$name.png").outputStream().use { bmp.compress(Bitmap.CompressFormat.PNG, 100, it) }
        }
        scenario.close()
    }

    /** Vistas raíz de todas las ventanas abiertas (la actividad y los diálogos). */
    @Suppress("UNCHECKED_CAST")
    private fun rootViews(): List<View> {
        val wmg = Class.forName("android.view.WindowManagerGlobal")
        val inst = wmg.getMethod("getInstance").invoke(null)
        val names = wmg.getMethod("getViewRootNames").invoke(inst) as Array<String>
        val get = wmg.getMethod("getRootView", String::class.java)
        return names.mapNotNull { get.invoke(inst, it) as? View }
    }

    private fun onAirScenario(): StudioViewModel.() -> Unit = {
        selectSource(SOURCE_RESULT); takeToAir(); selectSource(SOURCE_CAMERA_2)
    }

    // --- Tamaños pedidos, en dp (1 dp = 1 px a 160 dpi) ---
    @Test fun dp960x540() = shot("01-960x540dp", 960, 540, viewModel = vm(setup = onAirScenario()))
    @Test fun dp1280x720() = shot("02-1280x720dp", 1280, 720, viewModel = vm(setup = onAirScenario()))
    @Test fun dp1920x1080() = shot("03-1920x1080dp", 1920, 1080, viewModel = vm(setup = onAirScenario()))

    // --- Los mismos tamaños en PÍXELES con densidades reales: no equivalen a dp ---
    @Test fun px960x540hdpi() = shot("04-960x540px-240dpi-640x360dp", 640, 360, 240, viewModel = vm(setup = onAirScenario()))
    @Test fun px1280x720hdpi() = shot("05-1280x720px-240dpi-853x480dp", 853, 480, 240, viewModel = vm(setup = onAirScenario()))
    @Test fun px1920x1080hdpi() = shot("06-1920x1080px-240dpi-1280x720dp", 1280, 720, 240, viewModel = vm(setup = onAirScenario()))
    @Test fun phone731x411() = shot("07-celular-731x411dp-420dpi", 731, 411, 420, viewModel = vm(setup = onAirScenario()))

    // --- Escala de texto ---
    @Test fun font13at960() = shot("08-960x540dp-texto-1.3", 960, 540, fontScale = 1.3f, viewModel = vm(setup = onAirScenario()))
    @Test fun font20at960() = shot("09-960x540dp-texto-2.0", 960, 540, fontScale = 2.0f, viewModel = vm(setup = onAirScenario()))
    @Test fun font13at640() = shot("10-640x360dp-texto-1.3", 640, 360, 240, 1.3f, vm(setup = onAirScenario()))
    @Test fun font20at640() = shot("11-640x360dp-texto-2.0", 640, 360, 240, 2.0f, vm(setup = onAirScenario()))

    // --- Estados ---
    @Test fun trayOpen() = shot("12-960x540dp-bandeja-abierta", 960, 540, viewModel = vm(setup = { onAirScenario()(this); toggleReplaysTray() }))
    @Test fun lowPreviewTab() = shot("13-640x360dp-pestana-previa", 640, 360, 240, viewModel = vm(setup = {
        onAirScenario()(this); setMonitorView(com.hrmediasports.studio.ui.MonitorView.Preview)
    }))
    @Test fun manualCorrection() = shot("14-960x540dp-correccion-manual", 960, 540, viewModel = vm(setup = {
        onAirScenario()(this); openCorrection(); applyCorrection()
    }))
    @Test fun neverReceived() = shot("15-960x540dp-sin-datos-nunca", 960, 540, viewModel = vm(
        source = FixedSource(ScoreboardSnapshot(null, ReadingOrigin.Remote, LinkState.Connected, null, null)),
        setup = onAirScenario(),
    ))
    @Test fun connectionLost() = shot("16-960x540dp-conexion-perdida", 960, 540, viewModel = vm(
        source = FixedSource(
            ScoreboardSnapshot(MatchState(52, 49, 140, 3, 9), ReadingOrigin.Remote, LinkState.NotConnected, 88_000L, 0.9f),
        ),
        setup = onAirScenario(),
    ))

    // --- Lector de pizarra (vertical) ---
    @Test fun reader() = shot("17-lector-360x780dp", 360, 780, 160, portrait = true, content = { com.hrmediasports.studio.ui.reader.ReaderApp() })
    @Test fun reader13() = shot("18-lector-360x780dp-texto-1.3", 360, 780, 160, 1.3f, portrait = true, content = { com.hrmediasports.studio.ui.reader.ReaderApp() })

    // --- Paneles específicos y diálogos (ventana baja y ancha) ---
    @Test fun repliesPanelLow() = shot("21-640x360dp-panel-repeticiones", 640, 360, 240, viewModel = vm(setup = { onAirScenario()(this); openPanel(StudioPanel.Replays) }))
    @Test fun scoreboardPanelLow() = shot("22-640x360dp-panel-marcador", 640, 360, 240, viewModel = vm(setup = { onAirScenario()(this); openPanel(StudioPanel.Scoreboard) }))
    @Test fun correctionDialogWide() = shot("23-960x540dp-dialogo-correccion", 960, 540, manualClock = true, viewModel = vm(setup = { onAirScenario()(this); openCorrection() }))
    @Test fun correctionDialogLow() = shot("24-640x360dp-dialogo-correccion", 640, 360, 240, manualClock = true, viewModel = vm(setup = { onAirScenario()(this); openCorrection() }))
    @Test fun correctionDialogLowBig() = shot("25-640x360dp-dialogo-correccion-texto-2.0", 640, 360, 240, 2.0f, manualClock = true, viewModel = vm(setup = { onAirScenario()(this); openCorrection() }))
    @Test fun repliesPanelBig() = shot("26-640x360dp-panel-repeticiones-texto-2.0", 640, 360, 240, 2.0f, vm(setup = { onAirScenario()(this); openPanel(StudioPanel.Replays) }))
    @Test fun scoreboardPanelBig() = shot("27-640x360dp-panel-marcador-texto-2.0", 640, 360, 240, 2.0f, vm(setup = { onAirScenario()(this); openPanel(StudioPanel.Scoreboard) }))
    @Test fun trayOpenWide() = shot("28-1280x720dp-bandeja-abierta", 1280, 720, viewModel = vm(setup = { onAirScenario()(this); toggleReplaysTray() }))
    @Test fun mid853Big() = shot("29-853x480dp-texto-2.0", 853, 480, 240, 2.0f, vm(setup = onAirScenario()))
    @Test fun small640Big15() = shot("30-640x360dp-texto-1.5", 640, 360, 240, 1.5f, vm(setup = onAirScenario()))
    @Test fun wide1280Big20() = shot("31-1280x720dp-texto-2.0", 1280, 720, 160, 2.0f, vm(setup = onAirScenario()))
    @Test fun small640BigNoOnAir() = shot("32-640x360dp-texto-2.0-sin-salida", 640, 360, 240, 2.0f)

    // --- Módulo de contenidos para descansos ---
    private fun img(id: String, compat: Compat = Compat.Ok) = MediaItem(id, "content://demo/$id", "$id.png", MediaType.Image, width = 1920, height = 1080, compat = compat)
    private fun vid(id: String, compat: Compat = Compat.Ok, note: String? = null) =
        MediaItem(id, "content://demo/$id", "$id.mp4", MediaType.Video, durationMs = 15_000, width = 1920, height = 1080, compat = compat, compatNote = note, hardwareDecoder = if (compat == Compat.Ok) true else null)

    /** Biblioteca y dos listas de ejemplo. Devuelve el id de «Tiempo muerto». */
    private fun StudioViewModel.seed(): String {
        importMedia(listOf(img("patrocinador-1"), vid("anuncio-tienda"), img("patrocinador-2"), vid("formato-raro", Compat.Unsupported, "sin decodificador para video/x-desconocido")))
        consumeMessage()
        createPlaylist(Template.HalfTime)
        val half = state.value.editingPlaylistId!!
        addEntry(half, "patrocinador-1"); addEntry(half, "anuncio-tienda"); addEntry(half, "formato-raro")
        createPlaylist(Template.Timeout)
        val timeout = state.value.editingPlaylistId!!
        addEntry(timeout, "patrocinador-1"); addEntry(timeout, "anuncio-tienda"); addEntry(timeout, "patrocinador-2")
        toggleEntryScoreboard(timeout, state.value.content.playlist(timeout)!!.entries[0].entryId)
        editPlaylist(null)
        selectSource(SOURCE_CAMERA_2); takeToAir()
        return timeout
    }

    @Test fun contentLists() = shot("40-960x540dp-descansos-listas", 960, 540, viewModel = vm(setup = { seed(); openContentPanel() }))
    @Test fun contentListsLow() = shot("41-640x360dp-descansos-listas", 640, 360, 240, viewModel = vm(setup = { seed(); openContentPanel() }))
    @Test fun contentEditor() = shot("42-960x540dp-descansos-editor", 960, 540, manualClock = true, viewModel = vm(setup = { val id = seed(); openContentPanel(); editPlaylist(id) }))
    @Test fun contentLibrary() = shot("43-960x540dp-descansos-biblioteca", 960, 540, viewModel = vm(setup = { seed(); openContentPanel(); setContentTab(ContentTab.Library) }))
    @Test fun contentAudio() = shot("44-960x540dp-descansos-audio", 960, 540, viewModel = vm(setup = { seed(); openContentPanel(); setContentTab(ContentTab.Audio) }))
    @Test fun contentSponsors() = shot("45-960x540dp-descansos-patrocinadores", 960, 540, viewModel = vm(setup = { seed(); addOverlay("patrocinador-1", OverlayKind.Logo); openContentPanel(); setContentTab(ContentTab.Sponsors) }))
    @Test fun breakPrepared() = shot("46-960x540dp-descanso-preparado", 960, 540, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage() }))
    @Test fun breakOnAirWide() = shot("47-960x540dp-descanso-al-aire", 960, 540, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir() }))
    @Test fun breakOnAirLow() = shot("48-640x360dp-descanso-al-aire", 640, 360, 240, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir() }))
    @Test fun breakOnAirMid() = shot("49-853x480dp-descanso-al-aire", 853, 480, 240, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir() }))
    @Test fun breakOnAirBig() = shot("50-640x360dp-descanso-al-aire-texto-2.0", 640, 360, 240, 2.0f, vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir() }))
    @Test fun breakFallback() = shot("51-960x540dp-descanso-placa-respaldo", 960, 540, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir(); breakItemFailed("error de lectura"); consumeMessage() }))
    @Test fun breakHold() = shot("52-960x540dp-descanso-placa-espera", 960, 540, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir(); breakStop() }))
    @Test fun breakOnAir1280() = shot("53-1280x720dp-descanso-al-aire", 1280, 720, viewModel = vm(setup = { preparePlaylist(seed()); consumeMessage(); takeToAir() }))
}
