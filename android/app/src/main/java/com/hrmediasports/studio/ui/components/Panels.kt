package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.protocol.formatAge
import com.hrmediasports.studio.protocol.isStale
import com.hrmediasports.studio.ui.BreakView
import com.hrmediasports.studio.ui.ReplayClip
import com.hrmediasports.studio.ui.ScoreboardUiState
import com.hrmediasports.studio.ui.VideoSource
import com.hrmediasports.studio.ui.dataLabel
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/** Botón explícito que envía a la salida lo que está en Vista previa. */
@Composable
fun TakeToAirButton(
    canTakeToAir: Boolean,
    alreadyOnAir: Boolean,
    previewLabel: String?,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Button(
        onClick = onClick,
        enabled = canTakeToAir,
        modifier = modifier.fillMaxWidth().heightIn(min = 52.dp),
        colors = ButtonDefaults.buttonColors(
            containerColor = HrColors.Orange,
            contentColor = Color.Black,
            disabledContainerColor = HrColors.PanelRaised,
            disabledContentColor = HrColors.TextMuted,
        ),
    ) {
        Icon(Icons.Filled.PlayArrow, contentDescription = null)
        Column(Modifier.padding(start = 6.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                if (alreadyOnAir) "YA ESTÁ AL AIRE" else "PONER AL AIRE",
                fontWeight = FontWeight.ExtraBold,
                textAlign = TextAlign.Center,
            )
            // Con letra muy grande se omite el nombre de la fuente para no robar alto al monitor.
            if (previewLabel != null && !alreadyOnAir && LocalDensity.current.fontScale <= 1.3f) {
                Text(previewLabel, fontSize = 12.sp, textAlign = TextAlign.Center)
            }
        }
    }
}

/** Vista previa (pantallas anchas): fuente preparada, borde naranja. */
@Composable
fun PreviewPanel(
    source: VideoSource?,
    match: MatchState?,
    dataNote: String?,
    canTakeToAir: Boolean,
    alreadyOnAir: Boolean,
    onTakeToAir: () -> Unit,
    modifier: Modifier = Modifier,
    breakView: BreakView? = null,
) {
    PanelFrame(
        title = "VISTA PREVIA",
        modifier = modifier,
        borderColor = if (source != null) HrColors.Orange else HrColors.Outline,
        borderWidth = if (source != null) 3.dp else 1.dp,
        badge = { if (source != null) StatusChip(source.label.uppercase(), HrColors.Orange) },
    ) {
        FitVideo(Modifier.weight(1f).fillMaxWidth()) {
            if (source != null) SourceView(source, match, Modifier.fillMaxSize(), dataNote, breakView)
            else EmptyVideo("Elige una fuente para prepararla")
        }
        TakeToAirButton(canTakeToAir, alreadyOnAir, source?.label, onTakeToAir)
    }
}

