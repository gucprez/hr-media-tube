package com.hrmediasports.link

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/** Mensajes del enlace local (JSON). La especificación está en docs/protocolo-conexion.md. */
@Serializable
sealed interface LinkMessage

// --- Apretón de manos (sin firma de sesión: llevan pruebas HMAC) ---

/** Servidor → dispositivo, al abrir el canal. */
@Serializable @SerialName("challenge")
data class Challenge(val v: Int = LINK_PROTOCOL_VERSION, val sessionId: String, val nonceS: String) : LinkMessage

/** Dispositivo → servidor. `auth`: "pairing" (primer vínculo, secreto del QR) o "token" (reconexión). */
@Serializable @SerialName("hello")
data class Hello(
    val v: Int = LINK_PROTOCOL_VERSION,
    val deviceId: String,
    val name: String,
    val role: String,
    val auth: String,
    val nonceD: String,
    val proof: String,
) : LinkMessage

/** Servidor → dispositivo: prueba de que el estudio también conoce el secreto. */
@Serializable @SerialName("hello_ok")
data class HelloOk(val proof: String, val state: String) : LinkMessage

@Serializable @SerialName("hello_error")
data class HelloError(val code: String, val detail: String = "") : LinkMessage

// --- Mensajes de sesión (viajan firmados dentro de un [Envelope]) ---

@Serializable @SerialName("accepted")
data object Accepted : LinkMessage

@Serializable @SerialName("rejected")
data class Rejected(val reason: String) : LinkMessage

@Serializable @SerialName("offer")
data class Offer(val sdp: String) : LinkMessage

@Serializable @SerialName("answer")
data class Answer(val sdp: String) : LinkMessage

@Serializable @SerialName("ice")
data class IceCandidateMsg(val candidate: String, val sdpMid: String?, val sdpMLineIndex: Int) : LinkMessage

/** Estudio → cámara: la fuente está (o dejó de estar) AL AIRE. */
@Serializable @SerialName("onair")
data class OnAir(val active: Boolean) : LinkMessage

/** Cámara → estudio, cada ~2 s. Sirve para el registro de validación. */
@Serializable @SerialName("telemetry")
data class Telemetry(
    val t: Long,
    val width: Int = 0,
    val height: Int = 0,
    val fps: Double = 0.0,
    val bitrateKbps: Int = 0,
    val encoder: String = "",
    val hardwareEncoder: Boolean? = null,
    val rttMs: Int? = null,
    val lossPct: Double? = null,
    val limitation: String? = null,
    val batteryPct: Int? = null,
    val batteryTempC: Double? = null,
    val thermalStatus: Int? = null,
    val chargeCounterUah: Long? = null,
    val currentNowUa: Long? = null,
    val lens: String? = null,
    val zoom: Double? = null,
    val audioOn: Boolean? = null,
) : LinkMessage

@Serializable @SerialName("bye")
data class Bye(val reason: String = "") : LinkMessage

val LinkJson = Json {
    classDiscriminator = "type"
    ignoreUnknownKeys = true
    encodeDefaults = true
}

fun LinkMessage.toJson(): String = LinkJson.encodeToString(LinkMessage.serializer(), this)

fun parseLinkMessage(text: String): LinkMessage? = try {
    LinkJson.decodeFromString(LinkMessage.serializer(), text)
} catch (e: Exception) {
    null
}

/**
 * Contenedor firmado de los mensajes de sesión. `mac` = HMAC(sessionKey, dir|seq|msg).
 * `seq` crece estrictamente en cada sentido: detecta repeticiones y reordenamientos.
 */
@Serializable
data class Envelope(val seq: Long, val msg: String, val mac: String)
