package com.hrmediasports.studio

import com.hrmediasports.link.Handshake
import com.hrmediasports.link.OnAir
import com.hrmediasports.studio.camera.LinkClient
import com.hrmediasports.studio.camera.LinkEvent
import com.hrmediasports.studio.server.Hub
import com.hrmediasports.studio.server.StudioCore
import com.hrmediasports.studio.server.studioModule
import io.ktor.server.engine.embeddedServer
import io.ktor.server.netty.Netty
import io.ktor.websocket.close
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import java.net.ServerSocket
import java.util.Collections

/** Cliente de enlace de la app (OkHttp) contra el servidor del estudio (Ktor) por sockets reales en esta máquina. */
class LinkClientIntegrationTest {
    private val core = StudioCore()
    private val hub = Hub(core)
    private var port = 0
    private lateinit var server: io.ktor.server.engine.ApplicationEngine
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val events: MutableList<LinkEvent> = Collections.synchronizedList(mutableListOf())

    @Before fun start() {
        port = ServerSocket(0).use { it.localPort }
        server = embeddedServer(Netty, port = port, host = "127.0.0.1") { studioModule(core, port, hub) }.start(wait = false)
    }

    @After fun stop() {
        scope.cancel()
        server.stop(100, 500)
    }

    private fun client(id: String = "cel-1") = LinkClient(scope, id, "Celular $id").also { c ->
        scope.launch { c.events.collect { events.add(it) } }
    }

    private suspend fun await(what: String, cond: () -> Boolean) {
        val ok = kotlinx.coroutines.withTimeoutOrNull(10_000) { while (!cond()) delay(25); true }
        if (ok != true) org.junit.Assert.fail("Tiempo agotado esperando: $what. Eventos: ${synchronized(events) { events.toList() }}")
    }

    private inline fun <reified T : LinkEvent> has(noinline p: (T) -> Boolean = { true }) = synchronized(events) { events.filterIsInstance<T>().any(p) }

    @Test fun pairAcceptReconnectWithTokenWithoutDuplicates() = runBlocking {
        val p = core.createStudio("127.0.0.1", port)
        val c = client()
        delay(200)
        c.connectWithPairing("127.0.0.1", port, p.secret)

        await("pendiente") { has<LinkEvent.Authenticated> { it.state == "pending" } }
        assertEquals("pending", core.snapshot().single().state)

        core.accept("cel-1"); hub.conns["cel-1"]!!.send(com.hrmediasports.link.Accepted)
        await("aceptado") { has<LinkEvent.Accepted>() }

        // El token se deriva en el dispositivo; coincide con el que calcula el estudio sin haberse transmitido.
        val binding = c.derivedBinding()!!
        assertTrue(Handshake.deviceToken(p.secret, "cel-1").contentEquals(binding.token))

        // Corte de la conexión del lado servidor: el cliente reintenta SOLO con el token.
        events.clear()
        hub.conns["cel-1"]!!.session.close()
        await("pérdida") { has<LinkEvent.Lost>() }
        await("reautenticado") { has<LinkEvent.Authenticated> { it.state == "accepted" } }
        await("aceptado de inmediato") { has<LinkEvent.Accepted>() }

        // Sigue habiendo UNA sola cámara y está en línea.
        assertEquals(1, core.snapshot().size)
        assertTrue(core.snapshot().single().online)
        assertTrue(!has<LinkEvent.Fatal>())

        // Mensajes del estudio llegan verificados.
        hub.conns["cel-1"]!!.send(OnAir(true))
        await("al aire") { has<LinkEvent.Message> { it.message == OnAir(true) } }
        c.close()
    }

    @Test fun wrongSecretIsFatalBadProof() = runBlocking {
        core.createStudio("127.0.0.1", port)
        val c = client()
        delay(200)
        c.connectWithPairing("127.0.0.1", port, ByteArray(16) { 7 })
        await("fatal") { has<LinkEvent.Fatal> { it.code == "bad_proof" } }
        assertTrue(core.snapshot().isEmpty())
    }

    @Test fun newStudioMakesTheOldTokenUnknown() = runBlocking {
        val p = core.createStudio("127.0.0.1", port)
        val c = client()
        delay(200)
        c.connectWithPairing("127.0.0.1", port, p.secret)
        await("pendiente") { has<LinkEvent.Authenticated>() }
        core.accept("cel-1"); hub.conns["cel-1"]!!.send(com.hrmediasports.link.Accepted)
        await("aceptado") { has<LinkEvent.Accepted>() }

        // El operador pulsa "Crear estudio" otra vez: credencial nueva y lista vacía.
        events.clear()
        core.createStudio("127.0.0.1", port)
        hub.conns["cel-1"]!!.session.close()
        await("el cliente debe pedir vincular de nuevo") { has<LinkEvent.Fatal> { it.code == "unknown_device" } }
    }

    @Test fun noStudioYetRetriesInsteadOfFailing() = runBlocking {
        val c = client()
        delay(200)
        c.connectWithPairing("127.0.0.1", port, ByteArray(16) { 1 })
        await("reintento") { has<LinkEvent.Lost>() }
        assertTrue(!has<LinkEvent.Fatal>())
        c.close()
    }
}
