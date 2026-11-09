package com.hrmediasports.studio.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

object HrColors {
    val Background = Color(0xFF0B1220)
    val Panel = Color(0xFF141D2E)
    val PanelRaised = Color(0xFF1B2638)
    val Outline = Color(0xFF2D3A50)
    val Orange = Color(0xFFF26A0D)      // selección de trabajo, acciones
    val OnAirRed = Color(0xFFE5322D)    // exclusivamente lo que está al aire
    val Turquoise = Color(0xFF1DE9B6)   // estados de conexión activos
    val Amber = Color(0xFFFFC043)       // advertencia / datos de demostración
    val TextPrimary = Color(0xFFFFFFFF)
    val TextMuted = Color(0xFFA3B1C6)
}

object HrDimens {
    /** Tamaño mínimo de cualquier control táctil. */
    val MinTouch = 48.dp
    val PanelRadius = 12.dp
    val Gap = 12.dp
    val ExpandedBreakpoint = 840.dp
}

private val scheme = darkColorScheme(
    primary = HrColors.Orange,
    onPrimary = Color.Black,
    background = HrColors.Background,
    onBackground = HrColors.TextPrimary,
    surface = HrColors.Panel,
    onSurface = HrColors.TextPrimary,
    surfaceVariant = HrColors.PanelRaised,
    onSurfaceVariant = HrColors.TextMuted,
    outline = HrColors.Outline,
    error = HrColors.OnAirRed,
)

@Composable
fun HrTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = scheme, content = content)
}
