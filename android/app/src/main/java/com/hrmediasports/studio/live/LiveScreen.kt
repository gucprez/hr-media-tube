package com.hrmediasports.studio.live

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.BatteryManager
import android.os.Build
import android.os.SystemClock
import android.view.SurfaceHolder
import android.view.SurfaceView
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.domain.parseClock
import com.hrmediasports.studio.ui.theme.HrColors
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext

private val livePermissions: Array<String> = buildList {
    add(Manifest.permission.CAMERA)
    add(Manifest.permission.RECORD_AUDIO)
    if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
}.toTypedArray()

/** Modo TRANSMITIR: cámara del teléfono → YouTube / Facebook con marcador incrustado. */
@Composable
fun LiveApp(onExit: () -> Unit) {
    val context = LocalContext.current
    fun granted() = listOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO)
        .all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }
    var hasPermissions by remember { mutableStateOf(granted()) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { hasPermissions = granted() }
    LaunchedEffect(Unit) { if (!hasPermissions) launcher.launch(livePermissions) }

    if (!hasPermissions) {
        Column(Modifier.fillMaxSize().background(HrColors.Background).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically), horizontalAlignment = Alignment.CenterHorizontally) {
            Text("Para transmitir, la app necesita la cámara y el micrófono.", color = HrColors.TextPrimary, fontSize = 18.sp, textAlign = TextAlign.Center)
            Button(onClick = { launcher.launch(livePermissions) }, modifier = Modifier.heightIn(min = 56.dp)) { Text("DAR PERMISOS") }
            TextButton(onClick = {
                context.startActivity(Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
            }) { Text("Abrir ajustes de la app") }
            TextButton(onClick = onExit) { Text("Volver") }
        }
        return
    }

    LiveEngine.init(context)
    val vm: LiveViewModel = viewModel(factory = object : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            LiveViewModel(EngineControl, PrefsLiveSettingsStore(context), { SystemClock.elapsedRealtime() }) as T
    })
    LiveScreen(vm, onExit)
}

