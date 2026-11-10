package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.FiberManualRecord
import androidx.compose.material.icons.filled.Sensors
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.ui.RecordingState
import com.hrmediasports.studio.ui.StreamingState
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

private data class ButtonLook(
    val label: String,
    val icon: ImageVector,
    val content: Color,
    val container: Color,
    val border: Color,
    val stateText: String,
)

@Composable
private fun OutputButton(look: ButtonLook, onClick: () -> Unit, modifier: Modifier = Modifier, iconOnly: Boolean = false) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier
            .heightIn(min = HrDimens.MinTouch)
            .widthIn(min = HrDimens.MinTouch)
            .semantics {
                stateDescription = look.stateText
                if (iconOnly) contentDescription = look.label
            },
        contentPadding = if (iconOnly) PaddingValues(0.dp) else ButtonDefaults.ContentPadding,
        colors = ButtonDefaults.outlinedButtonColors(containerColor = look.container, contentColor = look.content),
        border = BorderStroke(2.dp, look.border),
    ) {
        Icon(look.icon, contentDescription = null, modifier = Modifier.size(18.dp))
        if (!iconOnly) {
            Text(look.label, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, modifier = Modifier.padding(start = 6.dp), maxLines = 1, softWrap = false)
        }
    }
}

/**
 * Cada estado se distingue por texto + icono + estilo (no solo por color):
 * no disponible (apagado), detenido (contorno blanco), grabando (relleno oscuro con "DETENER"),
 * error (ámbar).
 */
@Composable
fun RecordButton(state: RecordingState, onClick: () -> Unit, modifier: Modifier = Modifier, iconOnly: Boolean = false) {
    val look = when (state) {
        RecordingState.Unavailable -> ButtonLook("GRABAR", Icons.Filled.FiberManualRecord, HrColors.TextMuted, Color.Transparent, HrColors.Outline, "No disponible")
        RecordingState.Stopped -> ButtonLook("GRABAR", Icons.Filled.FiberManualRecord, HrColors.TextPrimary, Color.Transparent, HrColors.TextPrimary, "Detenida")
        RecordingState.Recording -> ButtonLook("GRABANDO", Icons.Filled.Stop, Color.White, Color(0xFF3A1416), HrColors.OnAirRed, "Grabando. Toca para detener")
        RecordingState.Error -> ButtonLook("ERROR GRAB.", Icons.Filled.FiberManualRecord, HrColors.Amber, Color.Transparent, HrColors.Amber, "Error")
    }
    OutputButton(look, onClick, modifier, iconOnly)
}

@Composable
fun StreamButton(state: StreamingState, onClick: () -> Unit, modifier: Modifier = Modifier, iconOnly: Boolean = false) {
    val look = when (state) {
        StreamingState.Unavailable -> ButtonLook("TRANSMITIR", Icons.Filled.Sensors, HrColors.TextMuted, Color.Transparent, HrColors.Outline, "No disponible")
        StreamingState.Offline -> ButtonLook("TRANSMITIR", Icons.Filled.Sensors, Color.Black, HrColors.Orange, HrColors.Orange, "Sin transmitir")
        StreamingState.Connecting -> ButtonLook("CONECTANDO…", Icons.Filled.Sensors, HrColors.Orange, Color.Transparent, HrColors.Orange, "Conectando")
        StreamingState.Live -> ButtonLook("EN VIVO", Icons.Filled.Stop, Color.White, HrColors.OnAirRed, HrColors.OnAirRed, "Transmitiendo. Toca para detener")
        StreamingState.Error -> ButtonLook("ERROR TRANS.", Icons.Filled.Sensors, HrColors.Amber, Color.Transparent, HrColors.Amber, "Error")
    }
    OutputButton(look, onClick, modifier, iconOnly)
}

@Composable
fun OutputControls(
    recording: RecordingState,
    streaming: StreamingState,
    onRecord: () -> Unit,
    onStream: () -> Unit,
    iconOnly: Boolean = false,
) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        RecordButton(recording, onRecord, iconOnly = iconOnly)
        StreamButton(streaming, onStream, iconOnly = iconOnly)
    }
}
