package com.leikwan.bridge.net

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.util.Log
import com.leikwan.bridge.service.LeikwanVpnService
import java.net.InetAddress
import java.net.Proxy
import java.net.Socket
import javax.net.SocketFactory
import okhttp3.OkHttpClient

/**
 * Process-wide entry point for panel egress: the installed [PanelNetworkBinder], the socket
 * factory panel/DoH clients use, and third-party VPN detection for error hints and a later
 * location check. Everything degrades to default routing when nothing is installed.
 */
object PanelNetwork {
    private const val TAG = "PanelNetwork"

    @Volatile
    private var installed: PanelNetworkBinder<*>? = null

    /** Delegates to the installed binder at socket-creation time, so clients built earlier still bind. */
    val socketFactory: SocketFactory = object : SocketFactory() {
        private fun target(): SocketFactory = installed?.socketFactory ?: SocketFactory.getDefault()
        override fun createSocket(): Socket = target().createSocket()
        override fun createSocket(host: String, port: Int): Socket = target().createSocket(host, port)
        override fun createSocket(host: String, port: Int, localHost: InetAddress, localPort: Int): Socket =
            target().createSocket(host, port, localHost, localPort)
        override fun createSocket(host: InetAddress, port: Int): Socket = target().createSocket(host, port)
        override fun createSocket(
            address: InetAddress,
            port: Int,
            localAddress: InetAddress,
            localPort: Int,
        ): Socket = target().createSocket(address, port, localAddress, localPort)
    }

    /** Idempotent; safe to call from Application and from any panel client builder. */
    fun ensureInstalled(context: Context): PanelNetworkBinder<*>? {
        installed?.let { return it }
        return synchronized(this) {
            installed ?: runCatching {
                PanelNetworkBinder(
                    connectivity = AndroidPanelConnectivity(context.applicationContext),
                    ownVpnRunning = { LeikwanVpnService.isRunning },
                    log = { message, error -> Log.w(TAG, message, error) },
                ).also { binder ->
                    binder.start()
                    installed = binder
                }
            }.onFailure { Log.w(TAG, "Panel network binding unavailable", it) }.getOrNull()
        }
    }

    fun current(): PanelNetworkBinder<*>? = installed

    fun isThirdPartyVpnActive(): Boolean = installed?.isThirdPartyVpnActive() ?: false

    fun shouldHintThirdPartyVpn(): Boolean = installed?.shouldHintThirdPartyVpn() ?: false

    /** For a location-check flow: [PanelEgress.THIRD_PARTY_VPN] means the panel sees another app's exit. */
    fun egress(): PanelEgress = installed?.egress() ?: PanelEgress.DEFAULT_ROUTE

    fun isEgressTrustworthyForLocation(): Boolean = egress() != PanelEgress.THIRD_PARTY_VPN

    /**
     * Panel control-plane clients ignore any system/VPN-advertised HTTP proxy and, when a binder
     * is present, open sockets on the physical network it selected.
     */
    fun applyTo(builder: OkHttpClient.Builder, binder: PanelNetworkBinder<*>?): OkHttpClient.Builder {
        builder.proxy(Proxy.NO_PROXY)
        if (binder != null) builder.socketFactory(binder.socketFactory)
        return builder
    }

    /** Drops the installed binder; tests only. */
    internal fun resetForTest(binder: PanelNetworkBinder<*>? = null) {
        synchronized(this) {
            installed?.stop()
            installed = binder
        }
    }
}

internal class AndroidPanelConnectivity(context: Context) : PanelConnectivity<Network> {
    private val connectivity = context.getSystemService(ConnectivityManager::class.java)

    override fun activeNetwork(): Network? = connectivity?.activeNetwork

    override fun describe(network: Network): PanelNetworkCandidate<Network>? {
        val caps = connectivity?.getNetworkCapabilities(network) ?: return null
        return PanelNetworkCandidate(
            value = network,
            hasInternet = caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET),
            isVpn = caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN),
            validated = caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED),
            transport = when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> PanelTransport.ETHERNET
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> PanelTransport.WIFI
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> PanelTransport.CELLULAR
                else -> PanelTransport.OTHER
            },
        )
    }

    @Suppress("DEPRECATION")
    override fun networks(): List<PanelNetworkCandidate<Network>> =
        connectivity?.allNetworks.orEmpty().mapNotNull(::describe)

    // Network.socketFactory binds each created socket; a VPN that forbids bypass makes that throw.
    override fun createBoundSocket(network: Network): Socket = network.socketFactory.createSocket()

    override fun watch(onChange: () -> Unit): AutoCloseable? {
        val manager = connectivity ?: return null
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) = onChange()
            override fun onLost(network: Network) = onChange()
            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) = onChange()
        }
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)
            .build()
        manager.registerNetworkCallback(request, callback)
        return AutoCloseable { runCatching { manager.unregisterNetworkCallback(callback) } }
    }
}