@Composable
fun KeepScreenOn() {
    val activity = LocalContext.current as? ComponentActivity
    DisposableEffect(Unit) {
        activity?.window?.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        onDispose { activity?.window?.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
    }
}

/** Modo ahorro: brillo mínimo para reducir calor y batería. */
@Composable
private fun DimScreen(dim: Boolean) {
    val activity = LocalContext.current as? ComponentActivity
    DisposableEffect(dim) {
        val w = activity?.window
        val lp = w?.attributes
        lp?.screenBrightness = if (dim) 0.02f else WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE
        if (lp != null) w.attributes = lp
        onDispose {
            val l = w?.attributes
            l?.screenBrightness = WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE
            if (l != null) w.attributes = l
        }
    }
}

@Composable
fun LiveScreen(vm: LiveViewModel, onExit: () -> Unit) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    val st by vm.status.collectAsStateWithLifecycle()
    val context = LocalContext.current
    KeepScreenOn()
    DimScreen(ui.saver)

    // Prepara cámara y codificador con la calidad elegida (solo fuera del aire).
    // También al terminar una transmisión, si mientras tanto se cambió la calidad.
    LaunchedEffect(ui.settings.quality, st.anyOn) {
        if (vm.needsPrepare() && (!st.prepared || LiveEngine.currentQuality() != ui.settings.quality)) { LiveEngine.stopPreview(); vm.prepare() }
    }

    // Logo del cliente.
    LaunchedEffect(ui.settings.logoUri) {
        val uri = ui.settings.logoUri
        val bmp = if (uri == null) null else withContext(Dispatchers.IO) {
            runCatching { context.contentResolver.openInputStream(Uri.parse(uri))?.use { BitmapFactory.decodeStream(it) } }.getOrNull()
        }
        LiveEngine.setLogo(bmp)
    }

    BackHandler {
        if (st.anyOn) vm.openPanel(LivePanel.ConfirmStop)
        else { LiveEngine.releaseIfIdle(); onExit() }
    }

    BoxWithConstraints(Modifier.fillMaxSize().background(Color.Black)) {
        val sideW = if (maxWidth > 1000.dp) 176.dp else 140.dp
        Row(Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing)) {
            Box(Modifier.weight(1f).fillMaxHeight()) {
                if (ui.saver) SaverView(st, ui, onWake = vm::toggleSaver)
                else Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Box(Modifier.aspectRatio(16f / 9f, matchHeightConstraintsFirst = true)
                        .border(3.dp, if (st.anyLive) HrColors.OnAirRed else HrColors.Outline)) {
                        if (st.prepared) androidx.compose.runtime.key(st.generation) { CameraPreview() }
                        else Text(st.error ?: "Preparando cámara…", color = HrColors.TextMuted, modifier = Modifier.align(Alignment.Center).padding(16.dp), textAlign = TextAlign.Center)
                    }
                }
                Column(Modifier.align(Alignment.TopCenter).padding(top = 6.dp, start = 8.dp, end = 8.dp), horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    StatsBar(st)
                    thermalAdvice(st.thermal)?.let { Banner(it, HrColors.Amber) }
                    st.error?.let { Banner(it, HrColors.OnAirRed, onClick = vm::clearError) }
                    ui.message?.let { Banner(it, HrColors.Amber, onClick = vm::consumeMessage) }
                    if (!st.hardwareEncoder && st.prepared) Banner("Sin codificador por hardware: el teléfono se calentará más. Baja la calidad.", HrColors.Amber)
                    if (!st.anyOn) st.lastRecording?.let { Banner("Última grabación: $it", HrColors.Turquoise) }
                }
                if (!ui.saver) ScoreControls(ui, vm, Modifier.align(Alignment.BottomCenter))
            }
            SidePanel(ui, st, vm, Modifier.width(sideW).fillMaxHeight())
        }
    }

    when (ui.panel) {
        LivePanel.Setup -> SetupDialog(ui.settings, onlineNow = st.anyOn, onSave = vm::saveSettings, onCancel = vm::closePanel)
        LivePanel.ConfirmStop -> AlertDialog(
            onDismissRequest = vm::closePanel,
            title = { Text("¿Finalizar la transmisión?") },
            text = { Text("Se corta en todos los destinos y se guarda la grabación de respaldo.") },
            confirmButton = { Button(onClick = vm::confirmStop, colors = ButtonDefaults.buttonColors(containerColor = HrColors.OnAirRed)) { Text("FINALIZAR") } },
            dismissButton = { TextButton(onClick = vm::closePanel) { Text("Seguir al aire") } },
        )
        LivePanel.ConfirmNewGame -> AlertDialog(
            onDismissRequest = vm::closePanel,
            title = { Text("¿Partido nuevo?") },
            text = { Text("Pone puntos y faltas en cero, 1er cuarto y reloj completo. Los equipos se mantienen.") },
            confirmButton = { Button(onClick = vm::newGame) { Text("PONER EN CERO") } },
            dismissButton = { TextButton(onClick = vm::closePanel) { Text("Cancelar") } },
        )
        LivePanel.Clock -> ClockDialog(ui, vm)
        LivePanel.None -> Unit
    }
}

@Composable
fun CameraPreview() {
    AndroidView(factory = { ctx ->
        SurfaceView(ctx).apply {
            holder.addCallback(object : SurfaceHolder.Callback {
                override fun surfaceCreated(holder: SurfaceHolder) {}
                override fun surfaceChanged(holder: SurfaceHolder, format: Int, width: Int, height: Int) {
                    if (!LiveEngine.status.value.previewing) LiveEngine.startPreview(this@apply)
                }
                override fun surfaceDestroyed(holder: SurfaceHolder) { LiveEngine.stopPreview() }
            })
        }
    }, modifier = Modifier.fillMaxSize())
}

@Composable
private fun SaverView(st: EngineStatus, ui: LiveUiState, onWake: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Color.Black).clickable(onClick = onWake).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp, Alignment.CenterVertically), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(if (st.anyLive) "● EN VIVO" else if (st.anyOn) "CONECTANDO…" else "FUERA DEL AIRE",
            color = if (st.anyLive) HrColors.OnAirRed else HrColors.TextMuted, fontSize = 28.sp, fontWeight = FontWeight.Black)
        Text("${ui.score.home.name} ${ui.score.home.points} – ${ui.score.away.points} ${ui.score.away.name} · ${ui.score.periodText()} · ${ui.score.clockText(ui.now)}",
            color = HrColors.TextPrimary, fontSize = 22.sp, fontWeight = FontWeight.Bold)
        Text("Modo ahorro: la cámara sigue transmitiendo con la pantalla al mínimo. Toca para volver.",
            color = HrColors.TextMuted, fontSize = 14.sp, textAlign = TextAlign.Center)
    }
}

