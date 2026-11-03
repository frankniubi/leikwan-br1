package com.leikwan.bridge.net

import java.io.IOException
import java.net.ConnectException
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.NoRouteToHostException
import java.net.PortUnreachableException
import java.net.Socket
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import javax.net.SocketFactory
import okhttp3.Interceptor
import okhttp3.Response

enum class PanelTransport { ETHERNET, WIFI, CELLULAR, OTHER }

/** One network as the panel binder sees it; [value] is the platform handle (Android `Network`). */
data class PanelNetworkCandidate<N>(
    val value: N,
    val hasInternet: Boolean,
    val isVpn: Boolean,
    val validated: Boolean,
    val transport: PanelTransport = PanelTransport.OTHER,
)

/** The platform view the binder needs; abstracted so selection and fallback are unit-testable. */
interface PanelConnectivity<N> {
    /** The calling app's default network, which is a VPN only when a VPN captures this app. */
    fun activeNetwork(): N?

    fun describe(network: N): PanelNetworkCandidate<N>?

    fun networks(): List<PanelNetworkCandidate<N>>

    /** An unconnected socket bound to [network]; throws when the OS refuses the binding. */
    fun createBoundSocket(network: N): Socket

    /** Starts change notifications; the returned handle stops them. Null when unavailable. */
    fun watch(onChange: () -> Unit): AutoCloseable?
}

object PanelNetworkSelection {
    /**
     * Picks the physical (non-VPN) internet network for panel traffic: validated networks
     * first, then the app's active network, then ethernet > Wi-Fi > cellular. Null when the
     * only internet path is a VPN, in which case panel traffic uses default routing.
     */
    fun <N> select(active: N?, candidates: List<PanelNetworkCandidate<N>>): N? =
        candidates
            .filter { it.hasInternet && !it.isVpn }
            .minWithOrNull(
                compareBy<PanelNetworkCandidate<N>> { !it.validated }
                    .thenBy { it.value != active }
                    .thenBy { it.transport.ordinal },
            )
            ?.value
}

/** Where panel traffic currently leaves the device, for flows that judge the client's location. */
enum class PanelEgress {
    /** Sockets are bound to a physical network, past any VPN or proxy app. */
    PHYSICAL_BOUND,

    /** No physical network to bind (or binding is cooling down); default routing, no VPN seen. */
    DEFAULT_ROUTE,

    /** Another app's VPN captures this app and binding could not bypass it: the panel sees its exit. */
    THIRD_PARTY_VPN,
}

/**
 * Binds panel control-plane sockets (and the DoH bootstrap that resolves the panel host) to a
 * physical network so another VPN/proxy app cannot change the address the panel sees.
 *
 * Never blocks sign-in: a refused binding falls back to a default-routed socket immediately,
 * and [PanelNetworkFallbackInterceptor] retries a call once on default routing when a bound
 * connect fails (a VPN without allowBypass can blackhole bound sockets). Either failure pauses
 * binding for [bindFailureCooldownMs] or until the selected network changes.
 */
