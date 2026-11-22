package com.hrmediasports.studio.link

import com.hrmediasports.studio.domain.MatchState
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.IOException
import java.net.URLDecoder
import java.net.URLEncoder
import java.security.MessageDigest
import java.security.SecureRandom
import java.util.Locale
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Enlace local por wifi entre el ESTUDIO y los teléfonos PIZARRA y CÁMARA (protocolo LAN v1).
 * Kotlin puro (java.net), sin dependencias, para probarlo con sockets reales.
 *
 *  - El Estudio abre un puerto TCP y muestra un QR con su IP, puerto y un secreto aleatorio.
 *  - Cada teléfono se conecta y demuestra que conoce el secreto (HMAC-SHA256 sobre un reto),
 *    sin enviarlo. Sin el QR nadie de la red puede mandar marcadores ni video.
 *  - Después viajan tramas [tipo:1][largo:4][datos].
 */
const val LAN_PORT = 47800
const val LAN_VERSION = 1
const val MAX_FRAME = 4 * 1024 * 1024

object FrameType {
    const val CHALLENGE = 1      // estudio → teléfono: reto aleatorio
    const val HELLO = 2          // teléfono → estudio: rol, id, nombre, HMAC
    const val WELCOME = 3        // estudio → teléfono: aceptado (texto: nombre asignado)
    const val REJECT = 4         // estudio → teléfono: motivo
    const val SCORE = 10         // pizarra → estudio
    const val VIDEO_CONFIG = 20  // cámara → estudio: SPS/PPS + resolución
    const val VIDEO_FRAME = 21   // cámara → estudio: un cuadro H.264
    const val THUMB = 22         // cámara → estudio: miniatura JPEG para la vista previa
    const val STATS = 23         // cámara → estudio: temperatura, batería
    const val PING = 30
}

enum class LanRole(val wire: String) {
    Scoreboard("pizarra"), Camera("camara");
    companion object { fun fromWire(s: String): LanRole? = entries.firstOrNull { it.wire == s } }
}

/** Lo que contiene el QR del Estudio. */
class PairingCode(val host: String, val port: Int, val secret: ByteArray) {
    fun toUri(): String = "hrms://estudio?v=$LAN_VERSION&h=${enc(host)}&p=$port&k=${Base32.encode(secret)}"

    /** Código para escribir a mano si no se puede leer el QR (grupos de 4). */
    fun manualCode(): String = Base32.encode(secret).chunked(4).joinToString("-")

    override fun equals(other: Any?): Boolean =
        other is PairingCode && other.host == host && other.port == port && other.secret.contentEquals(secret)
    override fun hashCode(): Int = host.hashCode() * 31 + port

    companion object {
        const val SECRET_BYTES = 10 // 80 bits → 16 caracteres base32

        fun random(host: String, port: Int): PairingCode =
            PairingCode(host, port, ByteArray(SECRET_BYTES).also { SecureRandom().nextBytes(it) })

        fun parse(text: String): PairingCode? {
            val t = text.trim()
            if (!t.startsWith("hrms://estudio?")) return null
            val q = t.substringAfter('?').split('&').mapNotNull {
                val i = it.indexOf('='); if (i <= 0) null else it.substring(0, i) to dec(it.substring(i + 1))
            }.toMap()
            if (q["v"]?.toIntOrNull() != LAN_VERSION) return null
            val host = q["h"]?.takeIf { it.isNotBlank() } ?: return null
            val port = q["p"]?.toIntOrNull()?.takeIf { it in 1..65535 } ?: return null
            val secret = q["k"]?.let { Base32.decode(it) }?.takeIf { it.size == SECRET_BYTES } ?: return null
            return PairingCode(host, port, secret)
        }

        /** Datos escritos a mano: IP, puerto (opcional) y el código de 16 letras. */
        fun manual(host: String, port: Int, code: String): PairingCode? {
            val secret = Base32.decode(code) ?: return null
            if (secret.size != SECRET_BYTES || host.isBlank() || port !in 1..65535) return null
            return PairingCode(host.trim(), port, secret)
        }

        private fun enc(s: String) = URLEncoder.encode(s, "UTF-8")
        private fun dec(s: String) = URLDecoder.decode(s, "UTF-8")
    }
}

