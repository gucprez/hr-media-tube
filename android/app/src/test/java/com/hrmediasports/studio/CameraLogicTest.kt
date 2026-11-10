package com.hrmediasports.studio

import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.hrmediasports.link.PairingInfo
import com.hrmediasports.studio.camera.Quality
import com.hrmediasports.studio.camera.QualityMapper
import com.hrmediasports.studio.camera.QrDecoder
import com.hrmediasports.studio.camera.SendStats
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CameraLogicTest {
    /** Dibuja el QR como lo vería la cámara: plano de luminancia (negro=0, blanco=255) con borde. */
    private fun luma(text: String, scale: Int = 6, quiet: Int = 4, rowPadding: Int = 0): Triple<ByteArray, Int, Int> {
        val m = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, 0, 0, mapOf(EncodeHintType.MARGIN to 0))
        val side = (m.width + quiet * 2) * scale
        val stride = side + rowPadding
        val out = ByteArray(stride * side) { 0xFF.toByte() }
        for (y in 0 until side) for (x in 0 until side) {
            val mx = x / scale - quiet; val my = y / scale - quiet
            if (mx in 0 until m.width && my in 0 until m.height && m.get(mx, my)) out[y * stride + x] = 0
        }
        return Triple(out, side, stride)
    }

    @Test fun decodesPairingQrFromLuminance() {
        val p = PairingInfo.create("192.168.1.20", 8765, nowMillis = 1_000_000)
        val (y, side, stride) = luma(p.toUri())
        val decoded = QrDecoder.decodePairing(y, side, side, stride)
        assertNotNull(decoded)
        assertEquals(p.sessionId, decoded!!.sessionId)
        assertEquals("192.168.1.20", decoded.host)
        assertTrue(p.secret.contentEquals(decoded.secret))
    }

    @Test fun decodesWhenRowsHavePadding() {
        val p = PairingInfo.create("10.0.0.7", 8765, nowMillis = 0)
        val (y, side, stride) = luma(p.toUri(), rowPadding = 16)
        assertEquals(p.sessionId, QrDecoder.decodePairing(y, side, side, stride)!!.sessionId)
    }

    @Test fun otherQrCodesAreNotPairings() {
        val (y, side, stride) = luma("https://example.com/promo")
        assertEquals("https://example.com/promo", QrDecoder.decodeText(y, side, side, stride))
        assertNull(QrDecoder.decodePairing(y, side, side, stride))
    }

    @Test fun blankFrameHasNoQr() {
        val blank = ByteArray(200 * 200) { 0xFF.toByte() }
        assertNull(QrDecoder.decodeText(blank, 200, 200))
    }

    @Test fun qualityThresholds() {
        val good = SendStats(1280, 720, 29.5, 2300, "c2.qti.avc.encoder", 20, 0.5, "none")
        assertEquals(Quality.Good, QualityMapper.quality(good))
        assertEquals(Quality.Fair, QualityMapper.quality(good.copy(fps = 18.0)))
        assertEquals(Quality.Fair, QualityMapper.quality(good.copy(limitation = "bandwidth")))   // limitada por red: ya no es "buena"
        assertEquals(Quality.Poor, QualityMapper.quality(good.copy(fps = 8.0)))
        assertEquals(Quality.Poor, QualityMapper.quality(good.copy(lossPct = 12.0)))
        assertEquals(Quality.Unknown, QualityMapper.quality(SendStats()))
    }

    @Test fun hardwareEncoderInference() {
        assertEquals(true, QualityMapper.isHardwareEncoder("c2.qti.avc.encoder"))
        assertEquals(true, QualityMapper.isHardwareEncoder("OMX.Exynos.AVC.Encoder"))
        assertEquals(true, QualityMapper.isHardwareEncoder("MediaCodecVideoEncoder"))
        assertEquals(false, QualityMapper.isHardwareEncoder("libvpx"))
        assertEquals(false, QualityMapper.isHardwareEncoder("SimulcastEncoderAdapter (libvpx, libvpx)"))
        assertEquals(false, QualityMapper.isHardwareEncoder("OpenH264"))
        assertNull(QualityMapper.isHardwareEncoder(""))
        assertNull(QualityMapper.isHardwareEncoder("desconocido"))
        assertFalse(QualityMapper.isHardwareEncoder("libvpx") == true)
    }
}
