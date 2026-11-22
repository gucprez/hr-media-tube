package com.hrmediasports.studio.ui

import android.graphics.BitmapFactory
import android.net.Uri
import android.os.SystemClock
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.QrCode2
import androidx.compose.material.icons.filled.Scoreboard
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.engine.DemoMediaEngine
import com.hrmediasports.studio.link.PairingCode
import com.hrmediasports.studio.link.RemoteCamera
import com.hrmediasports.studio.link.StudioLink
import com.hrmediasports.studio.live.CAMERA_AND_MIC
import com.hrmediasports.studio.live.CameraPreview
import com.hrmediasports.studio.live.EngineStatus
import com.hrmediasports.studio.live.KeepScreenOn
import com.hrmediasports.studio.live.LinkStatus
import com.hrmediasports.studio.live.LiveEngine
import com.hrmediasports.studio.live.PrefsLiveSettingsStore
import com.hrmediasports.studio.live.SetupDialog
import com.hrmediasports.studio.live.Thermal
import com.hrmediasports.studio.live.WithPermissions
import com.hrmediasports.studio.live.label
import com.hrmediasports.studio.live.thermalAdvice
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.ui.components.LocalLiveMonitors
import com.hrmediasports.studio.ui.components.MonitorRole
import com.hrmediasports.studio.ui.components.PanelFrame
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.screens.StudioActions
import com.hrmediasports.studio.ui.theme.HrColors
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** ESTUDIO real: la interfaz diseñada + cámara de este teléfono + cámaras/pizarra por wifi + transmisión. */
@Composable
fun RealStudioApp(onExit: () -> Unit) {
    WithPermissions(CAMERA_AND_MIC, "El Estudio usa la cámara y el micrófono de este teléfono para transmitir.", onExit) {
        val context = LocalContext.current
        LiveEngine.init(context)
        StudioLink.start(context)
        val vm: StudioViewModel = viewModel(key = "estudio-real", factory = object : ViewModelProvider.Factory {
            @Suppress("UNCHECKED_CAST")
            override fun <T : ViewModel> create(modelClass: Class<T>): T =
                StudioViewModel(DemoMediaEngine, StudioLink, live = EngineStudioLive(PrefsLiveSettingsStore(context))) as T
        })
        RealStudioScreen(vm, onExit)
    }
}

