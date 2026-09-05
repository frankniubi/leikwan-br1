package com.leikwan.bridge.service

import android.content.Context
import android.net.ConnectivityManager
import android.net.LinkProperties
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.util.Log
import io.nekohasekai.libbox.InterfaceUpdateListener
import java.net.NetworkInterface

/** Tracks physical networks even when Android's default network is our own VPN. */
internal class AndroidNetworkMonitor(
    context: Context,
    private val onUnderlyingNetwork: (Network?) -> Unit,
) {
    private val connectivity = context.getSystemService(ConnectivityManager::class.java)
    private val listeners = linkedSetOf<InterfaceUpdateListener>()
    private val lock = Any()
    private var registered = false
    private var selectedNetwork: Network? = null
    private val callback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) = refresh()
        override fun onLost(network: Network) = refresh()
        override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) = refresh()
        override fun onLinkPropertiesChanged(network: Network, properties: LinkProperties) = refresh()
    }

    data class PhysicalInterface(
        val network: Network,
        val name: String,
        val index: Int,
        val mtu: Int,
        val addresses: List<String>,
        val dnsServers: List<String>,
        val metered: Boolean,
        val transport: Int,
    )

    @Suppress("DEPRECATION")
    fun interfaces(): List<PhysicalInterface> = connectivity.allNetworks.mapNotNull { network ->
        val caps = connectivity.getNetworkCapabilities(network) ?: return@mapNotNull null
        if (!caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) ||
            caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) return@mapNotNull null
        val properties = connectivity.getLinkProperties(network) ?: return@mapNotNull null
        val name = properties.interfaceName?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
        val native = runCatching { NetworkInterface.getByName(name) }.getOrNull() ?: return@mapNotNull null
        if (native.index <= 0) return@mapNotNull null
        val addresses = properties.linkAddresses.mapNotNull { address ->
            address.address.hostAddress?.substringBefore('%')?.let { "$it/${address.prefixLength}" }
        }
        if (addresses.isEmpty()) return@mapNotNull null
        PhysicalInterface(
            network, name, native.index,
            properties.mtu.takeIf { it > 0 } ?: runCatching { native.mtu }.getOrDefault(1500),
            addresses, properties.dnsServers.mapNotNull { it.hostAddress?.substringBefore('%') },
            !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED),
            when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> NetworkCapabilities.TRANSPORT_ETHERNET
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> NetworkCapabilities.TRANSPORT_WIFI
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> NetworkCapabilities.TRANSPORT_CELLULAR
                else -> -1
            },
        )
    }.distinctBy { it.index }

    fun start(listener: InterfaceUpdateListener) {
        synchronized(lock) {
            if (!registered) {
                val request = NetworkRequest.Builder()
                    .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                    .addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)
                    .build()
                connectivity.registerNetworkCallback(request, callback)
                registered = true
            }
            listeners.add(listener)
        }
        refresh()
    }

    fun remove(listener: InterfaceUpdateListener) {
        synchronized(lock) {
            listeners.remove(listener)
            if (listeners.isEmpty()) close()
        }
    }

    fun close() {
        synchronized(lock) {
            listeners.clear()
            if (registered) runCatching { connectivity.unregisterNetworkCallback(callback) }
            registered = false
            selectedNetwork = null
        }
    }

    private fun refresh() {
        synchronized(lock) {
            if (!registered) return
            refreshLocked()
        }
    }

    // Serialize initial delivery, network changes, and close. libbox may call
    // getInterfaces() here; that method intentionally does not acquire lock.
    private fun refreshLocked() {
        val available = interfaces()
        val active = connectivity.activeNetwork
        val physical = available.firstOrNull { it.network == active } ?: available.minWithOrNull(
            compareBy<PhysicalInterface> { it.metered }
                .thenBy { when (it.transport) {
                    NetworkCapabilities.TRANSPORT_ETHERNET -> 0
                    NetworkCapabilities.TRANSPORT_WIFI -> 1
                    NetworkCapabilities.TRANSPORT_CELLULAR -> 2
                    else -> 3
                } }.thenBy { it.index },
        )
        val currentListeners = listeners.toList()
        val changed = selectedNetwork != physical?.network
        selectedNetwork = physical?.network
        if (changed) runCatching { onUnderlyingNetwork(physical?.network) }
            .onFailure { Log.w("AndroidNetworkMonitor", "Unable to update underlying VPN network", it) }
        currentListeners.forEach { listener ->
            if (registered && listener in listeners) {
                runCatching { listener.updateDefaultInterface(physical?.name.orEmpty(), physical?.index ?: -1, physical?.metered ?: false, false) }
                    .onFailure { Log.w("AndroidNetworkMonitor", "Unable to publish default interface", it) }
            }
        }
    }
}
