package com.hrmediasports.studio.camera

import android.Manifest
import android.content.pm.PackageManager
import android.view.WindowManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.ui.components.ConnectionPill
import com.hrmediasports.studio.ui.components.DemoBanner
import com.hrmediasports.studio.ui.components.HrBrandBar
import com.hrmediasports.studio.ui.components.PanelFrame
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens
import org.webrtc.RendererCommon
import org.webrtc.SurfaceViewRenderer

@Composable
fun RemoteCameraApp(vm: RemoteCameraViewModel = viewModel()) {
    val state by vm.state.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val owner = LocalLifecycleOwner.current

    // La pantalla no se apaga mientras se transmite: la app debe seguir en primer plano.
    DisposableEffect(Unit) {
        val window = (context as? android.app.Activity)?.window
        window?.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        onDispose { window?.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
    }

    fun granted(p: String) = ContextCompat.checkSelfPermission(context, p) == PackageManager.PERMISSION_GRANTED

    // Permisos: se piden solo en el momento de usar cada función.
    var pendingAudio by remember { mutableStateOf(true) }
    val micLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        vm.startStreaming(owner, withAudio = ok && pendingAudio)
    }
    val cameraForStream = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (!ok) return@rememberLauncherForActivityResult
        if (pendingAudio && !granted(Manifest.permission.RECORD_AUDIO)) micLauncher.launch(Manifest.permission.RECORD_AUDIO)
        else vm.startStreaming(owner, withAudio = pendingAudio && granted(Manifest.permission.RECORD_AUDIO))
    }
    val cameraForScan = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) vm.startScanning()
    }
    val micToggle = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok -> vm.setAudio(ok) }

    val onScan = { if (granted(Manifest.permission.CAMERA)) vm.startScanning() else cameraForScan.launch(Manifest.permission.CAMERA) }
    val onStart = { audio: Boolean ->
        pendingAudio = audio
        if (!granted(Manifest.permission.CAMERA)) cameraForStream.launch(Manifest.permission.CAMERA)
        else if (audio && !granted(Manifest.permission.RECORD_AUDIO)) micLauncher.launch(Manifest.permission.RECORD_AUDIO)
        else vm.startStreaming(owner, withAudio = audio)
    }

    Column(Modifier.fillMaxSize().background(HrColors.Background).windowInsetsPadding(WindowInsets.safeDrawing)) {
        HrBrandBar(title = "Cámara remota", compact = true)
        DemoBanner(
            label = "EN VALIDACIÓN",
            text = "WebRTC aún no medido en un dispositivo real (ver docs/MATRIZ-FUNCIONES.md).",
        )
        when (state.phase) {
            Phase.NeedPairing -> PairingPanel(state, onScan, vm::onPairingText, vm::rename)
            Phase.Scanning -> ScanPanel(vm)
            Phase.Connecting, Phase.WaitingAcceptance -> WaitPanel(state, vm::unpair)
            Phase.Ready, Phase.Streaming -> StreamPanel(
                state = state, vm = vm, onStart = onStart,
                onAudioToggle = { on -> if (!on || granted(Manifest.permission.RECORD_AUDIO)) vm.setAudio(on) else micToggle.launch(Manifest.permission.RECORD_AUDIO) },
            )
        }
    }
}

@Composable
private fun Centered(content: @Composable () -> Unit) {
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
    ) { content() }
}

@Composable
private fun BigButton(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, primary: Boolean = true) {
    Button(
        onClick = onClick,
        modifier = modifier.heightIn(min = 56.dp).widthIn(min = 220.dp),
        colors = if (primary) ButtonDefaults.buttonColors(containerColor = HrColors.Orange, contentColor = Color.Black)
        else ButtonDefaults.buttonColors(containerColor = HrColors.PanelRaised, contentColor = HrColors.TextPrimary),
    ) { Text(text, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center) }
}

