package com.hrmediasports.studio.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.ui.MonitorView
import com.hrmediasports.studio.ui.SourceKind
import com.hrmediasports.studio.ui.StudioPanel
import com.hrmediasports.studio.ui.StudioUiState
import com.hrmediasports.studio.ui.VideoSource
import com.hrmediasports.studio.ui.components.MonitorBox
import com.hrmediasports.studio.ui.components.PanelDialog
import com.hrmediasports.studio.ui.components.PreviewPanel
import com.hrmediasports.studio.ui.components.ProgramPanel
import com.hrmediasports.studio.ui.components.ReplaysPanelContent
import com.hrmediasports.studio.ui.components.ReplaysTray
import com.hrmediasports.studio.ui.components.ScoreboardPanel
import com.hrmediasports.studio.ui.components.ScoreboardSummaryButton
import com.hrmediasports.studio.ui.components.ScrollFade
import com.hrmediasports.studio.ui.components.SourceTile
import com.hrmediasports.studio.ui.components.TakeToAirButton
import com.hrmediasports.studio.ui.content.BreakBar
import com.hrmediasports.studio.ui.content.ContentActions
import com.hrmediasports.studio.ui.dataLabel
import com.hrmediasports.studio.ui.previewBreakView
import com.hrmediasports.studio.ui.programBreakView
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/** Acciones que la pantalla Estudio puede disparar; el estado vive en el ViewModel. */
data class StudioActions(
    val onSelectSource: (String) -> Unit = {},
    val onTakeToAir: () -> Unit = {},
    val onSaveReplay: () -> Unit = {},
    val onManualCorrection: () -> Unit = {},
    val onDiscardCorrection: () -> Unit = {},
    val onSetMonitorView: (MonitorView) -> Unit = {},
    val onToggleReplaysTray: () -> Unit = {},
    val onOpenPanel: (StudioPanel) -> Unit = {},
    val onClosePanel: () -> Unit = {},
)

/** Ancho mínimo útil para mostrar ambos monitores lado a lado en el modo bajo. */
private val BOTH_MONITORS_MIN_WIDTH = 440.dp

/**
 * El layout se decide con el espacio REAL del área de contenido (ya sin barras del sistema,
 * navegación ni cabecera), no con el tamaño nominal de la pantalla:
 *  - Ancho: monitores arriba, fuentes compactas debajo, bandeja de repeticiones, marcador a la derecha.
 *  - Bajo: prioriza Al aire; alterna Previa/Al aire si no caben ambos; repeticiones y marcador
 *    completos se abren en paneles aparte.
 * Los monitores nunca están dentro de un contenedor con desplazamiento.
 */
@Composable
fun StudioScreen(state: StudioUiState, actions: StudioActions, modifier: Modifier = Modifier, content: ContentActions = ContentActions()) {
    BoxWithConstraints(modifier.fillMaxSize()) {
        // Los umbrales crecen con la escala de texto: con letra grande hace falta más espacio real.
        val fs = LocalDensity.current.fontScale
        val wide = maxWidth >= 720.dp * fs && maxHeight >= 380.dp * fs
        if (wide) WideLayout(state, actions, content, maxWidth, maxHeight) else LowLayout(state, actions, content, maxWidth, maxHeight, fs)
    }

    when (state.openPanel) {
        StudioPanel.Replays -> PanelDialog("REPETICIONES", actions.onClosePanel) {
            ReplaysPanelContent(state.replays, state.replayBufferAvailable, actions.onSaveReplay)
        }
        StudioPanel.Scoreboard -> PanelDialog(null, actions.onClosePanel) {
            ScoreboardPanel(state.scoreboard, actions.onManualCorrection, actions.onDiscardCorrection, Modifier.fillMaxWidth(), compact = true)
        }
        null -> Unit
    }
}

