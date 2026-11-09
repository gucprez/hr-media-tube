package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.domain.periodLabel
import com.hrmediasports.studio.ui.CorrectionDraft
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

@Composable
fun ManualCorrectionDialog(
    draft: CorrectionDraft,
    onChange: ((CorrectionDraft) -> CorrectionDraft) -> Unit,
    onApply: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = HrColors.Panel,
        title = { Text("Corrección manual", color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                StepperRow("Local", draft.home.toString(), 0 < draft.home, draft.home < MatchState.MAX_POINTS,
                    { onChange { it.copy(home = it.home - 1) } }, { onChange { it.copy(home = it.home + 1) } })
                StepperRow("Visita", draft.away.toString(), 0 < draft.away, draft.away < MatchState.MAX_POINTS,
                    { onChange { it.copy(away = it.away - 1) } }, { onChange { it.copy(away = it.away + 1) } })
                StepperRow("Periodo", periodLabel(draft.period), 1 < draft.period, draft.period < MatchState.MAX_PERIOD,
                    { onChange { it.copy(period = it.period - 1) } }, { onChange { it.copy(period = it.period + 1) } })
                StepperRow("Posesión (s)", draft.possession.toString(), 0 < draft.possession, draft.possession < MatchState.MAX_POSSESSION_SECONDS,
                    { onChange { it.copy(possession = it.possession - 1) } }, { onChange { it.copy(possession = it.possession + 1) } })
                OutlinedTextField(
                    value = draft.clockText,
                    onValueChange = { text -> onChange { it.copy(clockText = text.take(5)) } },
                    label = { Text("Tiempo (mm:ss)") },
                    singleLine = true,
                    isError = !draft.isValid,
                    supportingText = { if (!draft.isValid) Text("Usa el formato mm:ss, por ejemplo 03:24") },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            TextButton(onClick = onApply, enabled = draft.isValid, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) {
                Text("Aplicar", fontWeight = FontWeight.Bold)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) { Text("Cancelar") }
        },
    )
}

@Composable
private fun StepperRow(
    label: String,
    value: String,
    canDecrease: Boolean,
    canIncrease: Boolean,
    onDecrease: () -> Unit,
    onIncrease: () -> Unit,
) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, color = HrColors.TextMuted, fontSize = 14.sp)
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