@Composable
private fun RealStudioScreen(vm: StudioViewModel, onExit: () -> Unit) {
    val state by vm.state.collectAsStateWithLifecycle()
    val engine by LiveEngine.status.collectAsStateWithLifecycle()
    val pairing by StudioLink.pairing.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val settings = state.liveSettings
    KeepScreenOn()

    LaunchedEffect(settings?.quality, engine.anyOn) {
        val q = settings?.quality ?: return@LaunchedEffect
        if (!engine.anyOn && !engine.recording && (!engine.prepared || LiveEngine.currentQuality() != q)) { LiveEngine.stopPreview(); LiveEngine.prepare(q) }
    }
    LaunchedEffect(settings?.logoUri) {
        val uri = settings?.logoUri
        val bmp = if (uri == null) null else withContext(Dispatchers.IO) {
            runCatching { context.contentResolver.openInputStream(Uri.parse(uri))?.use { BitmapFactory.decodeStream(it) } }.getOrNull()
        }
        LiveEngine.setLogo(bmp)
    }
    BackHandler {
        if (engine.anyOn || engine.recording) vm.openDialog(StudioDialog.ConfirmStopStream)
        else { LiveEngine.releaseIfIdle(); onExit() }
    }

    val monitors: @Composable (VideoSource, MonitorRole, Modifier) -> Boolean = { source, role, modifier ->
        liveMonitor(source, role, state, engine, modifier)
    }
    CompositionLocalProvider(LocalLiveMonitors provides monitors) {
        StudioAppContent(
            state = state,
            actions = StudioActions(
                onSelectSource = vm::selectSource, onTakeToAir = vm::takeToAir, onSaveReplay = vm::onSaveReplayClick,
                onManualCorrection = vm::openCorrection, onDiscardCorrection = vm::discardCorrection,
                onSetMonitorView = vm::setMonitorView, onToggleReplaysTray = vm::toggleReplaysTray,
                onOpenPanel = vm::openPanel, onClosePanel = vm::closePanel,
            ),
            onNavigate = vm::navigate, onRecord = vm::onRecordClick, onStream = vm::onStreamClick,
            onDraftChange = vm::updateDraft, onApplyCorrection = vm::applyCorrection,
            onDismissCorrection = vm::dismissCorrection, onMessageShown = vm::consumeMessage,
            topActions = { compact -> LiveTopActions(state, engine, compact, vm) },
            sectionOverride = { dest, modifier ->
                when (dest) {
                    Destination.Cameras, Destination.Settings -> { DevicesScreen(state, engine, pairing, vm, modifier); true }
                    else -> false
                }
            },
        )
    }

    when (state.dialog) {
        StudioDialog.Pairing -> PairingDialog(pairing, onReset = { StudioLink.resetCode(context) }, onClose = vm::closeDialog)
        StudioDialog.StreamSetup -> SetupDialog(settings ?: return, onlineNow = engine.anyOn, onSave = vm::saveLiveSettings, onCancel = vm::closeDialog)
        StudioDialog.ConfirmStopStream -> AlertDialog(
            onDismissRequest = vm::closeDialog,
            title = { Text(if (engine.anyOn) "¿Finalizar la transmisión?" else "¿Salir del Estudio?") },
            text = { Text(if (engine.anyOn) "Se corta en YouTube y Facebook. La grabación de respaldo se guarda en la galería." else "Se detiene la grabación en curso y se guarda en la galería.") },
            confirmButton = {
                Button(onClick = {
                    if (engine.anyOn) vm.confirmStopStream() else { LiveEngine.stopManualRecording(); vm.closeDialog() }
                }, colors = ButtonDefaults.buttonColors(containerColor = HrColors.OnAirRed)) { Text("FINALIZAR") }
            },
            dismissButton = { TextButton(onClick = vm::closeDialog) { Text("Seguir") } },
        )
        null -> Unit
    }
}

/** Qué se ve en cada monitor del Estudio real. */
@Composable
private fun liveMonitor(source: VideoSource, role: MonitorRole, state: StudioUiState, engine: EngineStatus, modifier: Modifier): Boolean {
    if (role == MonitorRole.Program) {
        // AL AIRE: la salida real de OpenGL (cámara + marcador + logo + gráficos), igual a la transmisión.
        Box(modifier.background(Color.Black)) {
            if (engine.prepared) androidx.compose.runtime.key(engine.generation) { CameraPreview() }
            else Text(engine.error ?: "Preparando cámara…", color = HrColors.TextMuted, modifier = Modifier.align(Alignment.Center).padding(12.dp), textAlign = TextAlign.Center)
        }
        return true
    }
    // VISTA PREVIA
    return when (source.id) {
        SOURCE_CAMERA_2 -> { RemoteThumb(state.remoteCameras.getOrNull(0), modifier); true }
        SOURCE_CAMERA_1 -> {
            Box(modifier.background(Color(0xFF0E1522)), contentAlignment = Alignment.Center) {
                Text(if (state.programSourceId == SOURCE_CAMERA_1) "CÁMARA 1 · este teléfono\n(es la misma imagen que AL AIRE)" else "CÁMARA 1 · este teléfono\nlista para poner al aire",
                    color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.padding(8.dp))
            }
            true
        }
        else -> false // Descanso y Resultado: el gráfico de la interfaz
    }
}