@Composable
private fun WideLayout(state: StudioUiState, actions: StudioActions, content: ContentActions, width: Dp, height: Dp) {
    val match = state.scoreboard.match
    val note = state.scoreboard.dataLabel()
    val scoreboardWidth = (width * 0.28f).coerceIn(236.dp, 320.dp)
    Row(Modifier.fillMaxSize().padding(HrDimens.Gap), horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap)) {
        Column(Modifier.weight(1f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(HrDimens.Gap)) {
            Row(Modifier.fillMaxWidth().weight(1f), horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap)) {
                PreviewPanel(
                    source = state.previewSource,
                    match = match,
                    dataNote = note,
                    canTakeToAir = state.canTakeToAir,
                    alreadyOnAir = state.previewSourceId != null && state.previewSourceId == state.programSourceId,
                    onTakeToAir = actions.onTakeToAir,
                    modifier = Modifier.weight(1f).fillMaxHeight(),
                    breakView = state.previewBreakView(),
                )
                ProgramPanel(state.programSource, match, note, Modifier.weight(1.3f).fillMaxHeight(), state.programBreakView())
            }
            if (state.breakOnAir) BreakBar(state, content)
            // Con la bandeja abierta, su lista ocupa el lugar de las fuentes: los monitores no se achican.
            if (!state.replaysTrayOpen || (state.breakOnAir && height < 560.dp)) SourcesRow(state, actions, dense = false)
            // En ventanas poco altas, mientras hay un descanso al aire la bandeja de repeticiones cede su lugar
            // a la barra del descanso: así los monitores (y Al aire) siguen visibles.
            if (!(state.breakOnAir && height < 560.dp)) {
                ReplaysTray(
                    clips = state.replays,
                    bufferAvailable = state.replayBufferAvailable,
                    open = state.replaysTrayOpen,
                    onToggle = actions.onToggleReplaysTray,
                    onSaveLast20 = actions.onSaveReplay,
                )
            }
        }
        ScoreboardPanel(
            state.scoreboard,
            actions.onManualCorrection,
            actions.onDiscardCorrection,
            Modifier.width(scoreboardWidth).fillMaxHeight().verticalScroll(rememberScrollState()),
            compact = true,
        )
    }
}

