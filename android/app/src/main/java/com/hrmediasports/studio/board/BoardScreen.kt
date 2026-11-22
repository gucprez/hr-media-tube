package com.hrmediasports.studio.board

import android.os.SystemClock
import android.util.Size
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.core.resolutionselector.AspectRatioStrategy
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size as GSize
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.data.SharedPrefsZoneTemplateStore
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.link.ClientStatus
import com.hrmediasports.studio.link.LanClient
import com.hrmediasports.studio.link.LanRole
import com.hrmediasports.studio.link.PairScreen
import com.hrmediasports.studio.link.PairingCode
import com.hrmediasports.studio.link.PairingStore
import com.hrmediasports.studio.link.FrameType
import com.hrmediasports.studio.link.ScoreMessage
import com.hrmediasports.studio.live.CAMERA_ONLY
import com.hrmediasports.studio.live.KeepScreenOn
import com.hrmediasports.studio.live.WithPermissions
import com.hrmediasports.studio.ui.theme.HrColors
import kotlinx.coroutines.flow.StateFlow
import java.util.concurrent.Executors

/** Envío real por wifi al Estudio. */
class LanScoreSink(val client: LanClient) : ScoreSink {
    override val status: StateFlow<ClientStatus> get() = client.status
    override fun send(msg: ScoreMessage) {
        client.sticky = stickyScore(msg)
        client.send(FrameType.SCORE, msg.encode())
    }
}

/** Modo PIZARRA: este teléfono mira el marcador del gimnasio (o alguien marca a mano) y lo manda al Estudio. */
@Composable
fun BoardApp(onExit: () -> Unit) {
    val context = LocalContext.current
    val store = remember { PairingStore(context, LanRole.Scoreboard) }
    var pairing by remember { mutableStateOf(store.load()) }
    val p = pairing
    if (p == null) {
        PairScreen("Pizarra: conectar con el Estudio", onPaired = { store.save(it); pairing = it }, onExit = onExit)
        return
    }
    KeepScreenOn()
    val vm: BoardViewModel = viewModel(key = "pizarra-${p.toUri()}", factory = object : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T {
            val client = LanClient(p, LanRole.Scoreboard, store.deviceId, store.deviceName).also { it.start() }
            val vm = BoardViewModel(LanScoreSink(client), SharedPrefsZoneTemplateStore(context), { SystemClock.elapsedRealtime() })
            vm.addCloseable { client.stop() } // al salir del modo Pizarra se corta la conexión
            return vm as T
        }
    })
    BoardScreen(vm, onUnpair = { store.save(null); pairing = null }, onExit = onExit)
}

