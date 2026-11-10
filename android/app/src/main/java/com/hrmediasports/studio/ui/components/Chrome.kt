package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Layers
import androidx.compose.material.icons.filled.PhotoCamera
import androidx.compose.material.icons.filled.Replay
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Videocam
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.ui.Destination
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Logotipo de HR Media Sports. Único punto de definición: todas las pantallas lo obtienen a
 * través de [HrBrandBar]. Es una marca, no texto de lectura: no crece con el tamaño de fuente
 * del sistema (si lo hiciera, desplazaría los controles de la barra superior).
 */
@Composable
fun HrLogo(modifier: Modifier = Modifier, compact: Boolean = false) {
    val d = LocalDensity.current
    CompositionLocalProvider(LocalDensity provides Density(d.density, fontScale = 1f)) {
        Row(
            modifier = modifier.clearAndSetSemantics { contentDescription = "HR Media Sports" },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = "HR",
                color = HrColors.Orange,
                fontWeight = FontWeight.Black,
                fontStyle = FontStyle.Italic,
                fontSize = if (compact) 24.sp else 32.sp,
                softWrap = false,
            )
            Spacer(Modifier.width(4.dp))
            Text(
                text = "MEDIA SPORTS",
                color = HrColors.TextPrimary,
                fontWeight = FontWeight.ExtraBold,
                fontSize = if (compact) 16.sp else 22.sp,
                letterSpacing = 0.5.sp,
                softWrap = false,
            )
        }
    }
}

/**
 * Etiqueta con color de énfasis. El texto transmite el significado (no solo el color) y nunca
 * se parte en dos líneas: si no cabe, el contenedor (FlowRow) la baja a la línea siguiente.
 */
@Composable
fun StatusChip(
    text: String,
    color: Color,
    modifier: Modifier = Modifier,
    filled: Boolean = false,
    dense: Boolean = false,
) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(50),
        color = if (filled) color else Color.Transparent,
        border = if (filled) null else BorderStroke(1.dp, color),
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(horizontal = if (dense) 8.dp else 10.dp, vertical = if (dense) 2.dp else 4.dp),
            color = if (filled) Color.White else color,
            fontSize = if (dense) 11.sp else 12.sp,
            fontWeight = FontWeight.Bold,
            letterSpacing = 0.5.sp,
            maxLines = 1,
            softWrap = false,
        )
    }
}

@Composable
fun StatusDot(color: Color, modifier: Modifier = Modifier) {
    Box(modifier.size(10.dp).clip(CircleShape).background(color))
}

@OptIn(ExperimentalLayoutApi::class)
/**
 * Marco de panel; el color del borde comunica rol (naranja = preparado, rojo = al aire).
 * El contenido recibe un [ColumnScope] para poder repartir el alto con `weight`.
 */
@Composable
fun PanelFrame(
    title: String,
    modifier: Modifier = Modifier,
    borderColor: Color = HrColors.Outline,
    borderWidth: Dp = 1.dp,
    badge: @Composable (() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(HrDimens.PanelRadius),
        color = HrColors.Panel,
        border = BorderStroke(borderWidth, borderColor),
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            FlowRow(
                Modifier.fillMaxWidth().heightIn(min = 32.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.Start),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(
                    title,
                    color = HrColors.TextPrimary,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 16.sp,
                    letterSpacing = 0.5.sp,
                    modifier = Modifier.align(Alignment.CenterVertically),
                )
                badge?.invoke()
            }
            content()
        }
    }
}