@Composable
private fun PairingPanel(state: CameraUiState, onScan: () -> Unit, onText: (String) -> Unit, onRename: (String) -> Unit) {
    var link by remember { mutableStateOf("") }
    var name by remember(state.deviceName) { mutableStateOf(state.deviceName) }
    Centered {
        Text("Vincular con un estudio", color = HrColors.TextPrimary, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
        Text(
            "1. En la laptop abre el estudio y pulsa «Crear estudio».\n2. Escanea el QR que aparece. El QR solo autoriza la conexión: el video va directo por la red local.\n3. Acepta este dispositivo en la laptop.",
            color = HrColors.TextMuted, fontSize = 14.sp, modifier = Modifier.widthIn(max = 520.dp),
        )
        state.error?.let { Text(it, color = HrColors.Amber, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.widthIn(max = 520.dp)) }
        state.notice?.let { Text(it, color = HrColors.TextMuted) }
        OutlinedTextField(
            value = name, onValueChange = { name = it.take(40) },
            label = { Text("Nombre de este dispositivo") }, singleLine = true,
            modifier = Modifier.widthIn(max = 520.dp).fillMaxWidth(),
        )
        BigButton("ESCANEAR QR", { onRename(name); onScan() })
        OutlinedTextField(
            value = link, onValueChange = { link = it },
            label = { Text("…o pega el enlace de vinculación") }, singleLine = true,
            keyboardOptions = KeyboardOptions.Default,
            modifier = Modifier.widthIn(max = 520.dp).fillMaxWidth(),
        )
        OutlinedButton(
            onClick = { onRename(name); onText(link) }, enabled = link.isNotBlank(),
            modifier = Modifier.heightIn(min = HrDimens.MinTouch),
            border = BorderStroke(1.dp, HrColors.Outline),
        ) { Text("Vincular con el enlace", color = HrColors.TextPrimary) }
    }
}

@Composable
private fun ScanPanel(vm: RemoteCameraViewModel) {
    Column(Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Apunta al QR del estudio", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
        QrScannerView(onText = { vm.onPairingText(it) }, modifier = Modifier.weight(1f).fillMaxWidth().clip(RoundedCornerShape(12.dp)))
        OutlinedButton(onClick = vm::cancelScanning, modifier = Modifier.heightIn(min = HrDimens.MinTouch), border = BorderStroke(1.dp, HrColors.Outline)) {
            Text("Cancelar", color = HrColors.TextPrimary)
        }
    }
}

@Composable
private fun WaitPanel(state: CameraUiState, onCancel: () -> Unit) {
    Centered {
        val waiting = state.phase == Phase.WaitingAcceptance
        ConnectionPill(
            if (waiting) "Esperando que el estudio acepte «${state.deviceName}»" else "Conectando con ${state.studio ?: "el estudio"}…",
            HrColors.Amber, Modifier.widthIn(max = 520.dp),
        )
        if (waiting) Text("En la laptop pulsa «Aceptar» en la lista de dispositivos.", color = HrColors.TextMuted)
        OutlinedButton(onClick = onCancel, modifier = Modifier.heightIn(min = HrDimens.MinTouch), border = BorderStroke(1.dp, HrColors.Outline)) {
            Text("Cancelar", color = HrColors.TextPrimary)
        }
    }
}

@Composable
private fun StreamPanel(
    state: CameraUiState,
    vm: RemoteCameraViewModel,
    onStart: (Boolean) -> Unit,
    onAudioToggle: (Boolean) -> Unit,
) {
    val streaming = state.phase == Phase.Streaming
    Row(Modifier.fillMaxSize().padding(8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        // Vista previa local; borde rojo + rótulo cuando el estudio avisa AL AIRE.
        Box(
            Modifier
                .weight(1f)
                .fillMaxHeight()
                .clip(RoundedCornerShape(10.dp))
                .background(HrColors.PanelRaised)
                .border(if (state.onAir) 4.dp else 1.dp, if (state.onAir) HrColors.OnAirRed else HrColors.Outline, RoundedCornerShape(10.dp)),
            contentAlignment = Alignment.Center,
        ) {
            if (streaming) LocalPreview(vm, Modifier.fillMaxWidth().aspectRatio(16f / 9f))
            else Text("Vista previa: pulsa «Iniciar envío»", color = HrColors.TextMuted, textAlign = TextAlign.Center, modifier = Modifier.padding(16.dp))
            if (state.onAir) {
                StatusChip("AL AIRE", HrColors.OnAirRed, Modifier.align(Alignment.TopStart).padding(10.dp), filled = true)
            }
        }
        Column(
            Modifier.widthIn(min = 260.dp, max = 360.dp).fillMaxHeight().verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            val connected = state.link == LinkStatus.Connected
            ConnectionPill(
                state.link.label + (state.studio?.let { " · $it" } ?: ""),
                if (connected) HrColors.Turquoise else HrColors.Amber,
            )
            if (state.onAir) {
                ConnectionPill("AL AIRE: el estudio está usando esta cámara", HrColors.OnAirRed)
            }
            state.error?.let { Text(it, color = HrColors.Amber, fontWeight = FontWeight.Bold) }
            state.notice?.let { Text(it, color = HrColors.TextMuted, fontSize = 13.sp) }

            if (streaming) QualityCard(state)

            BigButton(
                if (streaming) "DETENER ENVÍO" else "INICIAR ENVÍO",
                { if (streaming) vm.stopStreaming() else onStart(state.audioOn) },
                Modifier.fillMaxWidth(),
                primary = !streaming,
            )
            if (streaming) Controls(state, vm, onAudioToggle)
            OutlinedButton(
                onClick = vm::unpair, modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                border = BorderStroke(1.dp, HrColors.Outline),
            ) { Text("Desvincular de este estudio", color = HrColors.TextMuted) }
        }
    }
}

@Composable
private fun LocalPreview(vm: RemoteCameraViewModel, modifier: Modifier) {
    val track = vm.sender.videoTrack
    var renderer by remember { mutableStateOf<SurfaceViewRenderer?>(null) }
    AndroidView(
        factory = { ctx ->
            SurfaceViewRenderer(ctx).apply {
                init(vm.sender.eglBase.eglBaseContext, null)
                setScalingType(RendererCommon.ScalingType.SCALE_ASPECT_FIT)
                setEnableHardwareScaler(true)
                renderer = this
            }
        },
        modifier = modifier.pointerInput(Unit) {
            // Tocar la vista previa enfoca y mide la exposición en ese punto.
            detectTapGestures { p -> vm.focusAt(p.x / size.width, p.y / size.height) }
        },
    )
    DisposableEffect(track, renderer) {
        val r = renderer
        if (track != null && r != null) track.addSink(r)
        onDispose {
            if (track != null && r != null) runCatching { track.removeSink(r) }
        }
    }
    DisposableEffect(Unit) { onDispose { renderer?.release() } }
}

@Composable
private fun QualityCard(state: CameraUiState) {
    val s = state.stats
    PanelFrame(title = "CALIDAD", badge = {
        StatusChip(
            state.quality.label.uppercase(),
            when (state.quality) { Quality.Good -> HrColors.Turquoise; Quality.Fair -> HrColors.Amber; Quality.Poor -> HrColors.OnAirRed; Quality.Unknown -> HrColors.TextMuted },
            dense = true,
        )
    }) {
        val enc = when (state.hardwareEncoder) { true -> "hardware"; false -> "software"; null -> "sin dato" }
        Text(
            buildString {
                append(if (s.width > 0) "${s.width}×${s.height} · ${"%.0f".format(s.fps)} fps · ${s.bitrateKbps} kbps" else "Esperando video…")
                append("\nCodificador: ${s.encoder.ifBlank { "—" }} ($enc)")
                s.rttMs?.let { append("\nLatencia de red (RTT): $it ms") }
                s.lossPct?.let { append("\nPérdida: ${"%.1f".format(it)} %") }
                if (!s.limitation.isNullOrBlank() && s.limitation != "none") append("\nLimitado por: ${s.limitation}")
            },
            color = HrColors.TextMuted, fontSize = 13.sp,
        )
    }
}

@Composable
private fun Controls(state: CameraUiState, vm: RemoteCameraViewModel, onAudioToggle: (Boolean) -> Unit) {
    PanelFrame(title = "CONTROLES") {
        // Lente
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            Lens.entries.forEach { lens ->
                val sel = state.lens == lens
                OutlinedButton(
                    onClick = { vm.setLens(lens) }, modifier = Modifier.weight(1f).heightIn(min = HrDimens.MinTouch),
                    border = BorderStroke(if (sel) 2.dp else 1.dp, if (sel) HrColors.Orange else HrColors.Outline),
                ) { Text(lens.label, color = if (sel) HrColors.TextPrimary else HrColors.TextMuted, fontWeight = FontWeight.Bold) }
            }
        }
        // Zoom
        val caps = state.caps
        if (caps.maxZoom > caps.minZoom) {
            Text("Zoom ${"%.1f".format(state.zoom)}×", color = HrColors.TextPrimary, fontSize = 14.sp)
            Slider(
                value = state.zoom, onValueChange = vm::setZoom, valueRange = caps.minZoom..caps.maxZoom,
                modifier = Modifier.heightIn(min = HrDimens.MinTouch).semantics { contentDescription = "Zoom" },
                colors = SliderDefaults.colors(thumbColor = HrColors.Orange, activeTrackColor = HrColors.Orange),
            )
        } else Text("Esta cámara no ofrece zoom.", color = HrColors.TextMuted, fontSize = 13.sp)
        // Exposición
        if (caps.expMax > caps.expMin) {
            val ev = state.exposure * caps.expStepEv
            Text("Exposición ${if (ev >= 0) "+" else ""}${"%.1f".format(ev)} EV", color = HrColors.TextPrimary, fontSize = 14.sp)
            Slider(
                value = state.exposure.toFloat(), onValueChange = { vm.setExposure(it.toInt()) },
                valueRange = caps.expMin.toFloat()..caps.expMax.toFloat(), steps = (caps.expMax - caps.expMin - 1).coerceAtLeast(0),
                modifier = Modifier.heightIn(min = HrDimens.MinTouch).semantics { contentDescription = "Exposición" },
                colors = SliderDefaults.colors(thumbColor = HrColors.Orange, activeTrackColor = HrColors.Orange),
            )
        } else Text("Esta cámara no permite compensar la exposición.", color = HrColors.TextMuted, fontSize = 13.sp)
        Text("Enfoque: toca la vista previa.", color = HrColors.TextMuted, fontSize = 13.sp)
        // Audio
        Row(Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch), verticalAlignment = Alignment.CenterVertically) {
            Text(if (state.audioOn) "Micrófono activo" else "Micrófono silenciado", color = HrColors.TextPrimary, modifier = Modifier.weight(1f))
            Switch(checked = state.audioOn, onCheckedChange = onAudioToggle, modifier = Modifier.semantics { contentDescription = "Micrófono" })
        }
    }
}
