package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.background
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material.icons.filled.VideoLibrary
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.BorderStroke
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.formatClock
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.protocol.ReadingOrigin
import com.hrmediasports.studio.protocol.formatAge
import com.hrmediasports.studio.protocol.isStale
import com.hrmediasports.studio.ui.ReplayClip
import com.hrmediasports.studio.ui.ScoreboardUiState
import com.hrmediasports.studio.ui.VideoSource
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/** Vista previa: la fuente de trabajo (borde naranja) + el botón explícito "Poner al aire". */
@Composable
fun PreviewPanel(
    source: VideoSource?,
    match: MatchState,
    canTakeToAir: Boolean,
    alreadyOnAir: Boolean,
    onTakeToAir: () -> Unit,
    modifier: Modifier = Modifier,
) {
    PanelFrame(
        title = "VISTA PREVIA",
        modifier = modifier,
        borderColor = if (source != null) HrColors.Orange else HrColors.Outline,
        borderWidth = if (source != null) 3.dp else 1.dp,
        badge = { if (source != null) StatusChip("SELECCIÓN", HrColors.Orange) },
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (source != null) {
                SourceView(source, match, Modifier.fillMaxWidth().aspectRatio(16f / 9f))
            } else {
                EmptyVideo("Elige una fuente para prepararla")
            }
            Button(
                onClick = onTakeToAir,
                enabled = canTakeToAir,
                modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = HrColors.Orange,
                    contentColor = Color.Black,
                    disabledContainerColor = HrColors.PanelRaised,
                    disabledContentColor = HrColors.TextMuted,
                ),
            ) {
                Icon(Icons.Filled.PlayArrow, contentDescription = null)
                Text(
                    if (alreadyOnAir) "YA ESTÁ AL AIRE" else "PONER AL AIRE",
                    fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.padding(start = 6.dp),
                )
            }
        }
    }
}

/** Salida: lo que está al aire (borde rojo). */
@Composable
fun ProgramPanel(source: VideoSource?, match: MatchState, modifier: Modifier = Modifier) {
    PanelFrame(
        title = "AL AIRE",
        modifier = modifier,
        borderColor = if (source != null) HrColors.OnAirRed else HrColors.Outline,
        borderWidth = if (source != null) 3.dp else 1.dp,
        badge = {
            if (source != null) StatusChip("AL AIRE", HrColors.OnAirRed, filled = true)
            else StatusChip("SIN SALIDA", HrColors.TextMuted)
        },
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (source != null) {
                SourceView(source, match, Modifier.fillMaxWidth().aspectRatio(16f / 9f))
            } else {
                EmptyVideo("Nada al aire. Prepara una fuente y pulsa «Poner al aire».")
            }
            Text(
                "Salida de la interfaz de demostración: no se emite ni se graba.",
                color = HrColors.TextMuted,
                fontSize = 12.sp,
            )
        }
    }
}

@Composable
private fun EmptyVideo(text: String) {
    Box(
        Modifier
            .fillMaxWidth()
            .aspectRatio(16f / 9f)
            .clip(RoundedCornerShape(8.dp))
            .background(HrColors.PanelRaised),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = HrColors.TextMuted, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(12.dp))
    }
}

/** Panel de marcador recibido desde otro celular. */
@Composable
fun ScoreboardPanel(
    state: ScoreboardUiState,
    onManualCorrection: () -> Unit,
    onDiscardCorrection: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val match = state.match
    PanelFrame(title = "MARCADOR AUTOMÁTICO", modifier = modifier) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            ScoreboardConnection(state)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCell("LOCAL", match.homePoints.toString(), Modifier.weight(1f), big = true)
                StatCell("VISITA", match.awayPoints.toString(), Modifier.weight(1f), big = true)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCell("TIEMPO", formatClock(match.clockSeconds), Modifier.weight(1.3f))
                StatCell("CUARTO", match.period.toString(), Modifier.weight(1f))
                StatCell("POSESIÓN", match.possessionSeconds.toString(), Modifier.weight(1f), accent = true)
            }
            OutlinedButton(
                onClick = onManualCorrection,
                modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                border = BorderStroke(1.dp, HrColors.TextPrimary),
            ) {
                Icon(Icons.Filled.Edit, contentDescription = null, tint = HrColors.TextPrimary, modifier = Modifier.size(18.dp))
                Text("Corrección manual", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp))
            }
            if (state.origin == ReadingOrigin.Manual) {
                OutlinedButton(
                    onClick = onDiscardCorrection,
                    modifier = Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch),
                    border = BorderStroke(1.dp, HrColors.Outline),
                ) { Text("Descartar corrección", color = HrColors.TextMuted) }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Filled.Sync, contentDescription = null, tint = HrColors.TextMuted, modifier = Modifier.size(16.dp))
                Text(readingText(state), color = HrColors.TextMuted, fontSize = 13.sp)
            }
        }
    }
}

