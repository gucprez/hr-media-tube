package com.hrmediasports.studio.ui.components

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.hrmediasports.studio.ui.RecordingState
import com.hrmediasports.studio.ui.StreamingState
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrTheme
import androidx.compose.foundation.background

/** Todos los estados de grabación/transmisión, incluidos los que la demo no alcanza. */
@Preview(widthDp = 700, heightDp = 340, showBackground = true, backgroundColor = 0xFF0B1220)
@Composable
private fun OutputStatesPreview() {
    HrTheme {
        Column(Modifier.background(HrColors.Background).padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                RecordingState.entries.forEach { RecordButton(it, {}) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StreamingState.entries.forEach { StreamButton(it, {}) }
            }
        }
    }
}
