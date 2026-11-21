package com.leikwan.bridge.net

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import java.io.FileDescriptor
import java.io.IOException
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Socket
import javax.net.SocketFactory
import okhttp3.ConnectionPool
import okhttp3.Dns

/** PORTS never uses a VPN, the process default route, or a different transport as fallback. */
enum class PortNetworkChoice { AUTO, WIFI, CELLULAR, ETHERNET }

object DirectNetworkSelection {
    fun <N> select(choice: PortNetworkChoice, active: N?, candidates: List<PanelNetworkCandidate<N>>): N? {
        val transport = when (choice) {
            PortNetworkChoice.AUTO -> null
            PortNetworkChoice.WIFI -> PanelTransport.WIFI
            PortNetworkChoice.CELLULAR -> PanelTransport.CELLULAR
            PortNetworkChoice.ETHERNET -> PanelTransport.ETHERNET
        }
        return PanelNetworkSelection.select(active, candidates.filter {
            it.transport != PanelTransport.OTHER && (transport == null || it.transport == transport)
        })
    }
}

/** Shared strict egress for official panel requests, scoped DNS and the core's actual socket FDs. */
object DirectPortNetwork {
    private val lock = Any()
    @Volatile var required = false
        private set
    @Volatile var enabled = false
        private set
    private var manager: ConnectivityManager? = null
    private var connectivity: AndroidPanelConnectivity? = null
    private var callback: ConnectivityManager.NetworkCallback? = null
    private var choice = PortNetworkChoice.AUTO
    private var selected: Network? = null
    private var signature = ""
    private var onChange: (() -> Unit)? = null
    @Volatile var generation = 0L
        private set
    // All panel clients use this pool, including ones made before entering PORTS.
    val connectionPool = ConnectionPool()
    val dispatcher = okhttp3.Dispatcher()

    fun start(context: Context, choice: PortNetworkChoice, changed: (() -> Unit)? = null) {
        synchronized(lock) {
            required = true
            if (enabled && this.choice == choice) {
                if (changed != null) onChange = changed
                return
            }
            if (enabled) stop(resetRequired = false)
            required = true
            manager = context.getSystemService(ConnectivityManager::class.java)
                ?: throw IOException("ports_connectivity_unavailable")
            connectivity = AndroidPanelConnectivity(context)
            this.choice = choice
            onChange = changed
            enabled = true
            dispatcher.cancelAll()
            connectionPool.evictAll()
            AppDns.resetPools()
            callback = object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) = refresh()
                override fun onLost(network: Network) = refresh()
                override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) = refresh()
                override fun onLinkPropertiesChanged(network: Network, properties: android.net.LinkProperties) = refresh()
            }.also {
                try {
                    manager!!.registerNetworkCallback(NetworkRequest.Builder()
                        .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                        .addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN).build(), it)
                } catch (error: Exception) {
                    stop(resetRequired = false)
                    required = true
                    throw error
                }
            }
        }
        refresh()
    }

    private fun refresh() {
        val changed = synchronized(lock) {
            if (!enabled) return
            val view = connectivity ?: return
            val next = DirectNetworkSelection.select(choice, view.activeNetwork(), view.networks())
            val properties = next?.let { manager?.getLinkProperties(it) }
            val nextSignature = "$next:${properties?.interfaceName}:${properties?.dnsServers}"
            if (next == selected && signature == nextSignature) return
            selected = next
            signature = nextSignature
            generation++
            dispatcher.cancelAll()
            connectionPool.evictAll()
            AppDns.resetPools()
            onChange
        }
        changed?.invoke()
    }

    fun stop(resetRequired: Boolean = true) {
        synchronized(lock) {
            callback?.let { runCatching { manager?.unregisterNetworkCallback(it) } }
            callback = null
            selected = null
            signature = ""
            enabled = false
            if (resetRequired) required = false
            onChange = null
            generation++
            dispatcher.cancelAll()
            connectionPool.evictAll()
            AppDns.resetPools()
        }
    }

    private fun requireNetwork(): Network {
        if (!enabled) throw IOException("ports_network_not_started")
        val network = selected ?: throw IOException("ports_physical_network_unavailable")
        val caps = manager?.getNetworkCapabilities(network)
            ?: throw IOException("ports_physical_network_lost")
        if (caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN)
            || !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)) {
            throw IOException("ports_vpn_network_forbidden")
        }
        return network
    }

    fun requireAvailable() = synchronized(lock) { requireNetwork(); Unit }

    fun dnsServers(): List<String> = synchronized(lock) {
        val network = requireNetwork()
        val servers = manager?.getLinkProperties(network)?.dnsServers.orEmpty().mapNotNull { address ->
            address.hostAddress?.substringBefore('%')?.let { if (it.contains(':')) "[$it]:53" else "$it:53" }
        }
        if (servers.isEmpty()) throw IOException("ports_network_dns_unavailable")
        servers
    }

    val dns = object : Dns {
        override fun lookup(hostname: String): List<InetAddress> = synchronized(lock) { requireNetwork().getAllByName(hostname).toList() }
    }

    fun bindDescriptor(fd: FileDescriptor, expectedGeneration: Long = generation) = synchronized(lock) {
        if (generation != expectedGeneration) throw IOException("ports_stale_network_generation")
        // Android's public API fails for lockdown VPN/device policy; never acknowledge that as success.
        requireNetwork().bindSocket(fd)
    }

    fun detachService() = synchronized(lock) { onChange = null }

    val socketFactory: SocketFactory = object : SocketFactory() {
        override fun createSocket(): Socket = synchronized(lock) { requireNetwork().socketFactory.createSocket() }
        override fun createSocket(host: String, port: Int): Socket = connect(dns.lookup(host).first(), port)
        override fun createSocket(host: String, port: Int, local: InetAddress, localPort: Int): Socket =
            connect(dns.lookup(host).first(), port, local, localPort)
        override fun createSocket(host: InetAddress, port: Int): Socket = connect(host, port)
        override fun createSocket(host: InetAddress, port: Int, local: InetAddress, localPort: Int): Socket =
            connect(host, port, local, localPort)
        private fun connect(host: InetAddress, port: Int, local: InetAddress? = null, localPort: Int = 0): Socket {
            val socket = createSocket()
            try {
                if (local != null) socket.bind(InetSocketAddress(local, localPort))
                socket.connect(InetSocketAddress(host, port))
                return socket
            } catch (error: Exception) { socket.close(); throw error }
        }
    }
}
