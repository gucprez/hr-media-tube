package com.hrmediasports.studio.link

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import android.view.SurfaceHolder
import android.view.SurfaceView
import com.hrmediasports.studio.live.CAMERA_ONLY
import com.hrmediasports.studio.live.KeepScreenOn
import com.hrmediasports.studio.live.Thermal
import com.hrmediasports.studio.live.WithPermissions
import com.hrmediasports.studio.ui.theme.HrColors
import kotlinx.coroutines.flow.MutableStateFlow

/** Modo CÁMARA: este teléfono solo filma y manda la imagen al Estudio. */
@Composable
fun CameraRoleApp(onExit: () -> Unit) {
    WithPermissions(CAMERA_ONLY, "El modo Cámara usa la cámara de este teléfono para mandar video al Estudio.", onExit) {
        val context = LocalContext.current
        val store = remember { PairingStore(context, LanRole.Camera) }
        var pairing by remember { mutableStateOf(store.load()) }
        val p = pairing
        if (p == null) { PairScreen("Cámara: conectar con el Estudio", onPaired = { store.save(it); pairing = it }, onExit = onExit); return@WithPermissions }
        CameraSender.init(context)
        KeepScreenOn()
        CameraRoleScreen(p, store, onUnpair = { CameraSender.stop(); store.save(null); pairing = null }, onExit = onExit)
    }
}

@Composable
private fun CameraRoleScreen(pairing: PairingCode, store: PairingStore, onUnpair: () -> Unit, onExit: () -> Unit) {
    val st by CameraSender.status.collectAsStateWithLifecycle()
    var saver by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (!st.prepared) CameraSender.prepare() }
    LaunchedEffect(st.prepared, pairing) { if (st.prepared && !st.sending) CameraSender.start(pairing, store.deviceId, store.deviceName) }
    val link by (CameraSender.linkStatus ?: remember { MutableStateFlow<ClientStatus>(ClientStatus.Idle) }).collectAsState()
    val exit = { CameraSender.stop(); CameraSender.releaseIfIdle(); onExit() }
    BackHandler { exit() }
    Row(Modifier.fillMaxSize().background(Color.Black).windowInsetsPadding(WindowInsets.safeDrawing)) {
        Box(Modifier.weight(1f).fillMaxHeight(), contentAlignment = Alignment.Center) {
            if (saver) Text("Modo ahorro: la cámara sigue enviando.\nToca para ver la imagen.", color = HrColors.TextMuted, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxSize().clickable { saver = false }.padding(24.dp))
            else if (st.prepared) androidx.compose.runtime.key(st.generation) {
                AndroidView({ ctx -> SurfaceView(ctx).apply {
                    holder.addCallback(object : SurfaceHolder.Callback {
                        override fun surfaceCreated(h: SurfaceHolder) {}
                        override fun surfaceChanged(h: SurfaceHolder, f: Int, w: Int, hh: Int) { CameraSender.startPreview(this@apply) }
                        override fun surfaceDestroyed(h: SurfaceHolder) { CameraSender.stopPreview() }
                    })
                } }, Modifier.aspectRatio(16f / 9f, matchHeightConstraintsFirst = true)
                    .border(3.dp, if (link is ClientStatus.Connected) HrColors.Turquoise else HrColors.Outline))
            } else Text(st.error ?: "Preparando cámara…", color = HrColors.TextMuted)
        }
        Column(Modifier.width(190.dp).fillMaxHeight().background(HrColors.Panel).verticalScroll(rememberScrollState()).padding(8.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("CÁMARA REMOTA", color = HrColors.Orange, fontWeight = FontWeight.Black)
            val (txt, col) = when (val l = link) {
                is ClientStatus.Connected -> "Enviando al Estudio como ${l.assignedName}" to HrColors.Turquoise
                is ClientStatus.Connecting -> "Buscando el Estudio… (intento ${l.attempt})" to HrColors.Amber
                is ClientStatus.Rejected -> l.reason to HrColors.OnAirRed
                ClientStatus.Idle -> "Sin conexión" to HrColors.TextMuted
            }
            Text(txt, color = col, fontWeight = FontWeight.Bold)
            Text("${st.fps} fps · temp ${Thermal.fromAndroid(st.thermal).label}", color = HrColors.TextPrimary, fontSize = 13.sp)
            Text("El sonido lo toma el teléfono Estudio. Deja este teléfono fijo, en trípode y enchufado.", color = HrColors.TextMuted, fontSize = 12.sp)
            OutlinedButton(onClick = { saver = !saver }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(if (saver) "VER CÁMARA" else "AHORRO (menos calor)") }
            OutlinedButton(onClick = { CameraSender.switchCamera() }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("CAMBIAR CÁMARA") }
            if (link is ClientStatus.Rejected) Button(onClick = onUnpair, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("ESCANEAR DE NUEVO") }
            else OutlinedButton(onClick = onUnpair, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("OTRO ESTUDIO") }
            OutlinedButton(onClick = exit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("SALIR") }
        }
    }
    // Brillo mínimo en ahorro.
    val activity = LocalContext.current as? androidx.activity.ComponentActivity
    DisposableEffect(saver) {
        val w = activity?.window; val lp = w?.attributes
        lp?.screenBrightness = if (saver) 0.02f else android.view.WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE
        if (lp != null) w.attributes = lp
        onDispose {}
    }
}