private fun readingText(state: ScoreboardUiState): String = when {
    state.origin == ReadingOrigin.Manual -> "Valores corregidos a mano"
    state.ageMillis == null -> "Última lectura: sin lecturas"
    isStale(state.ageMillis) -> "Última lectura: ${formatAge(state.ageMillis)} (desactualizada)"
    else -> "Última lectura: ${formatAge(state.ageMillis)}"
}

@Composable
private fun ScoreboardConnection(state: ScoreboardUiState) {
    val (text, color) = when {
        state.origin == ReadingOrigin.Demo -> "Datos de ejemplo · sin pizarra conectada" to HrColors.Amber
        state.link == LinkState.Connected && state.ageMillis != null && isStale(state.ageMillis) ->
            "Pizarra conectada · sin datos recientes" to HrColors.Amber
        state.link == LinkState.Connected -> "Pizarra conectada" to HrColors.Turquoise
        state.link == LinkState.Connecting -> "Conectando con la pizarra…" to HrColors.Amber
        else -> "Pizarra sin conexión" to HrColors.TextMuted
    }
    Row(
        Modifier
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
private fun StatCell(label: String, value: String, modifier: Modifier = Modifier, big: Boolean = false, accent: Boolean = false) {
    Column(
        modifier
            .clip(RoundedCornerShape(8.dp))
            .background(HrColors.PanelRaised)
            .padding(vertical = 8.dp, horizontal = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, color = HrColors.TextMuted, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Text(
            value,
            color = if (accent) HrColors.Orange else HrColors.TextPrimary,
            fontSize = if (big) 40.sp else 24.sp,
            fontWeight = FontWeight.Black,
            maxLines = 1,
        )
    }
}

/** Repeticiones: lista y botón de guardado. Vacío y deshabilitado si no hay búfer real. */
@Composable
fun ReplaysPanel(
    clips: List<ReplayClip>,
    bufferAvailable: Boolean,
    onSaveLast20: () -> Unit,
    modifier: Modifier = Modifier,
) {
    PanelFrame(title = "REPETICIONES", modifier = modifier) {
        Row(horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap), verticalAlignment = Alignment.CenterVertically) {
            if (clips.isEmpty()) {
                Box(
                    Modifier
                        .weight(1f)
                        .heightIn(min = 72.dp)
                        .clip(RoundedCornerShape(8.dp))
                        .background(HrColors.PanelRaised)
                        .padding(12.dp),
                    contentAlignment = Alignment.CenterStart,
                ) {
                    Text(
                        if (bufferAvailable) "Aún no hay repeticiones guardadas."
                        else "Sin repeticiones. El búfer de video no está implementado en esta versión.",
                        color = HrColors.TextMuted,
                        fontSize = 13.sp,
                    )
                }
            } else {
                LazyRow(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(clips, key = { it.id }) { clip ->
                        Row(
                            Modifier
                                .heightIn(min = 64.dp)
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
            OutlinedButton(
                onClick = onSaveLast20,
                modifier = Modifier.width(150.dp).heightIn(min = 72.dp),
                border = BorderStroke(2.dp, if (bufferAvailable) HrColors.Orange else HrColors.Outline),
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(
                        Icons.Filled.VideoLibrary,
                        contentDescription = null,
                        tint = if (bufferAvailable) HrColors.Orange else HrColors.TextMuted,
                    )
                    Text(
                        "GUARDAR ÚLTIMOS 20 S",
                        color = if (bufferAvailable) HrColors.TextPrimary else HrColors.TextMuted,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center,
                    )
                    if (!bufferAvailable) Text("No disponible", color = HrColors.TextMuted, fontSize = 10.sp)
                }
            }
        }
    }
}
