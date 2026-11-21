package com.leikwan.bridge.service

import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardMode

enum class AppConnectionMode { VPN, PORTS }

object PortModePolicy {
    /** Every configured fixed-target Ultra rule, never arbitrary ports or native SOCKS. */
    fun supported(forwards: List<com.leikwan.bridge.runtime.ForwardListItem>, nativeIds: Set<Long> = emptySet()): List<com.leikwan.bridge.runtime.ForwardListItem> =
        forwards.filter { it.mode == ForwardMode.ULTRA && it.backendMode.equals("ultra", true)
            && !it.nativeOutbound && it.forwardId !in nativeIds && it.listenPort in 1..65535
            && it.target.isNotBlank() && it.serviceStatus.equals("active", true) }

    fun defaultForwardIds(catalog: com.leikwan.bridge.runtime.ForwardCatalog): Set<Long> =
        supported(catalog.visibleForwards, catalog.nativeOutbound?.forwards.orEmpty().map { it.forwardId }.toSet())
            .map { it.forwardId }.toSet()

    fun retainAuthorizedRuntime(sameNetwork: Boolean, expiresAt: java.time.Instant?, now: java.time.Instant): Boolean =
        sameNetwork && expiresAt?.isAfter(now) == true

    fun requireListenerAvailable(port: Int, transport: String) {
        val address = java.net.InetAddress.getByName("127.0.0.1")
        var tcp: java.net.ServerSocket? = null
        var udp: java.net.DatagramSocket? = null
        try {
            if (transport != "udp") {
                tcp = java.net.ServerSocket()
                tcp.reuseAddress = false
                tcp.bind(java.net.InetSocketAddress(address, port))
            }
            if (transport != "tcp") {
                udp = java.net.DatagramSocket(null)
                udp.reuseAddress = false
                udp.bind(java.net.InetSocketAddress(address, port))
            }
        } catch (error: Exception) { throw java.io.IOException("ports_listener_in_use_$port", error) }
        finally { tcp?.close(); udp?.close() }
    }

    fun needsVpnConsent(mode: AppConnectionMode): Boolean = mode == AppConnectionMode.VPN
    fun validate(forwards: List<ForwardContract>) {
        require(forwards.isNotEmpty()) { "ports_select_forward" }
        require(forwards.none { it.nativeOutbound }) { "ports_native_socks_unsupported" }
        require(forwards.all { it.mode == ForwardMode.ULTRA && it.backendMode.equals("ultra", true) }) {
            "ports_strict_egress_requires_ultra_single_bond_unsupported"
        }
        require(forwards.all { it.listenPort in 1..65535 && it.target.isNotBlank() }) { "ports_invalid_forward" }
        require(forwards.map { it.listenPort }.distinct().size == forwards.size) { "ports_listener_port_conflict" }
    }
}
