package com.hrmediasports.link

/**
 * Autenticación mutua con secreto compartido (el del QR, o el token derivado tras aceptar).
 * El secreto no viaja: se intercambian nonces y pruebas HMAC. Después, cada mensaje va firmado
 * con una clave de sesión, de modo que nadie en la red puede alterar ofertas SDP ni órdenes.
 */
object Handshake {
    const val AUTH_PAIRING = "pairing"
    const val AUTH_TOKEN = "token"
    const val DIR_TO_SERVER = "d2s"
    const val DIR_TO_DEVICE = "s2d"

    /** Token de reconexión: lo calcula el dispositivo y el estudio, nunca se transmite. */
    fun deviceToken(secret: ByteArray, deviceId: String): ByteArray = Crypto.hmac(secret, "token|$deviceId")

    fun deviceProof(key: ByteArray, sessionId: String, nonceS: String, nonceD: String, deviceId: String, auth: String): String =
        Crypto.hmacHex(key, "dev|$auth|$sessionId|$nonceS|$nonceD|$deviceId")

    fun serverProof(key: ByteArray, sessionId: String, nonceS: String, nonceD: String, deviceId: String): String =
        Crypto.hmacHex(key, "srv|$sessionId|$nonceS|$nonceD|$deviceId")

    fun sessionKey(key: ByteArray, sessionId: String, nonceS: String, nonceD: String): ByteArray =
        Crypto.hmac(key, "sess|$sessionId|$nonceS|$nonceD")
}

/** Firma y verifica mensajes de sesión de un sentido concreto del canal. */
class SessionCodec(private val sessionKey: ByteArray, private val sendDir: String, private val recvDir: String) {
    private var sendSeq = 0L
    private var lastRecvSeq = 0L

    fun seal(message: LinkMessage): String {
        val seq = ++sendSeq
        val body = message.toJson()
        val env = Envelope(seq, body, Crypto.hmacHex(sessionKey, "$sendDir|$seq|$body"))
        return LinkJson.encodeToString(Envelope.serializer(), env)
    }

    /** null si el sobre es inválido, está mal firmado o repite/retrocede la secuencia. */
    fun open(text: String): LinkMessage? {
        val env = try {
            LinkJson.decodeFromString(Envelope.serializer(), text)
        } catch (e: Exception) {
            return null
        }
        if (env.seq <= lastRecvSeq) return null
        if (!Crypto.constantTimeEquals(env.mac, Crypto.hmacHex(sessionKey, "$recvDir|${env.seq}|${env.msg}"))) return null
        val msg = parseLinkMessage(env.msg) ?: return null
        lastRecvSeq = env.seq
        return msg
    }

    companion object {
        fun forServer(key: ByteArray) = SessionCodec(key, Handshake.DIR_TO_DEVICE, Handshake.DIR_TO_SERVER)
        fun forDevice(key: ByteArray) = SessionCodec(key, Handshake.DIR_TO_SERVER, Handshake.DIR_TO_DEVICE)
    }
}
