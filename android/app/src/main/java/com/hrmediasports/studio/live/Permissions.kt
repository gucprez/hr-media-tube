package com.hrmediasports.studio.live

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.hrmediasports.studio.ui.theme.HrColors

/** Pide los permisos [required] (y notificaciones, opcional) antes de mostrar [content]. */
@Composable
fun WithPermissions(required: List<String>, why: String, onExit: () -> Unit, content: @Composable () -> Unit) {
    val context = LocalContext.current
    fun granted() = required.all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }
    val ask = remember(required) { (required + if (Build.VERSION.SDK_INT >= 33) listOf(Manifest.permission.POST_NOTIFICATIONS) else emptyList()).toTypedArray() }
    var ok by remember { mutableStateOf(granted()) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { ok = granted() }
    LaunchedEffect(Unit) { if (!ok) launcher.launch(ask) }
    if (ok) { content(); return }
    Column(Modifier.fillMaxSize().background(HrColors.Background).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(why, color = HrColors.TextPrimary, fontSize = 18.sp, textAlign = TextAlign.Center)
        Button(onClick = { launcher.launch(ask) }, modifier = Modifier.heightIn(min = 56.dp)) { Text("DAR PERMISOS") }
        TextButton(onClick = {
            context.startActivity(Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
        }) { Text("Abrir ajustes de la app") }
        TextButton(onClick = onExit) { Text("Volver") }
    }
}

val CAMERA_AND_MIC = listOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO)
val CAMERA_ONLY = listOf(Manifest.permission.CAMERA)