/** Base32 (RFC 4648, sin relleno): solo mayúsculas y números fáciles de dictar. */
object Base32 {
    private const val ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"

    fun encode(data: ByteArray): String {
        val sb = StringBuilder()
        var buffer = 0; var bits = 0
        for (b in data) {
            buffer = (buffer shl 8) or (b.toInt() and 0xFF); bits += 8
            while (bits >= 5) { sb.append(ALPHABET[(buffer shr (bits - 5)) and 31]); bits -= 5 }
        }
        if (bits > 0) sb.append(ALPHABET[(buffer shl (5 - bits)) and 31])
        return sb.toString()
    }

    fun decode(text: String): ByteArray? {
        val clean = text.uppercase(Locale.ROOT).filter { it != '-' && it != ' ' }
            .replace('0', 'O').replace('1', 'I').replace('8', 'B') // confusiones típicas al copiar
        val out = java.io.ByteArrayOutputStream()
        var buffer = 0; var bits = 0
        for (c in clean) {
            val v = ALPHABET.indexOf(c); if (v < 0) return null
            buffer = (buffer shl 5) or v; bits += 5
            if (bits >= 8) { out.write((buffer shr (bits - 8)) and 0xFF); bits -= 8 }
        }
        return out.toByteArray()
    }
}

object LanCrypto {
    fun hmac(secret: ByteArray, vararg parts: ByteArray): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(secret, "HmacSHA256"))
        parts.forEach { mac.update(it); mac.update(0) }
        return mac.doFinal()
    }

    fun helloMac(secret: ByteArray, challenge: ByteArray, role: LanRole, id: String): ByteArray =
        hmac(secret, "hello-v$LAN_VERSION".toByteArray(), challenge, role.wire.toByteArray(), id.toByteArray())

    fun equal(a: ByteArray, b: ByteArray): Boolean = MessageDigest.isEqual(a, b)
    fun challenge(): ByteArray = ByteArray(16).also { SecureRandom().nextBytes(it) }
}

class Frame(val type: Int, val payload: ByteArray)

object FrameIO {
    @Throws(IOException::class)
    fun write(out: DataOutputStream, type: Int, payload: ByteArray) {
        synchronized(out) {
            out.writeByte(type); out.writeInt(payload.size); out.write(payload); out.flush()
        }
    }

    @Throws(IOException::class)
    fun read(input: DataInputStream): Frame {
        val type = input.readUnsignedByte()
        val len = input.readInt()
        if (len < 0 || len > MAX_FRAME) throw IOException("trama inválida ($len bytes)")
        val data = ByteArray(len); input.readFully(data)
        return Frame(type, data)
    }
}

/** Mensajes simples clave=valor (sin dependencias de JSON). */
object KV {
    fun encode(map: Map<String, String>): ByteArray =
        map.entries.joinToString("&") { "${URLEncoder.encode(it.key, "UTF-8")}=${URLEncoder.encode(it.value, "UTF-8")}" }.toByteArray()

    fun decode(bytes: ByteArray): Map<String, String> = String(bytes).split('&').mapNotNull {
        val i = it.indexOf('='); if (i <= 0) null else URLDecoder.decode(it.substring(0, i), "UTF-8") to URLDecoder.decode(it.substring(i + 1), "UTF-8")
    }.toMap()
}

data class Hello(val role: LanRole, val id: String, val name: String, val mac: ByteArray) {
    fun encode(): ByteArray = KV.encode(mapOf("r" to role.wire, "id" to id, "n" to name, "m" to Base32.encode(mac)))
    companion object {
        fun decode(b: ByteArray): Hello? {
            val m = KV.decode(b)
            return Hello(LanRole.fromWire(m["r"] ?: return null) ?: return null, m["id"]?.take(64) ?: return null,
                m["n"]?.take(40) ?: "", Base32.decode(m["m"] ?: return null) ?: return null)
        }
    }
}