@Composable
private fun StatsBar(st: EngineStatus) {
    val context = LocalContext.current
    var battery by remember { mutableStateOf<Int?>(null) }
    var now by remember { mutableStateOf(SystemClock.elapsedRealtime()) }
    LaunchedEffect(Unit) {
        while (true) {
            battery = runCatching { context.getSystemService(BatteryManager::class.java).getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY) }.getOrNull()?.takeIf { it in 0..100 }
            now = SystemClock.elapsedRealtime()
            delay(1000)
        }
    }
    val parts = buildList {
        st.streamingSince?.let { add("AIRE " + formatClock(((now - it) / 1000).toInt())) }
        if (st.anyOn) add("%.1f Mbps".format(st.videoBitrateKbps / 1000f))
        add("${st.fps} fps")
        add("TEMP ${st.thermal.label}")
        if (st.recording) st.recordingStartedAt?.let { add("REC " + formatClock(((now - it) / 1000).toInt())) }
        battery?.let { add("BAT $it%") }
    }
    val hot = st.thermal.ordinal >= Thermal.Moderate.ordinal
    Text(parts.joinToString("  ·  "), color = if (hot) HrColors.Amber else HrColors.TextPrimary, fontSize = 12.sp, fontWeight = FontWeight.Bold,
        maxLines = 1, overflow = TextOverflow.Ellipsis,
        modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(Color(0xCC000000)).padding(horizontal = 10.dp, vertical = 4.dp))
}

@Composable
private fun Banner(text: String, color: Color, onClick: (() -> Unit)? = null) {
    Text(text + if (onClick != null) "  ✕" else "", color = Color.Black, fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.widthIn(max = 520.dp).clip(RoundedCornerShape(8.dp)).background(color)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(horizontal = 10.dp, vertical = 6.dp))
}

// ---------------- Marcador ----------------

@Composable
private fun ScoreControls(ui: LiveUiState, vm: LiveViewModel, modifier: Modifier) {
    val s = ui.score
    // Una sola fila de botones para tapar lo mínimo de la cámara.
    Row(modifier.fillMaxWidth().background(Color(0xD90B1220)).padding(horizontal = 4.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        TeamControls(s.home, home = true, vm, Modifier.weight(1f))
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(132.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { vm.openPanel(LivePanel.Clock) }) {
                Text(s.periodText() + " ", color = HrColors.TextMuted, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(s.clockText(ui.now), color = if (s.running) HrColors.Turquoise else HrColors.TextPrimary, fontSize = 22.sp,
                    fontWeight = FontWeight.Black, fontFamily = FontFamily.Monospace)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(3.dp)) {
                SmallBtn("◀", HrColors.PanelRaised, Modifier.width(34.dp)) { vm.previousPeriod() }
                SmallBtn(if (s.running) "II" else "▶", if (s.running) HrColors.Amber else HrColors.Turquoise, Modifier.width(58.dp), Color.Black) { vm.toggleClock() }
                SmallBtn("▶|", HrColors.PanelRaised, Modifier.width(34.dp)) { vm.nextPeriod() }
            }
        }
        TeamControls(s.away, home = false, vm, Modifier.weight(1f))
    }
}

@Composable
private fun TeamControls(t: Team, home: Boolean, vm: LiveViewModel, modifier: Modifier) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Box(Modifier.size(12.dp).clip(CircleShape).background(Color(t.color)))
            Text(t.name, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            Text(t.points.toString(), color = HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 22.sp)
            // Tocar las faltas resta una (corrección rápida).
            Text("F${t.fouls} −", color = if (t.fouls >= 5) HrColors.Amber else HrColors.TextMuted, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(6.dp)).clickable { vm.foul(home, -1) }.padding(horizontal = 6.dp, vertical = 4.dp))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(3.dp)) {
            for (d in listOf(1, 2, 3)) SmallBtn("+$d", HrColors.Orange, Modifier.weight(1f), Color.Black) { vm.points(home, d) }
            SmallBtn("−1", HrColors.PanelRaised, Modifier.weight(1f)) { vm.points(home, -1) }
            SmallBtn("F+", HrColors.PanelRaised, Modifier.weight(1f)) { vm.foul(home, 1) }
        }
    }
}

