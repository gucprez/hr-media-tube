package com.hrmediasports.studio.server

import io.ktor.server.engine.embeddedServer
import io.ktor.server.netty.Netty
import java.awt.Desktop
import java.net.URI

/** Estudio de HR Media Sports (laptop): servidor local + interfaz en el navegador. Sin cuentas ni nube. */
fun main(args: Array<String>) {
    val port = args.indexOf("--port").takeIf { it >= 0 }?.let { args.getOrNull(it + 1)?.toIntOrNull() } ?: DEFAULT_PORT
    val openBrowser = "--no-browser" !in args
    val maxCameras = args.indexOf("--max-cameras").takeIf { it >= 0 }?.let { args.getOrNull(it + 1)?.toIntOrNull() } ?: 1
    val core = StudioCore(maxCameras = maxCameras)
    val server = embeddedServer(Netty, port = port, host = "0.0.0.0") { studioModule(core, port) }
    server.start(wait = false)

    val url = "http://localhost:$port"
    println("Estudio HR Media Sports en $url")
    println("Direcciones de red local: " + lanAddresses().joinToString { "${it.ip} (${it.name})" }.ifEmpty { "ninguna detectada" })
    println("Cierra esta ventana (o Ctrl+C) para apagar el estudio.")
    if (openBrowser && !java.awt.GraphicsEnvironment.isHeadless() && Desktop.isDesktopSupported() &&
        Desktop.getDesktop().isSupported(Desktop.Action.BROWSE)
    ) {
        runCatching { Desktop.getDesktop().browse(URI(url)) }
    }
    Thread.currentThread().join()
}
