package com.hrmediasports.studio.camera

import android.annotation.SuppressLint
import android.content.Context
import android.hardware.camera2.CaptureRequest
import android.util.Range
import android.util.Size
import android.view.Surface
import androidx.camera.camera2.interop.Camera2Interop
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.FocusMeteringAction
import androidx.camera.core.Preview
import androidx.camera.core.SurfaceOrientedMeteringPointFactory
import androidx.camera.core.resolutionselector.AspectRatioStrategy
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import org.webrtc.CapturerObserver
import org.webrtc.SurfaceTextureHelper
import org.webrtc.VideoCapturer
import java.util.concurrent.TimeUnit

enum class Lens(val label: String, val selector: CameraSelector) {
    Back("Trasera", CameraSelector.DEFAULT_BACK_CAMERA),
    Front("Frontal", CameraSelector.DEFAULT_FRONT_CAMERA),
}

/** Rango de los controles de la cámara activa (lo informa CameraX tras abrir la cámara). */
data class CameraCaps(
    val minZoom: Float = 1f,
    val maxZoom: Float = 1f,
    val expMin: Int = 0,
    val expMax: Int = 0,
    val expStepEv: Float = 0f,
)

/**
 * Captura con CameraX y entrega los cuadros a WebRTC por textura (sin copias de CPU): CameraX escribe
 * en la `SurfaceTexture` del `SurfaceTextureHelper` de WebRTC. Así se conservan los controles de
 * CameraX (zoom, enfoque, exposición, cambio de lente), que `Camera2Capturer` de WebRTC no expone.
 */
class CameraXCapturer(
    private val owner: LifecycleOwner,
    initialLens: Lens,
    private val onCaps: (CameraCaps) -> Unit,
) : VideoCapturer {

    @Volatile
    var lens: Lens = initialLens
        private set

    private var helper: SurfaceTextureHelper? = null
    private var observer: CapturerObserver? = null
    private var appContext: Context? = null
    private var camera: Camera? = null
    private var provider: ProcessCameraProvider? = null
    private var width = 1280
    private var height = 720
    private var fps = 30
    private var running = false

    override fun initialize(helper: SurfaceTextureHelper, context: Context, observer: CapturerObserver) {
        this.helper = helper
        this.appContext = context.applicationContext
        this.observer = observer
    }

    override fun startCapture(width: Int, height: Int, framerate: Int) {
        this.width = width; this.height = height; this.fps = framerate
        running = true
        val ctx = appContext ?: return
        val future = ProcessCameraProvider.getInstance(ctx)
        future.addListener({
            provider = future.get()
            bind()
        }, ContextCompat.getMainExecutor(ctx))
    }

    @SuppressLint("UnsafeOptInUsageError")
    private fun bind() {
        val ctx = appContext ?: return
        val p = provider ?: return
        val h = helper ?: return
        if (!running) return
        p.unbindAll()

        val builder = Preview.Builder()
            .setResolutionSelector(
                ResolutionSelector.Builder()
                    .setAspectRatioStrategy(AspectRatioStrategy.RATIO_16_9_FALLBACK_AUTO_STRATEGY)
                    .setResolutionStrategy(ResolutionStrategy(Size(width, height), ResolutionStrategy.FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER))
                    .build(),
            )
        // 30 cuadros por segundo fijos: variar el rango hace que el encoder baje la tasa con poca luz.
        Camera2Interop.Extender(builder).setCaptureRequestOption(CaptureRequest.CONTROL_AE_TARGET_FPS_RANGE, Range(fps, fps))
        val preview = builder.build()

        preview.setSurfaceProvider(ContextCompat.getMainExecutor(ctx)) { request ->
            val size = request.resolution
            h.setTextureSize(size.width, size.height)
            val surface = Surface(h.surfaceTexture)
            request.provideSurface(surface, ContextCompat.getMainExecutor(ctx)) { surface.release() }
        }
        h.startListening { frame -> observer?.onFrameCaptured(frame) }

        camera = p.bindToLifecycle(owner, lens.selector, preview)
        camera?.let { cam ->
            val z = cam.cameraInfo.zoomState.value
            val e = cam.cameraInfo.exposureState
            onCaps(
                CameraCaps(
                    minZoom = z?.minZoomRatio ?: 1f,
                    maxZoom = z?.maxZoomRatio ?: 1f,
                    expMin = e.exposureCompensationRange.lower,
                    expMax = e.exposureCompensationRange.upper,
                    expStepEv = e.exposureCompensationStep.toFloat(),
                ),
            )
        }
        observer?.onCapturerStarted(true)
    }

    fun switchLens(to: Lens) {
        if (to == lens) return
        lens = to
        val ctx = appContext ?: return
        ContextCompat.getMainExecutor(ctx).execute { bind() }
    }

    fun setZoomRatio(ratio: Float) { camera?.cameraControl?.setZoomRatio(ratio) }

    fun setExposureIndex(index: Int) { camera?.cameraControl?.setExposureCompensationIndex(index) }

    /** Enfoque y exposición automáticos sobre un punto; x e y normalizados (0..1) sobre el cuadro. */
    fun focusAt(x: Float, y: Float) {
        val cam = camera ?: return
        val point = SurfaceOrientedMeteringPointFactory(1f, 1f).createPoint(x.coerceIn(0f, 1f), y.coerceIn(0f, 1f))
        cam.cameraControl.startFocusAndMetering(
            FocusMeteringAction.Builder(point, FocusMeteringAction.FLAG_AF or FocusMeteringAction.FLAG_AE)
                .setAutoCancelDuration(3, TimeUnit.SECONDS)
                .build(),
        )
    }

    override fun stopCapture() {
        running = false
        helper?.stopListening()
        appContext?.let { ctx -> ContextCompat.getMainExecutor(ctx).execute { provider?.unbindAll() } }
        camera = null
    }

    override fun changeCaptureFormat(width: Int, height: Int, framerate: Int) {
        this.width = width; this.height = height; this.fps = framerate
        appContext?.let { ContextCompat.getMainExecutor(it).execute { bind() } }
    }

    override fun dispose() { stopCapture() }

    override fun isScreencast() = false
}
