package com.hrmediasports.studio.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.ui.SourceKind
import com.hrmediasports.studio.ui.StudioUiState
import com.hrmediasports.studio.ui.components.PanelFrame
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

@Composable
private fun SectionColumn(modifier: Modifier, content: @Composable () -> Unit) {
    Column(
        modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(HrDimens.Gap),
        verticalArrangement = Arrangement.spacedBy(HrDimens.Gap),
    ) { content() }
}

@Composable
private fun Line(label: String, value: String, valueColor: androidx.compose.ui.graphics.Color = HrColors.TextPrimary) {
    Row(Modifier.fillMaxWidth().heightIn(min = 32.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = HrColors.TextMuted, fontSize = 14.sp, modifier = Modifier.weight(1f))
        Text(value, color = valueColor, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun Note(text: String) = Text(text, color = HrColors.TextMuted, fontSize = 13.sp)

/** Cámaras: ranuras de las cámaras previstas; ninguna conectada en esta versión. */
@Composable
fun CamerasScreen(state: StudioUiState, modifier: Modifier = Modifier) {
    SectionColumn(modifier) {
        PanelFrame("CÁMARAS", badge = { StatusChip("${state.connectedCameras} CONECTADAS", HrColors.TextMuted) }) {
            state.sources.filter { it.kind == SourceKind.Camera }.forEach {
                Line(it.label, "Sin conectar", HrColors.TextMuted)
            }
            Line(
                "Recepción de cámaras remotas",
                if (state.remoteCamerasAvailable) "Disponible" else "No disponible",
                if (state.remoteCamerasAvailable) HrColors.Turquoise else HrColors.TextMuted,
            )
            Note("La recepción de cámaras remotas (por ejemplo, un Android enviando video por WebRTC) aún no existe. Se decide con las pruebas T1 y T2 de docs/MATRIZ-FUNCIONES.md.")
        }
    }
}

/** Gráficos: los gráficos locales que sí dibuja la app. */
@Composable
fun GraphicsScreen(state: StudioUiState, onPrepare: (String) -> Unit, modifier: Modifier = Modifier) {
    SectionColumn(modifier) {
        PanelFrame("GRÁFICOS") {
            state.sources.filter { it.kind != SourceKind.Camera }.forEach { source ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(source.label, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                        Text("Gráfico local de la app", color = HrColors.TextMuted, fontSize = 12.sp)
                    }
                    OutlinedButton(onClick = { onPrepare(source.id) }, modifier = Modifier.heightIn(min = HrDimens.MinTouch)) {
                        Text("Preparar en Vista previa")
                    }
                }
            }
            Note("El marcador integrado sobre el video de salida aún no existe (prueba T4).")
        }
    }
}

/** Repeticiones: estado real del búfer y de la lista (vacíos). */
@Composable
fun ReplaysScreen(state: StudioUiState, modifier: Modifier = Modifier) {
    SectionColumn(modifier) {
        PanelFrame("REPETICIONES") {
            Line(
                "Búfer de los últimos 20 s",
                if (state.replayBufferAvailable) "Disponible" else "No disponible",
                if (state.replayBufferAvailable) HrColors.Turquoise else HrColors.TextMuted,
            )
            Line("Repeticiones guardadas", state.replays.size.toString())
            Note("El búfer de video no está implementado en esta versión (prueba T7).")
        }
    }
}

/** Ajustes: información de la versión; sin opciones editables todavía. */
@Composable
fun SettingsScreen(state: StudioUiState, modifier: Modifier = Modifier) {
    SectionColumn(modifier) {
        PanelFrame("ACERCA DE") {
            Line("Versión", "0.1.0-demo")
            Line("Modo", if (state.isDemo) "Demostración" else "Operativo", HrColors.Amber)
            Line("Licencia", "Apache-2.0 (código abierto)")
            Note("Alcance multiplataforma, protocolo y matriz de funciones: carpeta docs/ del repositorio.")
        }
        PanelFrame("PREFERENCIAS") {
            Note("Aún no hay preferencias configurables.")
        }
    }
}