@Composable
private fun RemoteThumb(cam: RemoteCamera?, modifier: Modifier) {
    Box(modifier.background(Color(0xFF0E1522)), contentAlignment = Alignment.Center) {
        val thumb = cam?.thumb
        if (cam?.connected == true && thumb != null) {
            Image(thumb.asImageBitmap(), contentDescription = "Vista de la Cámara 2", contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
            Text("CÁMARA 2 · vista previa (1 imagen/s)", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.align(Alignment.BottomStart).background(Color(0xAA000000)).padding(horizontal = 6.dp, vertical = 2.dp))
        } else {
            Text(if (cam?.connected == true) "CÁMARA 2 conectada\nesperando imagen…" else "CÁMARA 2 sin conectar\nToca CONECTAR y escanea el QR\ndesde el otro teléfono (modo CÁMARA)",
                color = HrColors.TextMuted, textAlign = TextAlign.Center, modifier = Modifier.padding(8.dp))
        }
    }
}

@Composable
internal fun RowScope.LiveTopActions(state: StudioUiState, engine: EngineStatus, compactBar: Boolean, vm: StudioViewModel) {
    // En teléfonos los botones extra van solo con icono: GRABAR y TRANSMITIR siempre deben verse.
    val compact = compactBar || androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp < 1200
    val hot = engine.thermal.ordinal >= Thermal.Moderate.ordinal
    if ((engine.anyOn && !compact) || hot) StatusChip(
        (if (engine.anyOn) "%.1f Mbps · ".format(engine.videoBitrateKbps / 1000f) else "") + "TEMP ${engine.thermal.label}",
        if (hot) HrColors.Amber else HrColors.TextMuted)
    TopIcon(Icons.Filled.QrCode2, "CONECTAR", compact) { StudioLink.refreshAddress(); vm.openDialog(StudioDialog.Pairing) }
    TopIcon(Icons.Filled.Scoreboard, if (state.overlayVisible) "MARCADOR SÍ" else "MARCADOR NO", compact, if (state.overlayVisible) HrColors.Turquoise else HrColors.Amber) { vm.toggleOverlay() }
    TopIcon(Icons.Filled.Settings, "TRANSMISIÓN", compact) { vm.openDialog(StudioDialog.StreamSetup) }
}

@Composable
private fun TopIcon(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, compact: Boolean, tint: Color = HrColors.TextPrimary, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.heightIn(min = 48.dp).widthIn(min = 48.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = if (compact) 8.dp else 12.dp)) {
        Icon(icon, contentDescription = label, tint = tint, modifier = Modifier.size(20.dp))
        if (!compact) Text(label, color = tint, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 6.dp))
    }
}