@Composable
fun BoardScreen(vm: BoardViewModel, onUnpair: () -> Unit, onExit: () -> Unit) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    val link by vm.link.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(HrColors.Background).windowInsetsPadding(WindowInsets.safeDrawing)) {
        Row(Modifier.fillMaxWidth().background(HrColors.Panel).padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("PIZARRA", color = HrColors.Orange, fontWeight = FontWeight.Black, fontSize = 18.sp)
            LinkChip(link)
            Box(Modifier.weight(1f))
            ModeTab("A MANO", ui.mode == BoardMode.Manual) { vm.setMode(BoardMode.Manual) }
            ModeTab("CÁMARA (AUTO)", ui.mode == BoardMode.Auto) { vm.setMode(BoardMode.Auto) }
            TextButton(onClick = onExit, modifier = Modifier.heightIn(min = 48.dp)) { Text("Salir") }
        }
        (link as? ClientStatus.Rejected)?.let {
            Row(Modifier.fillMaxWidth().background(HrColors.Amber).padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(it.reason, color = Color.Black, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Button(onClick = onUnpair) { Text("ESCANEAR DE NUEVO") }
            }
        }
        when (ui.mode) {
            BoardMode.Manual -> ManualBoard(ui, vm, Modifier.weight(1f))
            BoardMode.Auto -> WithPermissions(CAMERA_ONLY, "La lectura automática usa la cámara para mirar el marcador.", onExit = { vm.setMode(BoardMode.Manual) }) {
                AutoBoard(ui, vm, Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun LinkChip(s: ClientStatus) {
    val (t, c) = when (s) {
        is ClientStatus.Connected -> "CONECTADA AL ESTUDIO" to HrColors.Turquoise
        is ClientStatus.Connecting -> "BUSCANDO ESTUDIO…" to HrColors.Amber
        is ClientStatus.Rejected -> "RECHAZADA" to HrColors.OnAirRed
        ClientStatus.Idle -> "SIN CONEXIÓN" to HrColors.TextMuted
    }
    Text(t, color = Color.Black, fontSize = 12.sp, fontWeight = FontWeight.Black,
        modifier = Modifier.clip(RoundedCornerShape(6.dp)).background(c).padding(horizontal = 8.dp, vertical = 4.dp))
}

@Composable
private fun ModeTab(text: String, selected: Boolean, onClick: () -> Unit) {
    Box(Modifier.heightIn(min = 48.dp).clip(RoundedCornerShape(8.dp)).background(if (selected) HrColors.Orange else HrColors.PanelRaised)
        .clickable(onClick = onClick).padding(horizontal = 12.dp), contentAlignment = Alignment.Center) {
        Text(text, color = if (selected) Color.Black else HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 13.sp)
    }
}

// ---------------- A mano ----------------

@Composable
private fun ManualBoard(ui: BoardUiState, vm: BoardViewModel, modifier: Modifier) {
    val s = ui.manual
    Row(modifier.fillMaxWidth().padding(8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        TeamPad("LOCAL", s.home.points, Modifier.weight(1f)) { vm.points(true, it) }
        Column(Modifier.width(210.dp).fillMaxHeight().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(s.clockText(ui.now), color = if (s.running) HrColors.Turquoise else HrColors.TextPrimary, fontSize = 44.sp, fontWeight = FontWeight.Black, fontFamily = FontFamily.Monospace)
            Big(if (s.running) "PARAR RELOJ" else "ARRANCAR RELOJ", if (s.running) HrColors.Amber else HrColors.Turquoise, Color.Black) { vm.toggleClock() }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Small("−1 s") { vm.adjustClock(-1) }; Small("+1 s") { vm.adjustClock(1) }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Small("◀") { vm.previousPeriod() }
                Text(s.periodText(), color = HrColors.TextPrimary, fontSize = 24.sp, fontWeight = FontWeight.Black)
                Small("▶|") { vm.nextPeriod() }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) { for (m in listOf(8, 10, 12)) Small("$m′", if (s.periodMinutes == m) HrColors.Orange else HrColors.PanelRaised) { vm.setMinutes(m) } }
            TextButton(onClick = vm::newGame, modifier = Modifier.heightIn(min = 48.dp)) { Text("Partido nuevo (todo a cero)") }
        }
        TeamPad("VISITA", s.away.points, Modifier.weight(1f)) { vm.points(false, it) }
    }
}

@Composable
private fun TeamPad(label: String, points: Int, modifier: Modifier, onPoints: (Int) -> Unit) {
    Column(modifier.fillMaxHeight().clip(RoundedCornerShape(12.dp)).background(HrColors.Panel).padding(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, color = HrColors.TextMuted, fontWeight = FontWeight.Black)
        Text("$points", color = Color.White, fontSize = 56.sp, fontWeight = FontWeight.Black)
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            for (d in listOf(1, 2, 3)) Box(Modifier.weight(1f)) { Big("+$d", HrColors.Orange, Color.Black) { onPoints(d) } }
        }
        Box(Modifier.fillMaxWidth()) { Big("−1", HrColors.PanelRaised, Color.White) { onPoints(-1) } }
    }
}

@Composable
private fun Big(text: String, bg: Color, fg: Color, onClick: () -> Unit) {
    Box(Modifier.fillMaxWidth().heightIn(min = 56.dp).clip(RoundedCornerShape(10.dp)).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Text(text, color = fg, fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
    }
}

@Composable
private fun Small(text: String, bg: Color = HrColors.PanelRaised, onClick: () -> Unit) {
    Box(Modifier.heightIn(min = 48.dp).width(56.dp).clip(RoundedCornerShape(8.dp)).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Text(text, color = HrColors.TextPrimary, fontWeight = FontWeight.Black)
    }
}

// ---------------- Automático (cámara) ----------------

private val zoneColors = mapOf(ZoneId.Home to Color(0xFFF26A0D), ZoneId.Away to Color(0xFF1E88E5), ZoneId.Clock to Color(0xFF1DE9B6),
    ZoneId.Period to Color(0xFFFFC043), ZoneId.Possession to Color(0xFFE040FB))

@Composable
private fun AutoBoard(ui: BoardUiState, vm: BoardViewModel, modifier: Modifier) {
    Row(modifier.fillMaxWidth().padding(6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        BoxWithConstraints(Modifier.weight(1f).fillMaxHeight(), contentAlignment = Alignment.Center) {
            Box(Modifier.aspectRatio(4f / 3f, matchHeightConstraintsFirst = true)) {
                ScoreboardCamera(ui, vm)
                ZonesOverlay(ui, vm)
            }
        }
        Column(Modifier.width(230.dp).fillMaxHeight().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (ui.editingZones) {
                Text("Arrastra cada recuadro sobre su número en el marcador. Arrastra la esquina inferior derecha para cambiar el tamaño.",
                    color = HrColors.TextPrimary, fontSize = 13.sp)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Leer posesión (24 s)", color = HrColors.TextPrimary, modifier = Modifier.weight(1f))
                    Switch(checked = ui.usePossession, onCheckedChange = { vm.togglePossession() })
                }
            }
            for (id in ZoneId.entries) {
                if (id == ZoneId.Possession && !ui.usePossession) continue
                val zs = ui.zoneStates[id]
                val value = zs?.accepted?.let { if (id == ZoneId.Clock) formatClock(it) else "$it" } ?: "—"
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(Modifier.width(10.dp).heightIn(min = 22.dp).background(zoneColors.getValue(id)))
                    Text(id.label, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Text(value, color = if (zs?.doubtful == false) Color.White else HrColors.Amber, fontWeight = FontWeight.Black, fontSize = 18.sp, fontFamily = FontFamily.Monospace)
                }
            }
            val sent = ui.lastSent
            Text(if (sent != null && sent.auto) "Enviado: ${sent.match.homePoints}–${sent.match.awayPoints} · ${formatClock(sent.match.clockSeconds)} · ${sent.match.period}C"
                else "Sin lectura completa: no se envía nada (el Estudio lo marca desactualizado).",
                color = if (sent?.auto == true) HrColors.Turquoise else HrColors.Amber, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Button(onClick = vm::toggleEditZones, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
                Text(if (ui.editingZones) "LISTO · EMPEZAR A LEER" else "AJUSTAR ZONAS")
            }
            Text("Consejo: teléfono fijo en trípode, todo el marcador en cuadro, sin reflejos. Si duda, se mantiene el último valor seguro.",
                color = HrColors.TextMuted, fontSize = 12.sp)
            ui.message?.let { Text(it, color = HrColors.Turquoise, modifier = Modifier.clickable { vm.consumeMessage() }) }
        }
    }
}

@Composable
private fun ScoreboardCamera(ui: BoardUiState, vm: BoardViewModel) {
    val context = LocalContext.current
    val owner = LocalLifecycleOwner.current
    val zones by rememberUpdatedState(ui.zones)
    val usePoss by rememberUpdatedState(ui.usePossession)
    val previewView = remember { PreviewView(context).apply { scaleType = PreviewView.ScaleType.FIT_CENTER } }
    DisposableEffect(owner) {
        val executor = Executors.newSingleThreadExecutor()
        val future = ProcessCameraProvider.getInstance(context)
        var provider: ProcessCameraProvider? = null
        var lastAt = 0L
        future.addListener({
            val p = future.get(); provider = p
            val selector = ResolutionSelector.Builder().setAspectRatioStrategy(AspectRatioStrategy.RATIO_4_3_FALLBACK_AUTO_STRATEGY).build()
            val preview = Preview.Builder().setResolutionSelector(selector).build().also { it.setSurfaceProvider(previewView.surfaceProvider) }
            val analysis = ImageAnalysis.Builder().setResolutionSelector(selector)
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
            analysis.setAnalyzer(executor) { img ->
                try {
                    val now = SystemClock.elapsedRealtime()
                    if (now - lastAt < 220) return@setAnalyzer // ~4 lecturas/s: suficiente y casi no calienta
                    lastAt = now
                    val plane = img.planes[0]
                    val buf = plane.buffer
                    val w = img.width; val h = img.height; val stride = plane.rowStride
                    val px = IntArray(w * h)
                    for (y in 0 until h) for (x in 0 until w) px[y * w + x] = buf.get(y * stride + x).toInt() and 0xFF
                    val frame = Gray(w, h, px).rotate(img.imageInfo.rotationDegrees)
                    vm.onFrameRead(readZones(frame, zones, usePoss))
                } catch (_: Exception) {
                } finally { img.close() }
            }
            runCatching { p.unbindAll(); p.bindToLifecycle(owner, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis) }
        }, ContextCompat.getMainExecutor(context))
        onDispose { runCatching { provider?.unbindAll() }; executor.shutdown() }
    }
    AndroidView({ previewView }, Modifier.fillMaxSize())
}

@Composable
private fun ZonesOverlay(ui: BoardUiState, vm: BoardViewModel) {
    val zones by rememberUpdatedState(ui.zones)
    val usePoss by rememberUpdatedState(ui.usePossession)
    var dragging by remember { mutableStateOf<Pair<ZoneId, Boolean>?>(null) } // zona, ¿redimensionando?
    Canvas(Modifier.fillMaxSize().then(if (ui.editingZones) Modifier.pointerInput(Unit) {
        detectDragGestures(
            onDragStart = { pos ->
                val nx = pos.x / size.width; val ny = pos.y / size.height
                val ids = ZoneId.entries.filter { usePoss || it != ZoneId.Possession }
                val corner = ids.firstOrNull { val r = zones[it]; kotlin.math.abs(nx - r.right) < 0.05f && kotlin.math.abs(ny - r.bottom) < 0.06f }
                val inside = ids.lastOrNull { val r = zones[it]; nx in r.left..r.right && ny in r.top..r.bottom }
                dragging = (corner ?: inside)?.let { it to (corner != null) }
            },
            onDragEnd = { dragging = null }, onDragCancel = { dragging = null },
        ) { change, amount ->
            change.consume()
            val d = dragging ?: return@detectDragGestures
            val dx = amount.x / size.width; val dy = amount.y / size.height
            if (d.second) vm.resizeZone(d.first, dx, dy) else vm.moveZone(d.first, dx, dy)
        }
    } else Modifier)) {
        for (id in ZoneId.entries) {
            if (id == ZoneId.Possession && !ui.usePossession) continue
            val r = ui.zones[id]; val c = zoneColors.getValue(id)
            val tl = Offset(r.left * size.width, r.top * size.height)
            val sz = GSize(r.width * size.width, r.height * size.height)
            drawRect(c, tl, sz, style = Stroke(width = if (ui.selectedZone == id) 6f else 3f))
            if (ui.editingZones) drawCircle(c, 14f, Offset(tl.x + sz.width, tl.y + sz.height))
        }
    }
}