@Composable
private fun LowLayout(state: StudioUiState, actions: StudioActions, content: ContentActions, width: Dp, height: Dp, fontScale: Float) {
    val match = state.scoreboard.match
    val note = state.scoreboard.dataLabel()
    // Con letra muy grande las fuentes pasan a una columna: así el lateral no devora el ancho del monitor.
    val columns = if (fontScale > 1.3f) 1 else 2
    val sideMin = if (columns == 1) 100.dp * fontScale else 200.dp * fontScale
    val sideWidth = (width * 0.38f).coerceIn(sideMin, maxOf(sideMin, 340.dp))

    // Espacio real del lado izquierdo (descontando márgenes de 6 dp y la separación).
    val leftWidth = width - sideWidth - 18.dp
    val leftHeight = height - 12.dp
    val showBoth = leftWidth >= BOTH_MONITORS_MIN_WIDTH && leftHeight >= 200.dp
    // Si ambos monitores caben y sobra alto, se aprovecha: fuentes bajo los monitores y marcador completo a la derecha.
    val videoIfBoth = (leftWidth - 6.dp) / 2 * (9f / 16f)
    val spare = leftHeight - 100.dp * fontScale - videoIfBoth
    val roomy = showBoth && spare >= 70.dp * fontScale

    Row(Modifier.fillMaxSize().padding(6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Column(Modifier.weight(1f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            // Descanso al aire con letra muy grande: se quitan las pestañas y se muestra solo Al aire, que es lo prioritario.
            val onlyProgram = state.breakOnAir && fontScale > 1.3f && !showBoth
            if (!onlyProgram) MonitorHeader(showBoth, state, actions.onSetMonitorView)
            if (showBoth) {
                Row(Modifier.fillMaxWidth().weight(1f), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PreviewMonitor(state, match, note, Modifier.weight(1f).fillMaxHeight())
                    ProgramMonitor(state, match, note, Modifier.weight(1f).fillMaxHeight())
                }
            } else if (state.monitorView == MonitorView.Preview && !onlyProgram) {
                PreviewMonitor(state, match, note, Modifier.fillMaxWidth().weight(1f))
            } else {
                ProgramMonitor(state, match, note, Modifier.fillMaxWidth().weight(1f))
            }
            if (roomy) SourcesRow(state, actions, dense = true)
            if (state.breakOnAir) BreakBar(state, content)
            // Con un descanso al aire y nada nuevo preparado, «Volver al partido» ocupa el lugar de «Poner al aire».
            if (!state.breakOnAir || state.canTakeToAir) {
                TakeToAirButton(
                    canTakeToAir = state.canTakeToAir,
                    alreadyOnAir = state.previewSourceId != null && state.previewSourceId == state.programSourceId,
                    previewLabel = state.previewSource?.label,
                    onClick = actions.onTakeToAir,
                )
            }
        }
        val sideScroll = rememberScrollState()
        ScrollFade(sideScroll, HrColors.Background, Modifier.width(sideWidth).fillMaxHeight()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(sideScroll),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            if (roomy) {
                ScoreboardPanel(state.scoreboard, actions.onManualCorrection, actions.onDiscardCorrection, Modifier.fillMaxWidth(), compact = true)
            } else {
                SourcesGrid(state, actions, columns)
                ScoreboardSummaryButton(state.scoreboard, { actions.onOpenPanel(StudioPanel.Scoreboard) })
            }
            OutlinedButton(
                onClick = { actions.onOpenPanel(StudioPanel.Replays) },
                modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                border = BorderStroke(1.dp, HrColors.Outline),
            ) { Text("REPETICIONES (${state.replays.size})", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold) }
        }
        }
    }
}

@Composable
private fun PreviewMonitor(state: StudioUiState, match: com.hrmediasports.studio.domain.MatchState?, note: String?, modifier: Modifier) {
    MonitorBox(state.previewSource, match, note, HrColors.Orange, "Elige una fuente para prepararla", modifier, state.previewBreakView())
}

@Composable
private fun ProgramMonitor(state: StudioUiState, match: com.hrmediasports.studio.domain.MatchState?, note: String?, modifier: Modifier) {
    MonitorBox(state.programSource, match, note, HrColors.OnAirRed, "Nada al aire", modifier, state.programBreakView())
}

/**
 * Cabecera de los monitores en modo bajo: dos rótulos fijos si caben ambos, o pestañas
 * PREVIA / AL AIRE (48 dp) para alternar si no caben.
 */
@Composable
private fun MonitorHeader(showBoth: Boolean, state: StudioUiState, onSet: (MonitorView) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        MonitorTab("PREVIA", HrColors.Orange, filled = false, selected = showBoth || state.monitorView == MonitorView.Preview,
            enabled = !showBoth, onClick = { onSet(MonitorView.Preview) }, modifier = Modifier.weight(1f))
        MonitorTab("AL AIRE", HrColors.OnAirRed, filled = true, selected = showBoth || state.monitorView == MonitorView.Program,
            enabled = !showBoth, onClick = { onSet(MonitorView.Program) }, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun MonitorTab(
    text: String,
    color: androidx.compose.ui.graphics.Color,
    filled: Boolean,
    selected: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
    modifier: Modifier,
) {
    Surface(
        modifier = modifier
            .heightIn(min = if (enabled) HrDimens.MinTouch else 32.dp)
            .selectable(selected = selected, enabled = enabled, role = Role.Tab, onClick = onClick),
        shape = RoundedCornerShape(8.dp),
        color = if (selected && filled) color else androidx.compose.ui.graphics.Color.Transparent,
        border = BorderStroke(if (selected) 2.dp else 1.dp, if (selected) color else HrColors.Outline),
    ) {
        Column(Modifier.padding(horizontal = 8.dp, vertical = 4.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
            Text(
                text,
                color = if (selected) HrColors.TextPrimary else HrColors.TextMuted,
                fontWeight = FontWeight.ExtraBold,
                fontSize = 14.sp,
                textAlign = TextAlign.Center,
            )
        }
    }
}

/** Fuentes en una fila (modo ancho). Todas del mismo alto aunque unas tengan etiquetas y otras no. */
@Composable
private fun SourcesRow(state: StudioUiState, actions: StudioActions, dense: Boolean) {
    Row(
        Modifier.fillMaxWidth().height(IntrinsicSize.Min),
        horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap),
    ) {
        state.sources.forEach { source -> tile(source, state, actions, dense, Modifier.weight(1f).fillMaxHeight()) }
    }
}

/** Fuentes en cuadrícula 2×2 (modo bajo). */
@Composable
private fun SourcesGrid(state: StudioUiState, actions: StudioActions, columns: Int) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        state.sources.chunked(columns).forEach { pair ->
            Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                pair.forEach { source -> tile(source, state, actions, true, Modifier.weight(1f).fillMaxHeight()) }
            }
        }
    }
}

@Composable
private fun tile(source: VideoSource, state: StudioUiState, actions: StudioActions, dense: Boolean, modifier: Modifier) {
    SourceTile(
        source = source,
        selected = source.id == state.previewSourceId,
        onAir = source.id == state.programSourceId,
        onClick = { actions.onSelectSource(source.id) },
        modifier = modifier,
        dense = dense,
    )
}
