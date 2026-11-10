package com.hrmediasports.studio.ui.reader

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.Save
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.hrmediasports.studio.data.SharedPrefsZoneTemplateStore
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.ui.components.ConnectionPill
import com.hrmediasports.studio.ui.components.DemoBanner
import com.hrmediasports.studio.ui.components.HrBrandBar
import com.hrmediasports.studio.ui.components.PanelFrame
import com.hrmediasports.studio.ui.components.StatCell
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/** Encuadre de referencia del diseño (ancho/alto de la imagen de la pizarra). */
private const val FRAME_ASPECT = 917f / 650f

@Composable
fun ReaderApp() {
    val context = LocalContext.current.applicationContext
    val vm: ReaderViewModel = viewModel(
        factory = viewModelFactory { initializer { ReaderViewModel(SharedPrefsZoneTemplateStore(context)) } },
    )
    val state by vm.state.collectAsStateWithLifecycle()
    ReaderContent(
        state = state,
        onToggleEditing = vm::toggleEditing,
        onSelectZone = vm::selectZone,
        onMove = vm::moveSelected,
        onResize = vm::resizeSelected,
        onReset = vm::resetZones,
        onSave = vm::saveTemplate,
        onStart = vm::onStartSendClick,
        onMessageShown = vm::consumeMessage,
    )
}

@Composable
fun ReaderContent(
    state: ReaderUiState,
    onToggleEditing: () -> Unit,
    onSelectZone: (ZoneId) -> Unit,
    onMove: (Float, Float) -> Unit,
    onResize: (Float) -> Unit,
    onReset: () -> Unit,
    onSave: () -> Unit,
    onStart: () -> Unit,
    onMessageShown: () -> Unit,
) {
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            onMessageShown()
        }
    }
    Scaffold(
        containerColor = HrColors.Background,
        snackbarHost = { SnackbarHost(snackbar) },
        contentWindowInsets = WindowInsets(0),
        modifier = Modifier.fillMaxSize().windowInsetsPadding(WindowInsets.safeDrawing),
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            HrBrandBar(title = "Lector de pizarra", compact = true)
            if (state.isDemo) {
                DemoBanner(
                    text = "Interfaz de muestra: no hay cámara, reconocimiento ni envío reales. " +
                        "Solo el ajuste y guardado de zonas funcionan.",
                )
            }
            Column(
                Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                LinkPill(state.link)
                if (state.isDemo) {
                    StatusChip("SIN CÁMARA · MODO DEMOSTRACIÓN", HrColors.Amber, dense = true)
                }
                CameraFrame(state, onSelectZone, onMove)
                Text(
                    if (state.editing) "Toca una zona y arrástrala; usa − y + para cambiar su tamaño."
                    else "Ajusta las zonas sobre los dígitos.",
                    color = HrColors.TextMuted,
                    fontSize = 14.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                if (state.editing) ResizeBar(state, onResize, onReset)
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    OrangeOutlined(
                        if (state.editing) "Terminar ajuste" else "Ajustar zonas",
                        Icons.Filled.Tune,
                        onToggleEditing,
                        Modifier.weight(1f),
                    )
                    OrangeOutlined("Guardar plantilla", Icons.Filled.Save, onSave, Modifier.weight(1f))
                }
                Text(
                    when (state.templateStatus) {
                        TemplateStatus.Default -> "Plantilla predeterminada (sin guardar)"
                        TemplateStatus.Saved -> "Plantilla guardada en este dispositivo"
                        TemplateStatus.Unsaved -> "Hay cambios sin guardar"
                    },
                    color = if (state.templateStatus == TemplateStatus.Unsaved) HrColors.Amber else HrColors.TextMuted,
                    fontSize = 13.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
                DetectedPanel(state)
                StartButton(state.canSend && state.canRecognize, onStart)
                Text(
                    "Mantén el celular fijo y la pizarra visible.",
                    color = HrColors.TextMuted,
                    fontSize = 14.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

@Composable
private fun LinkPill(link: LinkState) {
    val (text, color) = when (link) {
        LinkState.Connected -> "Conectado al estudio" to HrColors.Turquoise
        LinkState.Connecting -> "Conectando con el estudio…" to HrColors.Amber
        LinkState.NotConnected -> "Sin conexión con el estudio" to HrColors.TextMuted
    }
    ConnectionPill(text, color)
}

@Composable
private fun OrangeOutlined(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit, modifier: Modifier) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.heightIn(min = 56.dp),
        border = BorderStroke(2.dp, HrColors.Orange),
    ) {
        Icon(icon, contentDescription = null, tint = HrColors.TextPrimary)
        Text(text, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp), textAlign = TextAlign.Center)
    }
}

@Composable
private fun ResizeBar(state: ReaderUiState, onResize: (Float) -> Unit, onReset: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            "Zona: ${state.selectedZone?.label ?: "—"}",
            color = HrColors.TextPrimary,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.weight(1f),
        )
        IconButton(onClick = { onResize(0.9f) }, modifier = Modifier.heightIn(min = HrDimens.MinTouch).size(HrDimens.MinTouch)) {
            Icon(Icons.Filled.Remove, contentDescription = "Reducir zona")
        }
        IconButton(onClick = { onResize(1.1f) }, modifier = Modifier.heightIn(min = HrDimens.MinTouch).size(HrDimens.MinTouch)) {
            Icon(Icons.Filled.Add, contentDescription = "Ampliar zona")
        }
        OutlinedButton(onClick = onReset, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) { Text("Restablecer") }
    }
}