@Composable
private fun SmallBtn(text: String, color: Color, modifier: Modifier = Modifier, textColor: Color = HrColors.TextPrimary, onClick: () -> Unit) {
    Box(modifier.heightIn(min = 48.dp).clip(RoundedCornerShape(8.dp)).background(color).clickable(onClick = onClick).padding(horizontal = 4.dp),
        contentAlignment = Alignment.Center) {
        Text(text, color = textColor, fontWeight = FontWeight.Black, fontSize = 15.sp, maxLines = 1)
    }
}

// ---------------- Panel lateral ----------------

@Composable
private fun SidePanel(ui: LiveUiState, st: EngineStatus, vm: LiveViewModel, modifier: Modifier) {
    Column(modifier.background(HrColors.Panel).verticalScroll(rememberScrollState()).padding(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        if (!st.anyOn) {
            val ready = ui.settings.readyCount
            Button(onClick = vm::goLive, enabled = st.prepared, modifier = Modifier.fillMaxWidth().heightIn(min = 64.dp), contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 4.dp),
                colors = ButtonDefaults.buttonColors(containerColor = HrColors.OnAirRed, contentColor = Color.White), shape = RoundedCornerShape(10.dp)) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("TRANSMITIR", fontWeight = FontWeight.Black, fontSize = 14.sp, maxLines = 1, softWrap = false)
                    Text(if (ready == 0) "sin destinos" else "$ready destino(s)", fontSize = 11.sp)
                }
            }
        } else {
            Button(onClick = vm::requestStop, modifier = Modifier.fillMaxWidth().heightIn(min = 64.dp), contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 4.dp),
                colors = ButtonDefaults.buttonColors(containerColor = HrColors.PanelRaised, contentColor = Color.White), shape = RoundedCornerShape(10.dp),
                border = androidx.compose.foundation.BorderStroke(2.dp, HrColors.OnAirRed)) {
                Text("FINALIZAR", fontWeight = FontWeight.Black, fontSize = 14.sp, maxLines = 1, softWrap = false)
            }
        }
        for (d in ui.settings.destinations.filter { it.enabled }) DestinationChip(d, st.links[d.platform] ?: LinkStatus.Off, st.anyOn) { vm.toggleDestination(d.platform) }
        SideBtn(if (ui.saver) "VER CÁMARA" else "AHORRO (menos calor)") { vm.toggleSaver() }
        SideBtn(if (ui.score.visible) "OCULTAR MARCADOR" else "MOSTRAR MARCADOR") { vm.toggleScoreboard() }
        SideBtn(if (ui.muted) "ACTIVAR MICRÓFONO" else "SILENCIAR MICRÓFONO", if (ui.muted) HrColors.Amber else null) { vm.toggleMute() }
        SideBtn("CAMBIAR CÁMARA") { vm.switchCamera() }
        SideBtn("PARTIDO NUEVO") { vm.openPanel(LivePanel.ConfirmNewGame) }
        SideBtn("CONFIGURAR") { vm.openPanel(LivePanel.Setup) }
    }
}

