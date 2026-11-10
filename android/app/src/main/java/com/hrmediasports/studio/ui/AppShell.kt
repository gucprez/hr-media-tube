package com.hrmediasports.studio.ui

import android.app.Activity
import android.content.pm.ActivityInfo
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.ui.components.DemoBanner
import com.hrmediasports.studio.ui.components.HrBrandBar
import com.hrmediasports.studio.ui.reader.ReaderApp
import com.hrmediasports.studio.ui.theme.HrColors
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Qué hace este dispositivo: estudio (horizontal) o lector de pizarra (celular vertical). */
enum class AppRole { Studio, Reader }

class AppViewModel : ViewModel() {
    private val _role = MutableStateFlow<AppRole?>(null)
    val role: StateFlow<AppRole?> = _role.asStateFlow()

    fun select(role: AppRole) { _role.value = role }
    fun backToSelector() { _role.value = null }
}

@Composable
fun HrApp(appViewModel: AppViewModel = viewModel()) {
    val role by appViewModel.role.collectAsStateWithLifecycle()

    val activity = LocalContext.current as? Activity
    DisposableEffect(role) {
        activity?.requestedOrientation = when (role) {
            AppRole.Studio -> ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
            AppRole.Reader -> ActivityInfo.SCREEN_ORIENTATION_SENSOR_PORTRAIT
            null -> ActivityInfo.SCREEN_ORIENTATION_FULL_SENSOR
        }
        onDispose {}
    }
    BackHandler(enabled = role != null) { appViewModel.backToSelector() }

    when (role) {
        null -> RoleScreen(onSelect = appViewModel::select)
        AppRole.Studio -> StudioApp()
        AppRole.Reader -> ReaderApp()
    }
}

@Composable
fun RoleScreen(onSelect: (AppRole) -> Unit) {
    Column(
        Modifier.fillMaxSize().background(HrColors.Background).windowInsetsPadding(WindowInsets.safeDrawing),
    ) {
        HrBrandBar(title = null, compact = true)
        DemoBanner(text = "Versión de demostración de la interfaz.")
        Column(
            Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
        ) {
            Text("¿Qué hará este dispositivo?", color = HrColors.TextPrimary, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            RoleButton("ESTUDIO", "Pantalla horizontal · tablet o celular", { onSelect(AppRole.Studio) })
            RoleButton("LECTOR DE PIZARRA", "Pantalla vertical · celular frente al marcador", { onSelect(AppRole.Reader) })
            Text(
                "Con el botón Atrás de Android vuelves a esta pantalla.",
                color = HrColors.TextMuted,
                fontSize = 13.sp,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun RoleButton(title: String, subtitle: String, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        modifier = Modifier.widthIn(max = 480.dp).fillMaxWidth().heightIn(min = 72.dp),
        colors = ButtonDefaults.buttonColors(containerColor = HrColors.Orange, contentColor = Color.Black),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(title, fontSize = 20.sp, fontWeight = FontWeight.Black)
            Text(subtitle, fontSize = 13.sp)
        }
    }
}