@Composable
fun PairingDialog(pairing: PairingCode?, onReset: () -> Unit, onClose: () -> Unit) {
    Dialog(onDismissRequest = onClose) {
        Column(Modifier.clip(RoundedCornerShape(16.dp)).background(HrColors.Panel).verticalScroll(rememberScrollState()).padding(16.dp),
            horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Conectar teléfonos", color = HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 18.sp)
            if (pairing == null || pairing.host == "0.0.0.0") {
                Text("No hay wifi. Conecta este teléfono a la misma red wifi que la Pizarra y la Cámara (o activa aquí el punto de acceso).",
                    color = HrColors.Amber, textAlign = TextAlign.Center)
            } else {
                val qr = remember(pairing) { qrBitmap(pairing.toUri()) }
                Row(horizontalArrangement = Arrangement.spacedBy(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Image(qr.asImageBitmap(), contentDescription = "Código QR del Estudio", modifier = Modifier.size(220.dp).clip(RoundedCornerShape(8.dp)))
                    Column(Modifier.widthIn(max = 260.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("En el otro teléfono abre la app, elige PIZARRA o CÁMARA y escanea este código.", color = HrColors.TextPrimary)
                        Text("Si no lee el QR, escribe:", color = HrColors.TextMuted, fontSize = 13.sp)
                        Text("IP  ${pairing.host}\nPuerto  ${pairing.port}\nCódigo  ${pairing.manualCode()}", color = HrColors.TextPrimary,
                            fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold)
                        Text("Todos en la misma red wifi. Los teléfonos emparejados se reconectan solos.", color = HrColors.TextMuted, fontSize = 12.sp)
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = onReset, modifier = Modifier.heightIn(min = 48.dp)) { Text("Nuevo código") }
                Button(onClick = onClose, modifier = Modifier.heightIn(min = 48.dp)) { Text("LISTO") }
            }
        }
    }
}

/** Secciones Cámaras y Ajustes del Estudio real: dispositivos conectados, transmisión y salud. */
@Composable
internal fun DevicesScreen(state: StudioUiState, engine: EngineStatus, pairing: PairingCode?, vm: StudioViewModel, modifier: Modifier) {
    Column(modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        PanelFrame("DISPOSITIVOS", badge = { StatusChip("${state.connectedCameras + if (state.scoreboard.link == LinkState.Connected) 1 else 0} CONECTADOS", HrColors.TextMuted) }) {
            DeviceLine("Cámara 1 · este teléfono", if (engine.prepared) "Lista" else engine.error ?: "Preparando…", if (engine.prepared) HrColors.Turquoise else HrColors.Amber)
            state.remoteCameras.take(1).forEach { c ->
                DeviceLine("Cámara 2 · remota", when {
                    !c.connected -> "Sin conectar"
                    !c.hasVideo -> "Conectada, sin video"
                    else -> "Conectada · ${c.fps} fps · bat ${c.battery}% · temp ${Thermal.fromAndroid(c.thermal).label}"
                }, if (c.connected) HrColors.Turquoise else HrColors.TextMuted)
            }
            val sb = state.scoreboard
            DeviceLine("Pizarra", when (sb.link) {
                LinkState.Connected -> if (StudioLink.lastAuto) "Conectada · lectura automática" else "Conectada · marcado a mano"
                else -> "Sin conectar"
            }, if (sb.link == LinkState.Connected) HrColors.Turquoise else HrColors.TextMuted)
            Button(onClick = { StudioLink.refreshAddress(); vm.openDialog(StudioDialog.Pairing) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("CONECTAR TELÉFONOS (QR)") }
            pairing?.let { Text("Estudio en ${it.host}:${it.port}", color = HrColors.TextMuted, fontSize = 12.sp) }
        }
        PanelFrame("TRANSMISIÓN") {
            val s = state.liveSettings
            s?.destinations?.filter { it.enabled }?.forEach { d ->
                val link = engine.links[d.platform] ?: LinkStatus.Off
                DeviceLine(d.platform.label, link.label() + ((link as? LinkStatus.Failed)?.reason?.let { " · $it" } ?: ""),
                    if (link == LinkStatus.Live) HrColors.OnAirRed else HrColors.TextMuted)
            }
            if (s?.readyCount == 0) Text("Sin destinos configurados.", color = HrColors.Amber)
            DeviceLine("Calidad", s?.quality?.label ?: "—", HrColors.TextPrimary)
            DeviceLine("Temperatura", engine.thermal.label, if (engine.thermal.ordinal >= Thermal.Moderate.ordinal) HrColors.Amber else HrColors.TextPrimary)
            thermalAdvice(engine.thermal)?.let { Text(it, color = HrColors.Amber, fontSize = 13.sp) }
            engine.lastRecording?.let { Text("Última grabación: $it", color = HrColors.TextMuted, fontSize = 12.sp) }
            Button(onClick = { vm.openDialog(StudioDialog.StreamSetup) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("CONFIGURAR YOUTUBE / FACEBOOK / EQUIPOS") }
        }
    }
}

@Composable
private fun DeviceLine(label: String, value: String, color: Color) {
    Row(Modifier.fillMaxWidth().heightIn(min = 32.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        Text(value, color = color, fontSize = 13.sp, textAlign = TextAlign.End, modifier = Modifier.weight(1.4f))
    }
}
