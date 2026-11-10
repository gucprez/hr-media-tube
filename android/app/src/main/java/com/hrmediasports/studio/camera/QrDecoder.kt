package com.hrmediasports.studio.camera

import com.google.zxing.BarcodeFormat
import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.NotFoundException
import com.google.zxing.PlanarYUVLuminanceSource
import com.google.zxing.common.HybridBinarizer
import com.google.zxing.qrcode.QRCodeReader
import com.hrmediasports.link.PairingInfo

/** Decodifica el QR de vinculación a partir de un plano de luminancia (Y). Lógica pura, sin cámara. */
object QrDecoder {
    private val hints = mapOf(
        DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormat.QR_CODE),
        DecodeHintType.TRY_HARDER to true,
    )

    /** Texto del QR, o null si en el cuadro no hay ninguno legible. */
    fun decodeText(luma: ByteArray, width: Int, height: Int, rowStride: Int = width): String? {
        // El plano Y de CameraX puede traer relleno por fila: se compacta antes de decodificar.
        val data = if (rowStride == width) luma else ByteArray(width * height).also {
            for (row in 0 until height) System.arraycopy(luma, row * rowStride, it, row * width, width)
        }
        val source = PlanarYUVLuminanceSource(data, width, height, 0, 0, width, height, false)
        return try {
            QRCodeReader().decode(BinaryBitmap(HybridBinarizer(source)), hints).text
        } catch (e: NotFoundException) {
            null
        } catch (e: Exception) {
            null
        }
    }

    /** Vinculación válida (formato y versión) o null. No comprueba vigencia: eso lo decide el estudio. */
    fun decodePairing(luma: ByteArray, width: Int, height: Int, rowStride: Int = width): PairingInfo? =
        decodeText(luma, width, height, rowStride)?.let { PairingInfo.parse(it) }
}