/** Navegación lateral. Su ancho se adapta al texto (escala de fuente) y puede desplazarse si no cabe. */
@Composable
fun NavRail(
    selected: Destination,
    onSelect: (Destination) -> Unit,
    compact: Boolean,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxHeight()
            .widthIn(min = if (compact) 72.dp else 88.dp)
            .width(IntrinsicSize.Max)
            .background(HrColors.Panel)
            .verticalScroll(rememberScrollState())
            .padding(vertical = 6.dp, horizontal = 6.dp),
        verticalArrangement = Arrangement.spacedBy(if (compact) 2.dp else 6.dp),
    ) {
        Destination.entries.forEach { dest ->
            val isSelected = dest == selected
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(min = if (compact) HrDimens.MinTouch else 64.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(if (isSelected) HrColors.Orange else Color.Transparent)
                    .selectable(selected = isSelected, role = Role.Tab, onClick = { onSelect(dest) })
                    .padding(horizontal = 4.dp, vertical = 2.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Icon(
                    imageVector = dest.icon(),
                    contentDescription = null,
                    tint = if (isSelected) Color.Black else HrColors.TextPrimary,
                    modifier = Modifier.size(if (compact) 22.dp else 26.dp),
                )
                Text(
                    dest.title,
                    color = if (isSelected) Color.Black else HrColors.TextPrimary,
                    fontSize = if (compact) 10.sp else 11.sp,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
                    softWrap = false,
                )
            }
        }
    }
}

private fun Destination.icon(): ImageVector = when (this) {
    Destination.Studio -> Icons.Filled.Videocam
    Destination.Cameras -> Icons.Filled.PhotoCamera
    Destination.Graphics -> Icons.Filled.Layers
    Destination.Replays -> Icons.Filled.Replay
    Destination.Settings -> Icons.Filled.Settings
}

/** Franja permanente que identifica el modo demostración. Se ajusta a varias líneas si hace falta. */
@Composable
fun DemoBanner(
    modifier: Modifier = Modifier,
    text: String = "Interfaz de muestra: sin cámaras, transmisión, grabación ni repeticiones reales.",
) {
    Text(
        text = buildAnnotatedString {
            withStyle(SpanStyle(fontWeight = FontWeight.Black)) { append("MODO DEMOSTRACIÓN  ") }
            append(text)
        },
        color = Color.Black,
        fontSize = 12.sp,
        modifier = modifier
            .fillMaxWidth()
            .background(HrColors.Amber)
            .padding(horizontal = 16.dp, vertical = 4.dp),
    )
}

/** Barra inferior de estado de conexiones. Turquesa solo cuando hay conexión real. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ConnectionStatusBar(
    connectedCameras: Int,
    scoreboardConnected: Boolean,
    versionLabel: String,
    modifier: Modifier = Modifier,
) {
    FlowRow(
        modifier = modifier
            .fillMaxWidth()
            .background(HrColors.Panel)
            .heightIn(min = 32.dp)
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(16.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        StatusItem(
            if (connectedCameras == 1) "1 cámara conectada" else "$connectedCameras cámaras conectadas",
            if (connectedCameras > 0) HrColors.Turquoise else HrColors.TextMuted,
        )
        StatusItem(
            if (scoreboardConnected) "Lector conectado" else "Lector sin conexión",
            if (scoreboardConnected) HrColors.Turquoise else HrColors.TextMuted,
        )
        Text(versionLabel, color = HrColors.TextMuted, fontSize = 12.sp)
    }
}

@Composable
private fun StatusItem(text: String, dotColor: Color) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        StatusDot(dotColor)
        Text(text, color = HrColors.TextPrimary, fontSize = 13.sp)
    }
}

/**
 * Barra superior de marca, común a Estudio, Lector y selector de modo: el logotipo se dibuja
 * solo aquí, por lo que no puede diferir entre pantallas.
 */
@Composable
fun HrBrandBar(
    title: String?,
    compact: Boolean,
    modifier: Modifier = Modifier,
    trailing: @Composable RowScope.() -> Unit = {},
) {
    Row(
        modifier
            .fillMaxWidth()
            .background(HrColors.Panel)
            .heightIn(min = 52.dp)
            .padding(horizontal = 16.dp, vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        HrLogo(compact = compact)
        if (title != null) {
            Text("|", color = HrColors.Outline, fontSize = if (compact) 22.sp else 28.sp)
            Text(
                title.uppercase(),
                color = HrColors.TextPrimary,
                fontSize = if (compact) 16.sp else 20.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 2,
            )
        }
        Spacer(Modifier.weight(1f))
        trailing()
    }
}
