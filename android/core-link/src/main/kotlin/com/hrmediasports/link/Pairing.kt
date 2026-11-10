package com.hrmediasports.link

import java.net.URLDecoder
import java.net.URLEncoder

const val LINK_PROTOCOL_VERSION = 1

/** Vigencia por defecto de la credencial de vinculación (el QR deja de servir después). */
const val PAIRING_TTL_MILLIS = 5 * 60 * 1000L

/**
 * Datos que viajan en el QR. El QR NO transporta video ni datos del partido: solo dice dónde
 * está el estudio y entrega el secreto temporal con el que el dispositivo se autentica.
 * El secreto nunca se envía por la red: solo se usa para firmar (HMAC).
 */
class PairingInfo(
    val sessionId: String,
    val secret: ByteArray,
    val host: String,
    val port: Int,
    val expiresAtMillis: Long,
) {
    fun isExpired(nowMillis: Long) = nowMillis > expiresAtMillis

    /** hrms://pair?v=1&h=192.168.1.20&p=8765&s=<sid>&k=<secreto b64url>&e=<epoch s> */
    fun toUri(): String = "hrms://pair?v=$LINK_PROTOCOL_VERSION" +
        "&h=${enc(host)}&p=$port&s=${enc(sessionId)}&k=${Crypto.b64url(secret)}&e=${expiresAtMillis / 1000}"

    companion object {
        fun create(host: String, port: Int, nowMillis: Long, ttlMillis: Long = PAIRING_TTL_MILLIS) = PairingInfo(
            sessionId = Crypto.randomHex(8),
            secret = Crypto.randomBytes(16),
            host = host,
            port = port,
            expiresAtMillis = nowMillis + ttlMillis,
        )

        /** Devuelve null si el texto no es un QR de vinculación válido y de versión soportada. */
        fun parse(uri: String): PairingInfo? {
            if (!uri.startsWith("hrms://pair?")) return null
            return try {
                val q = uri.substringAfter('?').split("&").associate {
                    val (k, v) = it.split("=", limit = 2)
                    k to URLDecoder.decode(v, "UTF-8")
                }
                if (q["v"]?.toInt() != LINK_PROTOCOL_VERSION) return null
                val port = q.getValue("p").toInt()
                if (port !in 1..65535) return null
                val secret = Crypto.fromB64url(q.getValue("k"))
                if (secret.size < 16) return null
                PairingInfo(
                    sessionId = q.getValue("s").also { require(it.isNotBlank()) },
                    secret = secret,
                    host = q.getValue("h").also { require(it.isNotBlank()) },
                    port = port,
                    expiresAtMillis = q.getValue("e").toLong() * 1000,
                )
            } catch (e: Exception) {
                null
            }
        }

        private fun enc(s: String) = URLEncoder.encode(s, "UTF-8")
    }
}
