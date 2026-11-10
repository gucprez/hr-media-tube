package com.hrmediasports.studio.server

import java.net.Inet4Address
import java.net.NetworkInterface

class LanAddress(val name: String, val ip: String)

/** Direcciones IPv4 privadas de esta laptop (candidatas para el QR). */
fun lanAddresses(): List<LanAddress> = try {
    NetworkInterface.getNetworkInterfaces().toList()
        .filter { it.isUp && !it.isLoopback && !it.isVirtual }
        .flatMap { nif ->
            nif.inetAddresses.toList()
                .filterIsInstance<Inet4Address>()
                .filter { it.isSiteLocalAddress }
                .map { LanAddress(nif.displayName, it.hostAddress) }
        }
} catch (e: Exception) {
    emptyList()
}