@Composable
private fun DestinationChip(d: LiveDestination, s: LinkStatus, onAir: Boolean, onClick: () -> Unit) {
    val color = when (s) {
        LinkStatus.Live -> HrColors.OnAirRed
        is LinkStatus.Reconnecting, LinkStatus.Connecting -> HrColors.Amber
        is LinkStatus.Failed -> HrColors.OnAirRed.copy(alpha = 0.5f)
        LinkStatus.Off -> HrColors.PanelRaised
    }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(color)
        .clickable(enabled = onAir, onClick = onClick).padding(horizontal = 8.dp, vertical = 6.dp)) {
        Text(d.platform.label.substringBefore(" ("), color = Color.White, fontWeight = FontWeight.Black, fontSize = 14.sp)
        Text(s.label(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        val detail = when (s) { is LinkStatus.Failed -> s.reason; is LinkStatus.Reconnecting -> s.reason; else -> null }
        detail?.let { Text(it, color = Color.White, fontSize = 11.sp, maxLines = 3) }
        if (onAir && (s == LinkStatus.Off || s is LinkStatus.Failed)) Text("Toca para conectar", color = Color.White, fontSize = 11.sp)
    }
}

@Composable
private fun SideBtn(text: String, color: Color? = null, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = RoundedCornerShape(8.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 6.dp, vertical = 4.dp)) {
        Text(text, color = color ?: HrColors.TextPrimary, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
    }
}

// ---------------- Reloj ----------------

@Composable
private fun ClockDialog(ui: LiveUiState, vm: LiveViewModel) {
    var text by remember { mutableStateOf(ui.score.clockText(ui.now)) }
    val parsed = parseClock(text)
    AlertDialog(
        onDismissRequest = vm::closePanel,
        title = { Text("Ajustar reloj") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = text, onValueChange = { text = it.take(5) }, label = { Text("mm:ss") }, singleLine = true,
                    isError = parsed == null, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    for (d in listOf(-10, -1, 1, 10)) OutlinedButton(onClick = { vm.adjustClock(d); text = vm.ui.value.score.clockText(vm.ui.value.now) },
                        modifier = Modifier.heightIn(min = 48.dp)) { Text(if (d > 0) "+$d s" else "$d s") }
                }
            }
        },
        confirmButton = { Button(enabled = parsed != null, onClick = { parsed?.let(vm::setClock); vm.closePanel() }) { Text("APLICAR") } },
        dismissButton = { TextButton(onClick = vm::closePanel) { Text("Cerrar") } },
    )
}

