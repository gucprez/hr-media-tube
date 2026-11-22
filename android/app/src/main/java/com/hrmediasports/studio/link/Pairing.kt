package com.hrmediasports.studio.link

import android.content.Context
import android.os.Build
import android.util.Size
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.google.zxing.BarcodeFormat
import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.MultiFormatReader
import com.google.zxing.PlanarYUVLuminanceSource
import com.google.zxing.common.HybridBinarizer
import com.hrmediasports.studio.ui.theme.HrColors
import java.util.UUID
import java.util.concurrent.Executors

/** Recuerda el Estudio emparejado y el id de este teléfono (para reconectar solo tras reiniciar). */
class PairingStore(context: Context, private val role: LanRole) {
    private val p = context.applicationContext.getSharedPreferences("enlace-${role.wire}", Context.MODE_PRIVATE)
    fun load(): PairingCode? = p.getString("uri", null)?.let { PairingCode.parse(it) }
    fun save(code: PairingCode?) { p.edit().putString("uri", code?.toUri()).apply() }
    val deviceId: String get() = p.getString("id", null) ?: UUID.randomUUID().toString().also { p.edit().putString("id", it).apply() }
    val deviceName: String get() = "${Build.MANUFACTURER} ${Build.MODEL}".take(40)
}

/** Pantalla para emparejar con el Estudio: escanear su QR o escribir los datos a mano. */
@Composable
fun PairScreen(title: String, onPaired: (PairingCode) -> Unit, onExit: () -> Unit) {
    var manual by remember { mutableStateOf(false) }
    var ip by remember { mutableStateOf("") }
    var port by remember { mutableStateOf(LAN_PORT.toString()) }
    var code by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    Row(Modifier.fillMaxSize().background(HrColors.Background).padding(12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Box(Modifier.weight(1f).fillMaxHeight(), contentAlignment = Alignment.Center) {
            if (!manual) QrScanner(Modifier.aspectRatio(4f / 3f)) { text ->
                val c = PairingCode.parse(text)
                if (c != null) onPaired(c) else error = "Ese QR no es de un Estudio HR Media Sports."
            } else Text("Ingreso manual", color = HrColors.TextMuted)
        }
        Column(Modifier.weight(1f).fillMaxHeight().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(title, color = HrColors.TextPrimary, fontWeight = FontWeight.Black, fontSize = 20.sp)
            Text("1. En el teléfono ESTUDIO toca CONECTAR.\n2. Apunta esta cámara al QR que aparece.\nLos dos teléfonos deben estar en la misma red wifi.",
                color = HrColors.TextPrimary)
            error?.let { Text(it, color = HrColors.Amber, fontWeight = FontWeight.Bold) }
            TextButton(onClick = { manual = !manual; error = null }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(if (manual) "Volver a escanear el QR" else "No puedo leer el QR: escribir los datos")
            }
            if (manual) {
                OutlinedTextField(ip, { ip = it.trim() }, label = { Text("IP del Estudio (ej. 192.168.1.20)") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
                OutlinedTextField(port, { port = it.filter(Char::isDigit).take(5) }, label = { Text("Puerto") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                OutlinedTextField(code, { code = it.uppercase().take(19) }, label = { Text("Código (XXXX-XXXX-XXXX-XXXX)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Button(onClick = {
                    val c = PairingCode.manual(ip, port.toIntOrNull() ?: 0, code)
                    if (c == null) error = "Revisa la IP y el código (16 letras y números)." else onPaired(c)
                }, modifier = Modifier.heightIn(min = 56.dp)) { Text("CONECTAR") }
            }
            TextButton(onClick = onExit, modifier = Modifier.heightIn(min = 48.dp)) { Text("Volver") }
        }
    }
}

/** Vista de cámara (CameraX) que lee códigos QR con ZXing. */
@Composable
fun QrScanner(modifier: Modifier = Modifier, onText: (String) -> Unit) {
    val context = LocalContext.current
    val owner = LocalLifecycleOwner.current
    val previewView = remember { PreviewView(context).apply { scaleType = PreviewView.ScaleType.FIT_CENTER } }
    DisposableEffect(owner) {
        val executor = Executors.newSingleThreadExecutor()
        val future = ProcessCameraProvider.getInstance(context)
        var provider: ProcessCameraProvider? = null
        val reader = MultiFormatReader().apply { setHints(mapOf(DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormat.QR_CODE))) }
        var done = false
        future.addListener({
            val p = future.get(); provider = p
            val preview = Preview.Builder().build().also { it.setSurfaceProvider(previewView.surfaceProvider) }
            @Suppress("DEPRECATION")
            val analysis = ImageAnalysis.Builder().setTargetResolution(Size(1280, 720))
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
            analysis.setAnalyzer(executor) { img ->
                try {
                    if (done) return@setAnalyzer
                    val plane = img.planes[0]
                    val buf = plane.buffer; val bytes = ByteArray(buf.remaining()); buf.get(bytes)
                    val src = PlanarYUVLuminanceSource(bytes, plane.rowStride, img.height, 0, 0, img.width, img.height, false)
                    val text = runCatching { reader.decodeWithState(BinaryBitmap(HybridBinarizer(src))).text }.getOrNull()
                    reader.reset()
                    if (text != null) { done = true; ContextCompat.getMainExecutor(context).execute { onText(text) } }
                } finally { img.close() }
            }
            runCatching { p.unbindAll(); p.bindToLifecycle(owner, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis) }
        }, ContextCompat.getMainExecutor(context))
        onDispose { runCatching { provider?.unbindAll() }; executor.shutdown() }
    }
    AndroidView({ previewView }, modifier)
}
