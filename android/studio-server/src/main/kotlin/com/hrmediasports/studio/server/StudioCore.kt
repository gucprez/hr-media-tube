package com.hrmediasports.studio.server

import com.hrmediasports.link.Crypto
import com.hrmediasports.link.Handshake
import com.hrmediasports.link.Hello
import com.hrmediasports.link.LINK_PROTOCOL_VERSION
import com.hrmediasports.link.PairingInfo
import com.hrmediasports.link.Telemetry

enum class DeviceState { PENDING, ACCEPTED }

class Device(val id: String, var name: String, val role: String) {
    var state = DeviceState.PENDING
    var online = false
    var connId = 0L
    var tokenKey: ByteArray? = null
    var onAir = false
    var telemetry: Telemetry? = null
    var lastSeenMillis = 0L
}

/** Resultado de validar un `hello`. */
sealed interface HelloDecision {
    /** `authKey`: secreto del QR o token de reconexión, según `hello.auth`. */
    class Ok(val authKey: ByteArray, val reconnect: Boolean) : HelloDecision
    class Fail(val code: String, val detail: String = "") : HelloDecision
}

enum class AcceptResult { OK, UNKNOWN, CAMERA_LIMIT }

/** Una conexión aceptada a nivel de autenticación. */
class Ticket(val device: Device, val connId: Long, val supersededOnline: Boolean)

@kotlinx.serialization.Serializable
class DeviceInfo(
    val id: String,
    val name: String,
    val role: String,
    val state: String,
    val online: Boolean,
    val onAir: Boolean,
    val telemetry: Telemetry?,
)

/**
 * Reglas del estudio sin red: vinculación, aceptación y reconexión sin duplicar dispositivos.
 * Es segura entre hilos (todo bajo el mismo cerrojo).
 */
class StudioCore(
    /** Primera versión: una cámara hasta validar 720p/30 en dispositivos reales (docs/PRUEBAS-TECNICAS.md). */
    val maxCameras: Int = 1,
    private val clock: () -> Long = System::currentTimeMillis,
) {
    @Volatile
    var pairing: PairingInfo? = null
        private set

    private val devices = LinkedHashMap<String, Device>()
    private var failedProofs = 0
    private var nextConnId = 0L

    /** Crear estudio: genera una credencial nueva y olvida todo lo anterior. */
    @Synchronized
    fun createStudio(host: String, port: Int): PairingInfo {
        devices.clear()
        failedProofs = 0
        return PairingInfo.create(host, port, clock()).also { pairing = it }
    }

    @Synchronized
    fun decide(nonceS: String, hello: Hello): HelloDecision {
        val p = pairing ?: return HelloDecision.Fail("no_studio", "Aún no se creó el estudio")
        if (hello.v != LINK_PROTOCOL_VERSION) return HelloDecision.Fail("unsupported_version")
        if (hello.role != "camera" && hello.role != "scoreboard") return HelloDecision.Fail("bad_role")
        if (hello.deviceId.isBlank() || hello.deviceId.length > 64) return HelloDecision.Fail("bad_device")
        val existing = devices[hello.deviceId]

        return when (hello.auth) {
            Handshake.AUTH_TOKEN -> {
                val key = existing?.takeIf { it.state == DeviceState.ACCEPTED }?.tokenKey
                    ?: return HelloDecision.Fail("unknown_device")
                val expected = Handshake.deviceProof(key, p.sessionId, nonceS, hello.nonceD, hello.deviceId, Handshake.AUTH_TOKEN)
                if (Crypto.constantTimeEquals(expected, hello.proof)) HelloDecision.Ok(key, reconnect = true)
                else HelloDecision.Fail("bad_proof")
            }
            Handshake.AUTH_PAIRING -> {
                if (failedProofs >= MAX_FAILED_PROOFS) return HelloDecision.Fail("locked", "Demasiados intentos; crea el estudio de nuevo")
                if (p.isExpired(clock())) return HelloDecision.Fail("pairing_expired")
                // Un dispositivo ya aceptado solo vuelve con su token: con el QR no puede suplantarlo.
                if (existing?.state == DeviceState.ACCEPTED) return HelloDecision.Fail("device_exists")
                val expected = Handshake.deviceProof(p.secret, p.sessionId, nonceS, hello.nonceD, hello.deviceId, Handshake.AUTH_PAIRING)
                if (!Crypto.constantTimeEquals(expected, hello.proof)) {
                    failedProofs++
                    HelloDecision.Fail("bad_proof")
                } else {
                    HelloDecision.Ok(p.secret, reconnect = false)
                }
            }
            else -> HelloDecision.Fail("bad_auth")
        }
    }

    /** Registra la conexión ya autenticada. Un mismo `deviceId` nunca genera dos entradas. */
    @Synchronized
    fun register(hello: Hello, decision: HelloDecision.Ok): Ticket {
        val device = devices.getOrPut(hello.deviceId) { Device(hello.deviceId, hello.name, hello.role) }
        device.name = hello.name
        val superseded = device.online
        device.online = true
        device.connId = ++nextConnId
        device.lastSeenMillis = clock()
        if (decision.reconnect) device.state = DeviceState.ACCEPTED
        return Ticket(device, device.connId, superseded)
    }

    /** El operador acepta la cámara: se calcula (no se transmite) su token de reconexión. */
    @Synchronized
    fun accept(deviceId: String): AcceptResult {
        val p = pairing ?: return AcceptResult.UNKNOWN
        val d = devices[deviceId] ?: return AcceptResult.UNKNOWN
        if (d.state == DeviceState.ACCEPTED) return AcceptResult.OK
        if (d.role == "camera" && devices.values.count { it.role == "camera" && it.state == DeviceState.ACCEPTED } >= maxCameras) {
            return AcceptResult.CAMERA_LIMIT
        }
        d.tokenKey = Handshake.deviceToken(p.secret, deviceId)
        d.state = DeviceState.ACCEPTED
        return AcceptResult.OK
    }

    /** Rechazar o desconectar/olvidar: el dispositivo desaparece y debe vincularse de nuevo. */
    @Synchronized
    fun remove(deviceId: String): Device? = devices.remove(deviceId)

    /** Marca fuera de línea solo si la conexión que se cerró sigue siendo la vigente. */
    @Synchronized
    fun markOffline(deviceId: String, connId: Long): Boolean {
        val d = devices[deviceId] ?: return false
        if (d.connId != connId) return false
        d.online = false
        d.onAir = false
        return true
    }

    @Synchronized
    fun setOnAir(deviceId: String, active: Boolean) {
        devices[deviceId]?.onAir = active
    }

    @Synchronized
    fun updateTelemetry(deviceId: String, t: Telemetry) {
        devices[deviceId]?.let { it.telemetry = t; it.lastSeenMillis = clock() }
    }

    @Synchronized
    fun get(deviceId: String): Device? = devices[deviceId]

    @Synchronized
    fun snapshot(): List<DeviceInfo> = devices.values.map {
        DeviceInfo(it.id, it.name, it.role, it.state.name.lowercase(), it.online, it.onAir, it.telemetry)
    }

    companion object {
        const val MAX_FAILED_PROOFS = 10
    }
}