// ---------------- Configuración ----------------

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun SetupDialog(initial: LiveSettings, onlineNow: Boolean, onSave: (LiveSettings) -> Unit, onCancel: () -> Unit) {
    var s by remember { mutableStateOf(initial) }
    val context = LocalContext.current
    val pickLogo = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            runCatching { context.contentResolver.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) }
            s = s.copy(logoUri = uri.toString())
        }
    }
    Dialog(onDismissRequest = onCancel, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(Modifier.fillMaxSize().background(HrColors.Background).windowInsetsPadding(WindowInsets.safeDrawing)) {
            Row(Modifier.fillMaxWidth().background(HrColors.Panel).padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Configurar transmisión", color = HrColors.TextPrimary, fontSize = 18.sp, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                TextButton(onClick = onCancel, modifier = Modifier.heightIn(min = 48.dp)) { Text("Cancelar") }
                Button(onClick = { onSave(s) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("GUARDAR") }
            }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Section("Destinos")
                if (onlineNow) Hint("Estás al aire: los cambios de clave se usan al reconectar ese destino.")
                for (p in Platform.entries) DestinationEditor(s.destination(p)) { s = s.withDestination(it) }
                val n = s.readyCount
                if (n > 0) Hint("Necesitas al menos %.1f Mbps de SUBIDA estables para %d destino(s) en %s. Si no llegas, usa Restream (un solo envío) o la calidad de ahorro."
                    .format(s.quality.uploadNeededMbps(n), n, s.quality.label.substringBefore(" (")))

                Section("Calidad")
                for (q in Quality.entries) Row(Modifier.fillMaxWidth().clickable { s = s.copy(quality = q) }.heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
                    RadioButton(selected = s.quality == q, onClick = { s = s.copy(quality = q) })
                    Column { Text(q.label, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold); Text(q.note, color = HrColors.TextMuted, fontSize = 12.sp) }
                }
                if (onlineNow) Hint("La calidad cambia en la próxima transmisión.")

                Section("Equipos y marcador")
                TeamEditor("Local", s.homeName, s.homeColor, { s = s.copy(homeName = it) }, { s = s.copy(homeColor = it) })
                TeamEditor("Visitante", s.awayName, s.awayColor, { s = s.copy(awayName = it) }, { s = s.copy(awayColor = it) })
                Text("Minutos por cuarto", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    for (m in listOf(5, 8, 10, 12)) {
                        val sel = s.periodMinutes == m
                        Box(Modifier.heightIn(min = 48.dp).widthIn(min = 64.dp).clip(RoundedCornerShape(8.dp))
                            .background(if (sel) HrColors.Orange else HrColors.PanelRaised).clickable { s = s.copy(periodMinutes = m) },
                            contentAlignment = Alignment.Center) { Text("$m min", color = if (sel) Color.Black else HrColors.TextPrimary, fontWeight = FontWeight.Bold) }
                    }
                }

                Section("Logo (esquina superior derecha del video)")
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { pickLogo.launch(arrayOf("image/*")) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(if (s.logoUri == null) "ELEGIR LOGO" else "CAMBIAR LOGO") }
                    if (s.logoUri != null) TextButton(onClick = { s = s.copy(logoUri = null) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Quitar") }
                }
                Hint("Usa un PNG con fondo transparente para que se vea profesional.")

                Section("Respaldo")
                Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Grabar copia en el teléfono", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                        Text("Si se cae internet, el partido queda grabado completo. Ocupa ~1,6 GB por hora en 720p.", color = HrColors.TextMuted, fontSize = 12.sp)
                    }
                    Switch(checked = s.record, onCheckedChange = { s = s.copy(record = it) })
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}

@Composable private fun Section(t: String) = Text(t.uppercase(), color = HrColors.Orange, fontWeight = FontWeight.Black, fontSize = 14.sp)
@Composable private fun Hint(t: String) = Text(t, color = HrColors.TextMuted, fontSize = 13.sp)

@Composable
private fun DestinationEditor(d: LiveDestination, onChange: (LiveDestination) -> Unit) {
    var showKey by remember { mutableStateOf(false) }
    var advanced by remember { mutableStateOf(d.platform == Platform.Custom) }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).background(HrColors.Panel).padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(d.platform.label, color = HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 16.sp, modifier = Modifier.weight(1f))
            Switch(checked = d.enabled, onCheckedChange = { onChange(d.copy(enabled = it)) })
        }
        if (d.enabled) {
            Text(when (d.platform) {
                Platform.YouTube -> "YouTube Studio › Crear › Emitir en directo › Transmisión › copia la «Clave de transmisión»."
                Platform.Facebook -> "Facebook › Video en vivo (Live Producer) › Software de transmisión › copia la «Clave de transmisión». Usa una clave persistente."
                Platform.Custom -> "Pega la dirección del servidor (rtmp:// o rtmps://) y la clave, o la dirección completa con la clave."
            }, color = HrColors.TextMuted, fontSize = 12.sp)
            OutlinedTextField(value = d.key, onValueChange = { onChange(d.copy(key = it.trim())) }, label = { Text("Clave de transmisión") }, singleLine = true,
                visualTransformation = if (showKey) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = { showKey = !showKey }, modifier = Modifier.heightIn(min = 48.dp)) { Text(if (showKey) "Ocultar clave" else "Mostrar clave") }
                if (d.platform != Platform.Custom) TextButton(onClick = { advanced = !advanced }, modifier = Modifier.heightIn(min = 48.dp)) { Text(if (advanced) "Ocultar servidor" else "Cambiar servidor") }
            }
            if (advanced) OutlinedTextField(value = d.server, onValueChange = { onChange(d.copy(server = it.trim())) }, label = { Text("Servidor") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
            d.problem()?.let { Text("⚠ $it", color = HrColors.Amber, fontSize = 13.sp, fontWeight = FontWeight.Bold) }
                ?: Text("Listo · clave ${maskKey(d.key)}", color = HrColors.Turquoise, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TeamEditor(label: String, name: String, color: Long, onName: (String) -> Unit, onColor: (Long) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        OutlinedTextField(value = name, onValueChange = { onName(it.uppercase().take(4)) }, label = { Text("$label (máx. 4 letras)") }, singleLine = true)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            for (c in TEAM_COLORS) Box(Modifier.size(48.dp).clip(CircleShape).background(Color(c))
                .border(if (c == color) 4.dp else 1.dp, if (c == color) HrColors.Orange else HrColors.Outline, CircleShape).clickable { onColor(c) })
        }
    }
}