/** Lectura de la pizarra enviada al Estudio. */
data class ScoreMessage(
    val match: MatchState,
    /** 0..1 en lectura automática; null si la marcó una persona. */
    val confidence: Float?,
    val auto: Boolean,
    val seq: Long,
    val clockRunning: Boolean = false,
) {
    fun encode(): ByteArray = KV.encode(buildMap {
        put("h", match.homePoints.toString()); put("a", match.awayPoints.toString())
        put("c", match.clockSeconds.toString()); put("p", match.period.toString()); put("s", match.possessionSeconds.toString())
        put("auto", if (auto) "1" else "0"); put("seq", seq.toString()); put("run", if (clockRunning) "1" else "0")
        confidence?.let { put("conf", String.format(Locale.ROOT, "%.3f", it)) }
    })

    companion object {
        fun decode(b: ByteArray): ScoreMessage? = runCatching {
            val m = KV.decode(b)
            ScoreMessage(
                MatchState(m.getValue("h").toInt(), m.getValue("a").toInt(), m.getValue("c").toInt(), m.getValue("p").toInt(), m.getValue("s").toInt()),
                m["conf"]?.toFloat()?.coerceIn(0f, 1f), m["auto"] == "1", m.getValue("seq").toLong(), m["run"] == "1",
            )
        }.getOrNull() // valores fuera de rango (MatchState los valida) → se descarta
    }
}

/** SPS/PPS + resolución del video de una cámara remota. */
data class VideoConfig(val width: Int, val height: Int, val sps: ByteArray, val pps: ByteArray) {
    fun encode(): ByteArray {
        val bos = java.io.ByteArrayOutputStream(); val out = DataOutputStream(bos)
        out.writeInt(width); out.writeInt(height); out.writeInt(sps.size); out.write(sps); out.writeInt(pps.size); out.write(pps)
        return bos.toByteArray()
    }
    override fun equals(other: Any?) = other is VideoConfig && width == other.width && height == other.height &&
        sps.contentEquals(other.sps) && pps.contentEquals(other.pps)
    override fun hashCode() = width * 31 + height

    companion object {
        fun decode(b: ByteArray): VideoConfig? = runCatching {
            val i = DataInputStream(b.inputStream())
            val w = i.readInt(); val h = i.readInt()
            val sps = ByteArray(i.readInt().also { require(it in 1..1024) }).also { i.readFully(it) }
            val pps = ByteArray(i.readInt().also { require(it in 1..1024) }).also { i.readFully(it) }
            require(w in 16..4096 && h in 16..4096)
            VideoConfig(w, h, sps, pps)
        }.getOrNull()
    }
}

/** Un cuadro H.264 (Annex-B) con su marca de tiempo en microsegundos. */
class VideoFrame(val ptsUs: Long, val keyFrame: Boolean, val data: ByteArray) {
    fun encode(): ByteArray {
        val bos = java.io.ByteArrayOutputStream(data.size + 9); val out = DataOutputStream(bos)
        out.writeByte(if (keyFrame) 1 else 0); out.writeLong(ptsUs); out.write(data)
        return bos.toByteArray()
    }
    companion object {
        fun decode(b: ByteArray): VideoFrame? {
            if (b.size < 10) return null
            val i = DataInputStream(b.inputStream())
            val key = i.readByte().toInt() == 1; val pts = i.readLong()
            return VideoFrame(pts, key, b.copyOfRange(9, b.size))
        }
    }
}

/** Datos de salud del teléfono cámara (para avisar al operador del Estudio). */
data class DeviceStats(val thermal: Int, val battery: Int, val fps: Int) {
    fun encode() = KV.encode(mapOf("t" to "$thermal", "b" to "$battery", "f" to "$fps"))
    companion object {
        fun decode(b: ByteArray): DeviceStats? = runCatching {
            val m = KV.decode(b); DeviceStats(m.getValue("t").toInt(), m.getValue("b").toInt(), m.getValue("f").toInt())
        }.getOrNull()
    }
}