class PanelNetworkBinder<N>(
    private val connectivity: PanelConnectivity<N>,
    private val ownVpnRunning: () -> Boolean,
    private val nowMs: () -> Long = System::currentTimeMillis,
    private val bindFailureCooldownMs: Long = BIND_FAILURE_COOLDOWN_MS,
    private val defaultSocketFactory: SocketFactory = SocketFactory.getDefault(),
    private val log: (String, Throwable?) -> Unit = { _, _ -> },
) {
    private enum class BindOutcome { UNKNOWN, BOUND, FAILED }

    private val lock = Any()
    private var watchHandle: AutoCloseable? = null
    @Volatile private var refreshed = false
    @Volatile private var selected: N? = null
    @Volatile private var bindBlockedUntilMs = 0L
    @Volatile private var lastOutcome = BindOutcome.UNKNOWN

    /** Hands panel OkHttp clients sockets bound to [selectedNetwork], or default sockets. */
    val socketFactory: SocketFactory = CreatingSocketFactory(::createSocket)

    fun start() {
        synchronized(lock) {
            if (watchHandle == null) {
                watchHandle = runCatching { connectivity.watch(::refresh) }
                    .onFailure { log("Unable to watch networks for panel binding", it) }
                    .getOrNull()
            }
        }
        refresh()
    }

    fun stop() {
        synchronized(lock) {
            runCatching { watchHandle?.close() }
            watchHandle = null
        }
    }

    fun refresh() {
        val next = runCatching {
            PanelNetworkSelection.select(connectivity.activeNetwork(), connectivity.networks())
        }.onFailure { log("Unable to read networks for panel binding", it) }.getOrNull()
        synchronized(lock) {
            if (next != selected) {
                // A different network deserves a fresh binding attempt.
                selected = next
                bindBlockedUntilMs = 0L
                lastOutcome = BindOutcome.UNKNOWN
            }
            refreshed = true
        }
    }

    fun selectedNetwork(): N? {
        if (!refreshed) refresh()
        return selected
    }

    /** True while new panel sockets will be bound to a physical network. */
    fun isBindingActive(): Boolean = selectedNetwork() != null && nowMs() >= bindBlockedUntilMs

    /** Records a refused binding or a failed bound connection; binding pauses until cooldown or a network change. */
    fun reportBindFailure(cause: Throwable?) {
        synchronized(lock) {
            bindBlockedUntilMs = nowMs() + bindFailureCooldownMs
            lastOutcome = BindOutcome.FAILED
        }
        log("Panel network binding failed; using default routing", cause)
    }

    /**
     * Another app's VPN is the default network for this app. Our own VPN never counts: it
     * excludes this app, so from here its default network is the physical one.
     */
    fun isThirdPartyVpnActive(): Boolean {
        if (ownVpnRunning()) return false
        return runCatching {
            val active = connectivity.activeNetwork() ?: return false
            connectivity.describe(active)?.isVpn == true
        }.getOrDefault(false)
    }

    /** The last bound panel socket succeeded on the current network, so a third-party VPN is bypassed. */
    fun vpnBypassConfirmed(): Boolean = isBindingActive() && lastOutcome == BindOutcome.BOUND

    /** Show the "another VPN is running" hint only when such a VPN is up and binding did not get around it. */
    fun shouldHintThirdPartyVpn(): Boolean = isThirdPartyVpnActive() && !vpnBypassConfirmed()

    fun egress(): PanelEgress = when {
        shouldHintThirdPartyVpn() -> PanelEgress.THIRD_PARTY_VPN
        isBindingActive() -> PanelEgress.PHYSICAL_BOUND
        else -> PanelEgress.DEFAULT_ROUTE
    }

    internal fun createSocket(): Socket {
        val network = selectedNetwork()
        if (network != null && nowMs() >= bindBlockedUntilMs) {
            try {
                return connectivity.createBoundSocket(network).also { lastOutcome = BindOutcome.BOUND }
            } catch (e: Exception) {
                reportBindFailure(e)
            } catch (e: LinkageError) {
                reportBindFailure(e)
            }
        }
        return defaultSocketFactory.createSocket()
    }

    companion object {
        const val BIND_FAILURE_COOLDOWN_MS = 60_000L
    }
}

/**
 * Innermost application interceptor for panel clients: when a call fails while connecting over
 * a bound socket, pause binding and retry exactly once on default routing. Only connect-phase
 * failures qualify, so a request the panel may already have received is never replayed.
 */
class PanelNetworkFallbackInterceptor(
    private val binder: () -> PanelNetworkBinder<*>?,
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val active = binder()?.takeIf { it.isBindingActive() } ?: return chain.proceed(request)
        return try {
            chain.proceed(request)
        } catch (e: IOException) {
            if (chain.call().isCanceled() || !isConnectPhaseFailure(e)) throw e
            active.reportBindFailure(e)
            chain.proceed(request)
        }
    }

    companion object {
        /**
         * OkHttp records earlier route attempts as suppressed exceptions; if any of them got past
         * connecting, the panel may have seen the request, so the call does not qualify.
         */
        internal fun isConnectPhaseFailure(error: Throwable): Boolean =
            failedBeforeConnecting(error) && error.suppressed.all(::failedBeforeConnecting)

        private fun failedBeforeConnecting(error: Throwable): Boolean =
            generateSequence(error) { it.cause }.take(4).any { cause ->
                cause is ConnectException ||
                    cause is NoRouteToHostException ||
                    cause is UnknownHostException ||
                    cause is PortUnreachableException ||
                    (cause is SocketTimeoutException && cause.message?.contains("connect", ignoreCase = true) == true)
            }
    }
}

/** OkHttp only calls [createSocket] with no arguments; the connecting overloads keep the contract. */
private class CreatingSocketFactory(private val create: () -> Socket) : SocketFactory() {
    override fun createSocket(): Socket = create()

    override fun createSocket(host: String, port: Int): Socket =
        connected(InetSocketAddress(host, port))

    override fun createSocket(host: String, port: Int, localHost: InetAddress, localPort: Int): Socket =
        connected(InetSocketAddress(host, port), InetSocketAddress(localHost, localPort))

    override fun createSocket(host: InetAddress, port: Int): Socket =
        connected(InetSocketAddress(host, port))

    override fun createSocket(address: InetAddress, port: Int, localAddress: InetAddress, localPort: Int): Socket =
        connected(InetSocketAddress(address, port), InetSocketAddress(localAddress, localPort))

    private fun connected(remote: InetSocketAddress, local: InetSocketAddress? = null): Socket {
        val socket = create()
        try {
            if (local != null) socket.bind(local)
            socket.connect(remote)
            return socket
        } catch (e: IOException) {
            runCatching { socket.close() }
            throw e
        }
    }
}