/** Salida (pantallas anchas): fuente al aire, borde rojo. */
@Composable
fun ProgramPanel(source: VideoSource?, match: MatchState?, dataNote: String?, modifier: Modifier = Modifier, breakView: BreakView? = null) {
    PanelFrame(
        title = "AL AIRE",
        modifier = modifier,
        borderColor = if (source != null) HrColors.OnAirRed else HrColors.Outline,
        borderWidth = if (source != null) 3.dp else 1.dp,
        badge = {
            if (source != null) StatusChip(source.label.uppercase(), HrColors.OnAirRed, filled = true)
            else StatusChip("SIN SALIDA", HrColors.TextMuted)
        },
    ) {
        FitVideo(Modifier.weight(1f).fillMaxWidth()) {
            if (source != null) SourceView(source, match, Modifier.fillMaxSize(), dataNote, breakView)
            else EmptyVideo("Nada al aire. Prepara una fuente y pulsa «Poner al aire».")
        }
        Box(Modifier.fillMaxWidth().heightIn(min = 24.dp), contentAlignment = Alignment.CenterStart) {
            Text(
                "Salida de demostración: no se emite ni se graba.",
                color = HrColors.TextMuted,
                fontSize = 12.sp,
            )
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
/** Panel de marcador recibido desde otro celular. `compact` reduce tamaños sin bajar los controles de 48 dp. */
@Composable
fun ScoreboardPanel(
    state: ScoreboardUiState,
    onManualCorrection: () -> Unit,
    onDiscardCorrection: () -> Unit,
    modifier: Modifier = Modifier,
    compact: Boolean = false,
) {
    val m = state.match
    val label = state.dataLabel()
    PanelFrame(
        title = if (compact) "MARCADOR" else "MARCADOR AUTOMÁTICO",
        modifier = modifier,
        badge = { if (label != null) StatusChip(label, HrColors.Amber, dense = true) },
    ) {
        ScoreboardConnection(state, compact)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatCell("LOCAL", m?.homePoints?.toString() ?: "—", Modifier.weight(1f), big = true, compact = compact)
            StatCell("VISITA", m?.awayPoints?.toString() ?: "—", Modifier.weight(1f), big = true, compact = compact)
        }
        // Filas flexibles: según el ancho real caben 2 o 3 por fila y ninguna etiqueta se corta.
        FlowRow(
            Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(if (compact) 6.dp else 8.dp),
            verticalArrangement = Arrangement.spacedBy(if (compact) 6.dp else 8.dp),
        ) {
            val cell = Modifier.weight(1f).widthIn(min = if (compact) 64.dp else 84.dp)
            StatCell("TIEMPO", m?.let { formatClock(it.clockSeconds) } ?: "—", cell, compact = compact)
            StatCell("CUARTO", m?.period?.toString() ?: "—", cell, compact = compact)
            StatCell("POSESIÓN", m?.possessionSeconds?.toString() ?: "—", cell, accent = true, compact = compact)
        }
        OutlinedButton(
            onClick = onManualCorrection,
            modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
            border = BorderStroke(1.dp, HrColors.TextPrimary),
        ) {
            Icon(Icons.Filled.Edit, contentDescription = null, tint = HrColors.TextPrimary, modifier = Modifier.size(18.dp))
            Text("Corrección manual", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp))
        }
        // Solo existe mientras haya una corrección manual activa.
        if (state.origin == ReadingOrigin.Manual) {
            if (state.newerReadingAvailable) {
                Text(
                    "Llegó una lectura nueva del lector. Se mantiene tu corrección hasta que la descartes.",
                    color = HrColors.Amber,
                    fontSize = 13.sp,
                )
            }
            OutlinedButton(
                onClick = onDiscardCorrection,
                modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                border = BorderStroke(1.dp, HrColors.Outline),
            ) { Text("Descartar corrección", color = HrColors.TextPrimary) }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Filled.Sync, contentDescription = null, tint = HrColors.TextMuted, modifier = Modifier.size(16.dp))
            Text(readingText(state), color = HrColors.TextMuted, fontSize = 13.sp)
        }
    }
}

