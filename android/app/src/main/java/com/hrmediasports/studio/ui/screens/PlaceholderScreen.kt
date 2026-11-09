package com.hrmediasports.studio.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.ui.Destination
import com.hrmediasports.studio.ui.theme.HrColors

/** Secciones de navegación aún sin implementar: lo dicen explícitamente. */
@Composable
fun PlaceholderScreen(destination: Destination, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(destination.title.uppercase(), color = HrColors.TextPrimary, fontSize = 24.sp, fontWeight = FontWeight.Black)
            Text(
                "Sección no implementada todavía. No forma parte de esta entrega.",
                color = HrColors.TextMuted,
                textAlign = TextAlign.Center,
            )
        }
    }
}
