package com.hrmediasports.studio.server

import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import java.awt.image.BufferedImage
import java.io.ByteArrayOutputStream
import javax.imageio.ImageIO

/** PNG del QR (módulos negros sobre blanco con borde, como exigen los lectores). */
fun qrPng(text: String, modulePx: Int = 8, quietModules: Int = 4): ByteArray {
    val matrix = QRCodeWriter().encode(
        text, BarcodeFormat.QR_CODE, 0, 0,
        mapOf(EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M, EncodeHintType.MARGIN to 0),
    )
    val size = (matrix.width + quietModules * 2) * modulePx
    val img = BufferedImage(size, size, BufferedImage.TYPE_INT_RGB)
    for (y in 0 until size) for (x in 0 until size) {
        val mx = x / modulePx - quietModules
        val my = y / modulePx - quietModules
        val black = mx in 0 until matrix.width && my in 0 until matrix.height && matrix.get(mx, my)
        img.setRGB(x, y, if (black) 0x000000 else 0xFFFFFF)
    }
    return ByteArrayOutputStream().also { ImageIO.write(img, "png", it) }.toByteArray()
}
