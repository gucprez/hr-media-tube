package com.hrmediasports.studio.ui.content

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.SkipNext
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.content.formatDuration
import com.hrmediasports.studio.ui.StudioUiState
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Control del descanso que está AL AIRE: qué se muestra, cuánto queda, qué sigue, y el botón
 * destacado «Volver al partido». Solo aparece mientras hay un descanso en la salida.
 */
@Composable
fun BreakBar(state: StudioUiState, actions: ContentActions, modifier: Modifier = Modifier) {
    val b = state.breakState ?: return
    val back = state.sources.firstOrNull { it.id == state.returnSourceId }?.label ?: "cámara"
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(HrDimens.PanelRadius),
        color = HrColors.Panel,
        border = BorderStroke(2.dp, HrColors.OnAirRed),
    ) {
        // Una sola fila de controles: los tres botones y «Volver al partido» siempre quedan visibles juntos.
        Column(Modifier.padding(horizontal = 10.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            val remaining = b.remainingMs?.let { "quedan ${formatDuration(it)}" } ?: "sin límite"
            if (LocalDensity.current.fontScale > 1.3f) {
                // Letra muy grande: una sola línea corta, para no quitarle alto al monitor Al aire (que ya muestra el nombre).
                Text((if (b.paused) "EN PAUSA · " else "") + remaining.replaceFirstChar { it.uppercase() }, color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
            } else {
                Text(
                    "AL AIRE: ${b.showing.title} · $remaining" + if (b.paused) " · EN PAUSA" else "",
                    color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
                )
                Text("Sigue: ${state.breakNextTitle} · vuelve a $back", color = HrColors.TextMuted, fontSize = 12.sp)
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                IconButton(onClick = actions.onPause, modifier = Modifier.size(HrDimens.MinTouch)) {
                    Icon(if (b.paused) Icons.Filled.PlayArrow else Icons.Filled.Pause, contentDescription = if (b.paused) "Reproducir" else "Pausar", tint = HrColors.TextPrimary)
                }
                IconButton(onClick = actions.onNext, modifier = Modifier.size(HrDimens.MinTouch)) {
                    Icon(Icons.Filled.SkipNext, contentDescription = "Siguiente elemento", tint = HrColors.TextPrimary)
                }
                IconButton(onClick = actions.onStop, modifier = Modifier.size(HrDimens.MinTouch)) {
                    Icon(Icons.Filled.Stop, contentDescription = "Detener (deja la placa de espera)", tint = HrColors.TextPrimary)
                }
                Button(
                    onClick = actions.onBackToMatch,
                    modifier = Modifier.heightIn(min = 52.dp).weight(1f),
                    colors = ButtonDefaults.buttonColors(containerColor = HrColors.Orange, contentColor = Color.Black),
                    contentPadding = PaddingValues(horizontal = 10.dp, vertical = 6.dp),
                ) {
                    Text("VOLVER AL PARTIDO", fontWeight = FontWeight.Black, textAlign = TextAlign.Center)
                }
            }
        }
    }
}
