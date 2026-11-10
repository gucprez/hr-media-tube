package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Leaderboard
import androidx.compose.material.icons.filled.SportsBasketball
import androidx.compose.material.icons.filled.Videocam
import androidx.compose.material.icons.filled.VideocamOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Fill
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.ui.SourceKind
import com.hrmediasports.studio.ui.VideoSource
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Cabe un área 16:9 dentro del espacio REAL disponible (por ancho y por alto), sin forzar
 * proporciones que desborden. Es lo que mantiene los monitores visibles en ventanas bajas.
 */
@Composable
fun FitVideo(
    modifier: Modifier = Modifier,
    border: BorderStroke? = null,
    content: @Composable () -> Unit,
) {
    BoxWithConstraints(modifier, contentAlignment = Alignment.Center) {
        val byHeight: Dp = if (constraints.hasBoundedHeight) maxHeight * (16f / 9f) else maxWidth
        val w = minOf(maxWidth, byHeight)
        Box(
            Modifier
                .size(w, w * (9f / 16f))
                .clip(RoundedCornerShape(8.dp))
                .then(if (border != null) Modifier.border(border, RoundedCornerShape(8.dp)) else Modifier),
        ) { content() }
    }
}

/**
 * Contenido visual de una fuente. Las cámaras NO tienen video en esta versión: se muestra un
 * marcador rotulado. "Descanso" y "Resultado" son gráficos locales reales (dibujados por la app).
 * El texto de estos gráficos es parte de la imagen: no sigue la escala de fuente del sistema.
 */
@Composable
fun SourceView(
    source: VideoSource,
    match: MatchState?,
    modifier: Modifier = Modifier,
    dataNote: String? = null,
) {
    val d = LocalDensity.current
    CompositionLocalProvider(LocalDensity provides Density(d.density, fontScale = 1f)) {
        BoxWithConstraints(
            modifier = modifier
                .background(Brush.verticalGradient(listOf(Color(0xFF1B2638), Color(0xFF0E1522)))),
            contentAlignment = Alignment.Center,
        ) {
            // Tamaños relativos al ancho real del monitor.
            val k = (maxWidth.value / 320f).coerceIn(0.45f, 1.4f)
            val title = (22f * k).sp
            val body = (13f * k).coerceAtLeast(9f).sp
            val icon = (40f * k).dp
            when (source.kind) {
                SourceKind.Camera -> Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Icon(Icons.Filled.VideocamOff, null, tint = HrColors.TextMuted, modifier = Modifier.size(icon))
                    Text(source.label.uppercase(), color = HrColors.TextPrimary, fontSize = title, fontWeight = FontWeight.ExtraBold)
                    Text("Sin video · fuente de ejemplo", color = HrColors.TextMuted, fontSize = body, textAlign = TextAlign.Center)
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
                        Icon(Icons.Filled.SportsBasketball, null, tint = HrColors.Orange, modifier = Modifier.size(icon * 1.1f))
                        Text("DESCANSO", color = Color.White, fontSize = title, fontWeight = FontWeight.Black)
                    }
                }

                SourceKind.Result -> Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Text("RESULTADO", color = HrColors.TextMuted, fontSize = body, fontWeight = FontWeight.Bold)
                    Row(horizontalArrangement = Arrangement.spacedBy((24f * k).dp), verticalAlignment = Alignment.CenterVertically) {
                        ScoreColumn("LOCAL", match?.homePoints?.toString() ?: "—", k)
                        Text("|", color = HrColors.TextMuted, fontSize = title)
                        ScoreColumn("VISITA", match?.awayPoints?.toString() ?: "—", k)
                    }
                    if (dataNote != null) {
                        Text(dataNote, color = HrColors.Amber, fontSize = body, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun ScoreColumn(label: String, value: String, k: Float) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(label, color = HrColors.TextPrimary, fontSize = (12f * k).coerceAtLeast(8f).sp, fontWeight = FontWeight.Bold)
        Text(value, color = Color.White, fontSize = (40f * k).sp, fontWeight = FontWeight.Black)
    }
}

/** Monitor sin marco de panel (ventanas bajas): el borde del propio video indica su rol. */
@Composable
fun MonitorBox(
    source: VideoSource?,
    match: MatchState?,
    dataNote: String?,
    borderColor: Color,
    emptyText: String,
    modifier: Modifier = Modifier,
) {
    FitVideo(modifier, border = BorderStroke(if (source != null) 3.dp else 1.dp, if (source != null) borderColor else HrColors.Outline)) {
        if (source != null) {
            SourceView(source, match, Modifier.fillMaxSize(), dataNote)
        } else {
            EmptyVideo(emptyText)
        }
    }
}

@Composable
fun EmptyVideo(text: String, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize().background(HrColors.PanelRaised), contentAlignment = Alignment.Center) {
        Text(text, color = HrColors.TextMuted, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(8.dp))
    }
}

/**
 * Tarjeta de fuente compacta y seleccionable.
 *  - PREVIA (naranja): fuente preparada en Vista previa.
 *  - AL AIRE (rojo): fuente en la salida. Si es la misma, se ven ambas etiquetas y un borde
 *    rojo con un filete naranja interior. Ninguna de las dos señales depende solo del color.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun SourceTile(
    source: VideoSource,
    selected: Boolean,
    onAir: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    dense: Boolean = false,
) {
    val outer = when {
        onAir -> HrColors.OnAirRed
        selected -> HrColors.Orange
        else -> HrColors.Outline
    }
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
        border = BorderStroke(if (selected || onAir) 3.dp else 1.dp, outer),
    ) {
        Box(
            if (selected && onAir) {
                Modifier.padding(2.dp).border(2.dp, HrColors.Orange, RoundedCornerShape(7.dp))
            } else {
                Modifier
            },
        ) {
            Column(
                Modifier.padding(horizontal = 8.dp, vertical = if (dense) 4.dp else 6.dp),
                verticalArrangement = Arrangement.spacedBy(if (dense) 2.dp else 4.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (!dense) {
                        Icon(
                            when (source.kind) {
                                SourceKind.Camera -> Icons.Filled.Videocam
                                SourceKind.Break -> Icons.Filled.SportsBasketball
                                SourceKind.Result -> Icons.Filled.Leaderboard
                            },
                            contentDescription = null,
                            tint = HrColors.TextMuted,
                            modifier = Modifier.size(20.dp),
                        )
                    }
                    Text(
                        source.label.uppercase(),
                        color = HrColors.TextPrimary,
                        fontSize = if (dense) 13.sp else 14.sp,
                        fontWeight = FontWeight.ExtraBold,
                        maxLines = 1,
                        softWrap = false,
                    )
                }
                if (selected || onAir) {
                    FlowRow(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(4.dp),
                        verticalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        if (selected) StatusChip("PREVIA", HrColors.Orange, dense = true)
                        if (onAir) StatusChip("AL AIRE", HrColors.OnAirRed, filled = true, dense = true)
                    }
                }
            }
        }
    }
}
