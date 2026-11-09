package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.SportsBasketball
import androidx.compose.material.icons.filled.VideocamOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Fill
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.ui.SourceKind
import com.hrmediasports.studio.ui.VideoSource
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Contenido visual de una fuente. Las cámaras NO tienen video en esta versión:
 * se muestra un marcador de posición rotulado. "Descanso" y "Resultado" son gráficos
 * locales reales (dibujados por la app), no capturas.
 */
@Composable
fun SourceView(
    source: VideoSource,
    match: MatchState,
    modifier: Modifier = Modifier,
    large: Boolean = true,
) {
    val titleSize = if (large) 22.sp else 12.sp
    val bodySize = if (large) 13.sp else 10.sp
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(8.dp))
            .background(Brush.verticalGradient(listOf(Color(0xFF1B2638), Color(0xFF0E1522)))),
        contentAlignment = Alignment.Center,
    ) {
        when (source.kind) {
            SourceKind.Camera -> Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Icon(
                    Icons.Filled.VideocamOff,
                    contentDescription = null,
                    tint = HrColors.TextMuted,
                    modifier = Modifier.size(if (large) 40.dp else 22.dp),
                )
                Text(source.label.uppercase(), color = HrColors.TextPrimary, fontSize = titleSize, fontWeight = FontWeight.ExtraBold)
                Text("Sin video · fuente de ejemplo", color = HrColors.TextMuted, fontSize = bodySize, textAlign = TextAlign.Center)
            }

            SourceKind.Break -> {
                Canvas(Modifier.fillMaxSize()) {
                    val stripe = size.width * 0.12f
                    for (i in 0..5) {
                        val x = size.width * (0.45f + i * 0.14f)
                        val path = Path().apply {
                            moveTo(x, 0f)
                            lineTo(x + stripe, 0f)
                            lineTo(x + stripe - size.height * 0.5f, size.height)
                            lineTo(x - size.height * 0.5f, size.height)
                            close()
                        }
                        drawPath(path, HrColors.Orange.copy(alpha = if (i % 2 == 0) 0.85f else 0.35f), style = Fill)
                    }
                }
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(
                        Icons.Filled.SportsBasketball,
                        contentDescription = null,
                        tint = HrColors.Orange,
                        modifier = Modifier.size(if (large) 44.dp else 22.dp),
                    )
                    Text("DESCANSO", color = Color.White, fontSize = titleSize, fontWeight = FontWeight.Black)
                }
            }

            SourceKind.Result -> Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Text("RESULTADO", color = HrColors.TextMuted, fontSize = bodySize, fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(if (large) 24.dp else 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    ScoreColumn("LOCAL", match.homePoints, large)
                    Text("|", color = HrColors.TextMuted, fontSize = titleSize)
                    ScoreColumn("VISITA", match.awayPoints, large)
                }
            }
        }
    }
}

@Composable
private fun ScoreColumn(label: String, points: Int, large: Boolean) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(label, color = HrColors.TextPrimary, fontSize = if (large) 12.sp else 8.sp, fontWeight = FontWeight.Bold)
        Text(points.toString(), color = Color.White, fontSize = if (large) 40.sp else 20.sp, fontWeight = FontWeight.Black)
    }
}

/**
 * Tarjeta de fuente seleccionable. Borde naranja = selección de trabajo (en Vista previa);
 * rótulo rojo "AL AIRE" = fuente en la salida. Ninguno depende solo del color: ambos llevan texto.
 */
@Composable
fun SourceTile(
    source: VideoSource,
    match: MatchState,
    selected: Boolean,
    onAir: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val stateText = buildString {
        if (selected) append(", en vista previa")
        if (onAir) append(", al aire")
    }
    Surface(
        modifier = modifier
            .heightIn(min = HrDimens.MinTouch)
            .semantics { contentDescription = source.label + stateText }
            .selectable(selected = selected, role = Role.RadioButton, onClick = onClick),
        shape = RoundedCornerShape(10.dp),
        color = HrColors.Panel,
        border = BorderStroke(if (selected) 3.dp else 1.dp, if (selected) HrColors.Orange else HrColors.Outline),
    ) {
        Column(Modifier.padding(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(
                Modifier.fillMaxWidth().heightIn(min = 24.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(source.label.uppercase(), color = HrColors.TextPrimary, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                when {
                    onAir -> StatusChip("AL AIRE", HrColors.OnAirRed, filled = true)
                    selected -> StatusChip("PREVISTA", HrColors.Orange)
                }
            }
            SourceView(source, match, Modifier.fillMaxWidth().aspectRatio(16f / 9f), large = false)
        }
    }
}