/**
 * Encuadre de la cámara. No hay cámara en esta versión: se muestra un marcador rotulado con
 * una cuadrícula de guía; las zonas encima sí son editables y se guardan.
 */
@Composable
private fun CameraFrame(state: ReaderUiState, onSelectZone: (ZoneId) -> Unit, onMove: (Float, Float) -> Unit) {
    BoxWithConstraints(
        Modifier
            .fillMaxWidth()
            .aspectRatio(FRAME_ASPECT)
            .clip(RoundedCornerShape(10.dp))
            .background(Brush.verticalGradient(listOf(Color(0xFF1B2638), Color(0xFF0E1522)))),
    ) {
        val density = LocalDensity.current
        val widthPx = with(density) { maxWidth.toPx() }
        val heightPx = with(density) { maxHeight.toPx() }

        Canvas(Modifier.fillMaxSize()) {
            val line = Color.White.copy(alpha = 0.08f)
            for (i in 1..2) {
                drawLine(line, Offset(size.width * i / 3f, 0f), Offset(size.width * i / 3f, size.height))
                drawLine(line, Offset(0f, size.height * i / 3f), Offset(size.width, size.height * i / 3f))
            }
        }
        // Capa 1: recuadros de las zonas (arrastrables en modo ajuste).
        ZoneId.entries.forEach { id ->
            val rect = state.zones[id]
            val selected = state.editing && state.selectedZone == id
            val color = if (selected) Color.White else HrColors.Orange
            Box(
                Modifier
                    .offset { IntOffset((rect.left * widthPx).toInt(), (rect.top * heightPx).toInt()) }
                    .size(with(density) { (rect.width * widthPx).toDp() }, with(density) { (rect.height * heightPx).toDp() })
                    .background(color.copy(alpha = if (selected) 0.18f else 0.06f))
                    .border(BorderStroke(if (selected) 3.dp else 2.dp, color))
                    .selectable(selected = selected, enabled = state.editing, role = Role.RadioButton, onClick = { onSelectZone(id) })
                    .pointerInput(state.editing, state.selectedZone, id) {
                        if (state.editing && state.selectedZone == id) {
                            detectDragGestures { change, drag ->
                                change.consume()
                                onMove(drag.x / widthPx, drag.y / heightPx)
                            }
                        }
                    },
            )
        }
        // Capa 2: rótulos fuera del recuadro, para que nunca los recorte el tamaño de la zona.
        ZoneId.entries.forEach { id ->
            val rect = state.zones[id]
            val labelHeightPx = with(density) { 22.dp.toPx() }
            Box(
                Modifier.offset {
                    IntOffset(
                        (rect.left * widthPx).toInt(),
                        (rect.top * heightPx - labelHeightPx).coerceAtLeast(0f).toInt(),
                    )
                },
            ) { StatusChip(id.label, HrColors.Orange, filled = true, dense = true) }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DetectedPanel(state: ReaderUiState) {
    PanelFrame(
        title = "DATOS DETECTADOS",
        badge = { StatusChip(if (state.canRecognize) "LECTURA" else "DATOS DEMO", HrColors.Amber) },
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            val m = state.sample
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                val cell = Modifier.weight(1f).widthIn(min = 84.dp)
                StatCell("LOCAL", m?.homePoints?.toString() ?: "—", cell)
                StatCell("VISITA", m?.awayPoints?.toString() ?: "—", cell)
                StatCell("TIEMPO", m?.let { formatClock(it.clockSeconds) } ?: "—", cell)
                StatCell("CUARTO", m?.period?.toString() ?: "—", cell)
                StatCell("POSESIÓN", m?.possessionSeconds?.toString() ?: "—", cell)
            }
            Text(
                if (state.canRecognize) "Vista previa local" else "Datos demo: no se está leyendo ninguna pizarra.",
                color = HrColors.TextMuted,
                fontSize = 13.sp,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun StartButton(available: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        modifier = Modifier
            .fillMaxWidth()
            .heightIn(min = 64.dp)
            .semantics { stateDescription = if (available) "Disponible" else "No disponible" },
        colors = ButtonDefaults.buttonColors(
            containerColor = if (available) HrColors.Orange else HrColors.PanelRaised,
            contentColor = if (available) Color.Black else HrColors.TextMuted,
        ),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.PlayArrow, contentDescription = null)
                Text("INICIAR ENVÍO", fontSize = 20.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(start = 6.dp))
            }
            if (!available) Text("No disponible en modo demostración", fontSize = 12.sp)
        }
    }
}