/** Resumen de una línea del marcador; abre el panel completo (ventanas bajas). */
@Composable
fun ScoreboardSummaryButton(state: ScoreboardUiState, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val m = state.match
    val scores = if (m == null) "—" else "${m.homePoints} – ${m.awayPoints}  ·  ${formatClock(m.clockSeconds)}"
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.fillMaxWidth().heightIn(min = 56.dp).semantics { contentDescription = "Marcador $scores. Abrir panel" },
        border = BorderStroke(1.dp, HrColors.Outline),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 10.dp, vertical = 4.dp),
    ) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            Text("MARCADOR", color = HrColors.TextMuted, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            Text(scores, color = HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 16.sp)
            state.dataLabel()?.let { Text(it, color = HrColors.Amber, fontSize = 11.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

private fun readingText(state: ScoreboardUiState): String = when {
    state.origin == ReadingOrigin.Manual -> "Valores corregidos a mano"
    state.ageMillis == null -> "Última lectura: sin lecturas"
    isStale(state.ageMillis) || state.link != LinkState.Connected ->
        "Última lectura: ${formatAge(state.ageMillis)} (desactualizada)"
    else -> "Última lectura: ${formatAge(state.ageMillis)}"
}

@Composable
private fun ScoreboardConnection(state: ScoreboardUiState, compact: Boolean) {
    // En el panel compacto los textos son más cortos para caber en una línea.
    fun t(full: String, short: String) = if (compact) short else full
    val (text, color) = when {
        // Se perdió una conexión que existió: se conservan los últimos datos y se advierte.
        state.ageMillis != null && state.link == LinkState.NotConnected ->
            t("Conexión perdida · datos desactualizados", "Conexión perdida") to HrColors.Amber
        state.link == LinkState.Connected && state.ageMillis != null && isStale(state.ageMillis) ->
            t("Pizarra conectada · sin datos recientes", "Sin datos recientes") to HrColors.Amber
        state.link == LinkState.Connected && state.ageMillis == null ->
            t("Pizarra conectada · esperando datos", "Conectada · sin datos") to HrColors.Amber
        state.link == LinkState.Connected -> "Pizarra conectada" to HrColors.Turquoise
        state.link == LinkState.Connecting -> t("Conectando con la pizarra…", "Conectando…") to HrColors.Amber
        else -> "Pizarra sin conexión" to HrColors.TextMuted
    }
    ConnectionPill(text, color)
}

/** Franja de estado de conexión; el texto dice el estado, el color lo refuerza. */
@Composable
fun ConnectionPill(text: String, color: Color, modifier: Modifier = Modifier) {
    Row(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(color.copy(alpha = 0.14f))
            .padding(horizontal = 10.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        StatusDot(color)
        Text(text, color = color, fontWeight = FontWeight.Bold, fontSize = 14.sp)
    }
}

@Composable
fun StatCell(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    big: Boolean = false,
    accent: Boolean = false,
    compact: Boolean = false,
) {
    Column(
        modifier
            .clip(RoundedCornerShape(8.dp))
            .background(HrColors.PanelRaised)
            .padding(vertical = if (compact) 4.dp else 8.dp, horizontal = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, color = HrColors.TextMuted, fontSize = if (compact) 10.sp else 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
        Text(
            value,
            color = if (accent) HrColors.Orange else HrColors.TextPrimary,
            fontSize = if (big) (if (compact) 30.sp else 40.sp) else (if (compact) 20.sp else 24.sp),
            fontWeight = FontWeight.Black,
            maxLines = 1,
            softWrap = false,
        )
    }
}

private const val BUFFER_UNAVAILABLE = "El búfer de video no está implementado en esta versión."

@Composable
private fun SaveReplayButton(bufferAvailable: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.heightIn(min = HrDimens.MinTouch).widthIn(min = 150.dp),
        border = BorderStroke(2.dp, if (bufferAvailable) HrColors.Orange else HrColors.Outline),
    ) {
        Icon(
            Icons.Filled.VideoLibrary,
            contentDescription = null,
            tint = if (bufferAvailable) HrColors.Orange else HrColors.TextMuted,
            modifier = Modifier.size(20.dp),
        )
        Column(Modifier.padding(start = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                "GUARDAR ÚLTIMOS 20 S",
                color = if (bufferAvailable) HrColors.TextPrimary else HrColors.TextMuted,
                fontSize = 12.sp,
                fontWeight = FontWeight.ExtraBold,
                textAlign = TextAlign.Center,
            )
            if (!bufferAvailable) Text("No disponible", color = HrColors.TextMuted, fontSize = 11.sp)
        }
    }
}

/** Lista de clips o, si no hay, el motivo. */
@Composable
private fun ReplayList(clips: List<ReplayClip>, bufferAvailable: Boolean, modifier: Modifier = Modifier) {
    if (clips.isEmpty()) {
        Box(
            modifier
                .clip(RoundedCornerShape(8.dp))
                .background(HrColors.PanelRaised)
                .padding(12.dp),
            contentAlignment = Alignment.CenterStart,
        ) {
            Text(
                if (bufferAvailable) "Aún no hay repeticiones guardadas." else "Sin repeticiones. $BUFFER_UNAVAILABLE",
                color = HrColors.TextMuted,
                fontSize = 13.sp,
            )
        }
    } else {
        LazyRow(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(clips, key = { it.id }) { clip ->
                Row(
                    Modifier
                        .heightIn(min = 56.dp)
                        .clip(RoundedCornerShape(8.dp))
                        .background(HrColors.PanelRaised)
                        .padding(horizontal = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Icon(Icons.Filled.PlayArrow, contentDescription = null, tint = HrColors.TextPrimary)
                    Text("${clip.label} · ${clip.durationSeconds} s", color = HrColors.TextPrimary, fontSize = 14.sp)
                }
            }
        }
    }
}

/**
 * Bandeja de repeticiones (pantallas anchas): cerrada es una barra; abierta suma la lista debajo.
 * Los monitores se reparten el alto restante, así que nunca quedan tapados.
 */
@Composable
fun ReplaysTray(
    clips: List<ReplayClip>,
    bufferAvailable: Boolean,
    open: Boolean,
    onToggle: () -> Unit,
    onSaveLast20: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(HrDimens.PanelRadius),
        color = HrColors.Panel,
        border = BorderStroke(1.dp, HrColors.Outline),
    ) {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    "REPETICIONES (${clips.size})",
                    color = HrColors.TextPrimary,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 16.sp,
                    modifier = Modifier.weight(1f),
                )
                SaveReplayButton(bufferAvailable, onSaveLast20)
                IconButton(onClick = onToggle, modifier = Modifier.size(HrDimens.MinTouch)) {
                    Icon(
                        if (open) Icons.Filled.ExpandMore else Icons.Filled.ExpandLess,
                        contentDescription = if (open) "Cerrar bandeja de repeticiones" else "Abrir bandeja de repeticiones",
                        tint = HrColors.TextPrimary,
                    )
                }
            }
            if (open) ReplayList(clips, bufferAvailable, Modifier.fillMaxWidth().heightIn(min = 56.dp))
        }
    }
}

/** Contenido completo de repeticiones para el panel específico de ventanas bajas. */
@Composable
fun ReplaysPanelContent(
    clips: List<ReplayClip>,
    bufferAvailable: Boolean,
    onSaveLast20: () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        ReplayList(clips, bufferAvailable, Modifier.fillMaxWidth().heightIn(min = 72.dp))
        SaveReplayButton(bufferAvailable, onSaveLast20, Modifier.fillMaxWidth())
    }
}

/** Panel específico a pantalla casi completa, con cierre explícito de 48 dp. */
@Composable
fun PanelDialog(title: String?, onClose: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(
            Modifier.fillMaxWidth(0.94f).fillMaxHeight(0.94f),
            shape = RoundedCornerShape(HrDimens.PanelRadius),
            color = HrColors.Panel,
            border = BorderStroke(1.dp, HrColors.Outline),
        ) {
            Column(Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (title != null) Text(title, color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                val scroll = rememberScrollState()
                ScrollFade(scroll, HrColors.Panel, Modifier.weight(1f).fillMaxWidth()) {
                    Column(
                        Modifier.fillMaxSize().verticalScroll(scroll),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                        content = content,
                    )
                }
                OutlinedButton(
                    onClick = onClose,
                    modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                    border = BorderStroke(1.dp, HrColors.TextPrimary),
                ) { Text("Cerrar", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold) }
            }
        }
    }
}
