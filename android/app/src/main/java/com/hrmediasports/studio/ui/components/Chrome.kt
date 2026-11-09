package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.selection.selectable
import com.hrmediasports.studio.ui.Destination
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Logotipo de HR Media Sports. Único punto de definición: todas las pantallas lo
 * obtienen a través de [HrTopBar], así que no puede diferir entre ellas.
 */
@Composable
fun HrLogo(modifier: Modifier = Modifier, compact: Boolean = false) {
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
        )
        Spacer(Modifier.width(4.dp))
        Text(
            text = "MEDIA SPORTS",
            color = HrColors.TextPrimary,
            fontWeight = FontWeight.ExtraBold,
            fontSize = if (compact) 16.sp else 22.sp,
            letterSpacing = 0.5.sp,
        )
    }
}

/** Etiqueta pequeña con color de énfasis; el texto transmite el significado, no solo el color. */
@Composable
fun StatusChip(text: String, color: Color, modifier: Modifier = Modifier, filled: Boolean = false) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(50),
        color = if (filled) color else Color.Transparent,
        border = if (filled) null else BorderStroke(1.dp, color),
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
            color = if (filled) Color.White else color,
            fontSize = 12.sp,
            fontWeight = FontWeight.Bold,
            letterSpacing = 0.5.sp,
        )
    }
}

@Composable
fun StatusDot(color: Color, modifier: Modifier = Modifier) {
    Box(modifier.size(10.dp).clip(CircleShape).background(color))
}

/** Marco de panel; el color del borde comunica rol (naranja = selección, rojo = al aire). */
@Composable
fun PanelFrame(
    title: String,
    modifier: Modifier = Modifier,
    borderColor: Color = HrColors.Outline,
    borderWidth: Dp = 1.dp,
    badge: @Composable (() -> Unit)? = null,
    content: @Composable () -> Unit,
) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(HrDimens.PanelRadius),
        color = HrColors.Panel,
        border = BorderStroke(borderWidth, borderColor),
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(
                Modifier.fillMaxWidth().heightIn(min = 32.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    title,
                    color = HrColors.TextPrimary,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 16.sp,
                    letterSpacing = 0.5.sp,
                )
                badge?.invoke()
            }
            content()
        }
    }
}

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
            .width(if (compact) 76.dp else 92.dp)
            .background(HrColors.Panel)
            .padding(vertical = 8.dp, horizontal = 6.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Destination.entries.forEach { dest ->
            val isSelected = dest == selected
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(min = 64.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(if (isSelected) HrColors.Orange else Color.Transparent)
                    .selectable(selected = isSelected, role = Role.Tab, onClick = { onSelect(dest) })
                    .padding(4.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Icon(
                    imageVector = dest.icon(),
                    contentDescription = null,
                    tint = if (isSelected) Color.Black else HrColors.TextPrimary,
                    modifier = Modifier.size(26.dp),
                )
                Text(
                    dest.title,
                    color = if (isSelected) Color.Black else HrColors.TextPrimary,
                    fontSize = 11.sp,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
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

/** Franja permanente que identifica el modo demostración en todas las pantallas. */
@Composable
fun DemoBanner(modifier: Modifier = Modifier) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .background(HrColors.Amber)
            .padding(horizontal = 16.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text("MODO DEMOSTRACIÓN", color = Color.Black, fontWeight = FontWeight.Black, fontSize = 12.sp)
        Text(
            "Interfaz de muestra: no hay cámaras, transmisión, grabación ni repeticiones reales.",
            color = Color.Black,
            fontSize = 12.sp,
            maxLines = 2,
        )
    }
}

/** Barra inferior de estado de conexiones. Turquesa solo cuando hay conexión real. */
@Composable
fun ConnectionStatusBar(
    connectedCameras: Int,
    scoreboardConnected: Boolean,
    versionLabel: String,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .background(HrColors.Panel)
            .heightIn(min = 36.dp)
            .padding(horizontal = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        StatusDot(if (connectedCameras > 0) HrColors.Turquoise else HrColors.TextMuted)
        Text(
            if (connectedCameras == 1) "1 cámara conectada" else "$connectedCameras cámaras conectadas",
            color = HrColors.TextPrimary,
            fontSize = 13.sp,
        )
        StatusDot(if (scoreboardConnected) HrColors.Turquoise else HrColors.TextMuted)
        Text(
            if (scoreboardConnected) "Lector conectado" else "Lector sin conexión",
            color = HrColors.TextPrimary,
            fontSize = 13.sp,
        )
        Spacer(Modifier.weight(1f))
        Text(versionLabel, color = HrColors.TextMuted, fontSize = 12.sp)
    }
}
