package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.periodLabel
import com.hrmediasports.studio.ui.CorrectionDraft
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

/**
 * Corrección manual del marcador. Ventana propia: título y botones siempre visibles; los campos van
 * en filas flexibles (2 por fila si caben) y, si no caben todos, se desplazan solo ellos.
 * El campo de tiempo está en la primera fila para que nunca quede fuera de la vista.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ManualCorrectionDialog(
    draft: CorrectionDraft,
    onChange: ((CorrectionDraft) -> CorrectionDraft) -> Unit,
    onApply: () -> Unit,
    onDismiss: () -> Unit,
) {
    val screenHeight = LocalConfiguration.current.screenHeightDp.dp
    val fs = LocalDensity.current.fontScale
    val cell = Modifier.widthIn(min = 250.dp * fs.coerceAtLeast(1f))
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(
            Modifier.fillMaxWidth(0.94f).widthIn(max = 720.dp).heightIn(max = screenHeight * 0.94f),
            shape = RoundedCornerShape(HrDimens.PanelRadius),
            color = HrColors.Panel,
            border = BorderStroke(1.dp, HrColors.Outline),
        ) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Corrección manual", color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                val scroll = rememberScrollState()
                ScrollFade(scroll, HrColors.Panel, Modifier.weight(1f, fill = false).fillMaxWidth()) {
                FlowRow(
                    Modifier.fillMaxWidth().verticalScroll(scroll),
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                    verticalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    OutlinedTextField(
                        value = draft.clockText,
                        onValueChange = { text -> onChange { it.copy(clockText = text.take(5)) } },
                        label = { Text("Tiempo (mm:ss)") },
                        singleLine = true,
                        isError = !draft.isValid,
                        supportingText = { if (!draft.isValid) Text("Usa el formato mm:ss, por ejemplo 03:24") },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    StepperRow("Local", draft.home.toString(), 0 < draft.home, draft.home < MatchState.MAX_POINTS,
                        { onChange { it.copy(home = it.home - 1) } }, { onChange { it.copy(home = it.home + 1) } }, cell.weight(1f))
                    StepperRow("Visita", draft.away.toString(), 0 < draft.away, draft.away < MatchState.MAX_POINTS,
                        { onChange { it.copy(away = it.away - 1) } }, { onChange { it.copy(away = it.away + 1) } }, cell.weight(1f))
                    StepperRow("Periodo", periodLabel(draft.period), 1 < draft.period, draft.period < MatchState.MAX_PERIOD,
                        { onChange { it.copy(period = it.period - 1) } }, { onChange { it.copy(period = it.period + 1) } }, cell.weight(1f))
                    StepperRow("Posesión (s)", draft.possession.toString(), 0 < draft.possession, draft.possession < MatchState.MAX_POSSESSION_SECONDS,
                        { onChange { it.copy(possession = it.possession - 1) } }, { onChange { it.copy(possession = it.possession + 1) } }, cell.weight(1f))
                }
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp, Alignment.End)) {
                    OutlinedButton(
                        onClick = onDismiss,
                        modifier = Modifier.heightIn(min = HrDimens.MinTouch),
                        border = BorderStroke(1.dp, HrColors.Outline),
                    ) { Text("Cancelar", color = HrColors.TextPrimary) }
                    Button(
                        onClick = onApply,
                        enabled = draft.isValid,
                        modifier = Modifier.heightIn(min = HrDimens.MinTouch),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = HrColors.Orange, contentColor = Color.Black,
                            disabledContainerColor = HrColors.PanelRaised, disabledContentColor = HrColors.TextMuted,
                        ),
                    ) { Text("Aplicar", fontWeight = FontWeight.Bold) }
                }
            }
        }
    }
}

@Composable
private fun StepperRow(
    label: String,
    value: String,
    canDecrease: Boolean,
    canIncrease: Boolean,
    onDecrease: () -> Unit,
    onIncrease: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(modifier.heightIn(min = HrDimens.MinTouch), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, color = HrColors.TextMuted, fontSize = 14.sp, modifier = Modifier.padding(end = 8.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onDecrease, enabled = canDecrease, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) {
                Icon(Icons.Filled.Remove, contentDescription = "Restar a $label")
            }
            Text(value, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold, fontSize = 16.sp)
            IconButton(onClick = onIncrease, enabled = canIncrease, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) {
                Icon(Icons.Filled.Add, contentDescription = "Sumar a $label")
            }
        }
    }
}
