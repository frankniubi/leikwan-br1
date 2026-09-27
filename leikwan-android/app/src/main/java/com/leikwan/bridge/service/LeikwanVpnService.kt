package com.leikwan.bridge.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.ConnectivityManager
import android.net.IpPrefix
import android.net.Network
import android.net.NetworkCapabilities
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import android.util.Log
import androidx.annotation.VisibleForTesting
import androidx.core.app.NotificationCompat
import com.leikwan.bridge.R
import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.shared.deviceLimitMessage
import io.nekohasekai.libbox.CommandClient
import io.nekohasekai.libbox.CommandClientHandler
import io.nekohasekai.libbox.CommandClientOptions
import io.nekohasekai.libbox.CommandServer
import io.nekohasekai.libbox.CommandServerHandler
import io.nekohasekai.libbox.ConnectionOwner
import io.nekohasekai.libbox.ConnectionEvents
import io.nekohasekai.libbox.InterfaceUpdateListener
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.LocalDNSTransport
import io.nekohasekai.libbox.LogIterator
import io.nekohasekai.libbox.NeighborUpdateListener
import io.nekohasekai.libbox.NetworkInterfaceIterator
import io.nekohasekai.libbox.OutboundGroupItemIterator
import io.nekohasekai.libbox.OutboundGroupIterator
import io.nekohasekai.libbox.OverrideOptions
import io.nekohasekai.libbox.PlatformInterface
import io.nekohasekai.libbox.RoutePrefixIterator
import io.nekohasekai.libbox.SetupOptions
import io.nekohasekai.libbox.StatusMessage
import io.nekohasekai.libbox.StringIterator
import io.nekohasekai.libbox.SystemProxyStatus
import io.nekohasekai.libbox.TunOptions
import io.nekohasekai.libbox.WIFIState
import java.net.InetAddress

private const val TAG = "LeikwanVpnService"
private const val CHANNEL_ID = "leikwan_vpn"
private const val NOTIFICATION_ID = 1

class LeikwanVpnService : VpnService(), PlatformInterface, CommandServerHandler {

    private var tunFd: ParcelFileDescriptor? = null
    private var commandServer: CommandServer? = null
    private var commandClient: CommandClient? = null
    private val networkMonitor by lazy {
        AndroidNetworkMonitor(this) { network ->
            setUnderlyingNetworks(network?.let { arrayOf(it) })
        }
    }


    companion object {
        var instance: LeikwanVpnService? = null
            private set
        var isRunning: Boolean = false
            private set
        var configJson: String? = null
        @Volatile
        var startupState: VpnStartupState = VpnStartupState.IDLE
            @VisibleForTesting internal set
        @Volatile
        var lastStartError: String? = null
            @VisibleForTesting internal set

        fun startupSnapshot(): VpnStartupSnapshot {
            return VpnStartupSnapshot(
                state = startupState,
                errorMessage = lastStartError,
            )
        }
    }

    override fun onCreate() {
        super.onCreate()
        instance = this
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == "STOP") {
            stopVpn()
            return START_NOT_STICKY
        }

        val config = configJson
        if (config == null) {
            Log.e(TAG, "No config provided")
            startupState = VpnStartupState.FAILED
            lastStartError = getString(R.string.vpn_error_no_config)
            stopSelf()
            return START_NOT_STICKY
        }

        startupState = VpnStartupState.STARTING
        lastStartError = null
        isRunning = false
        startForeground(NOTIFICATION_ID, buildNotification(getString(R.string.vpn_notification_connecting)))

        try {
            val options = SetupOptions()
            options.basePath = filesDir.absolutePath
            options.workingPath = cacheDir.absolutePath
            options.tempPath = cacheDir.absolutePath
            Libbox.setup(options)
            Libbox.checkConfig(config)

            val server = CommandServer(this, this)
            commandServer = server
            server.start()
            startCommandClientDiagnostics()
            server.startOrReloadService(config, OverrideOptions())

            isRunning = true
            startupState = VpnStartupState.RUNNING
            lastStartError = null
            updateNotification(getString(R.string.vpn_notification_connected))
            Log.i(TAG, "VPN service started with libbox")
        } catch (e: Exception) {
            startupState = VpnStartupState.FAILED
            lastStartError = e.message ?: "Failed to start VPN"
            Log.e(TAG, "Failed to start VPN", e)
            stopVpn()
        }

        return START_STICKY
    }

    private fun closeRuntime() {
        try {
            commandClient?.disconnect()
        } catch (e: Exception) {
            Log.e(TAG, "Error disconnecting CommandClient", e)
        }
        commandClient = null
        try {
            commandServer?.closeService()
        } catch (e: Exception) {
            Log.e(TAG, "Error closing libbox service", e)
        }
        runCatching { commandServer?.close() }
            .onFailure { Log.e(TAG, "Error closing CommandServer", it) }
        commandServer = null
        networkMonitor.close()
        try {
            tunFd?.close()
        } catch (e: Exception) {
            Log.e(TAG, "Error closing TUN", e)
        }
        tunFd = null
    }

    fun stopVpn() {
        closeRuntime()
        // The config contains ephemeral native SOCKS credentials. Do not keep it
        // reachable after the VPN runtime has been stopped.
        configJson = null
        isRunning = false
        if (startupState != VpnStartupState.FAILED) {
            startupState = VpnStartupState.IDLE
            lastStartError = null
        }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        closeRuntime()
        instance = null
        isRunning = false
        configJson = null
        if (startupState != VpnStartupState.FAILED) {
            startupState = VpnStartupState.IDLE
            lastStartError = null
        }
        super.onDestroy()
    }

    override fun onRevoke() {
        stopVpn()
    }

    // === PlatformInterface ===

    override fun openTun(options: TunOptions): Int {
        val plan = TunSetupPlanner.plan(
            TunOptionSnapshot(
                autoRoute = options.autoRoute,
                strictRoute = options.strictRoute,
                inet4Addresses = options.inet4Address.toRoutePrefixes(),
                inet6Addresses = options.inet6Address.toRoutePrefixes(),
                inet4Routes = options.inet4RouteAddress.toRoutePrefixes(),
                inet6Routes = options.inet6RouteAddress.toRoutePrefixes(),
                dnsServer = runCatching { options.dnsServerAddress?.value }
                    .getOrNull()
                    ?.trim()
                    ?.takeIf { it.isNotEmpty() },
                inet4RouteExcludes = options.inet4RouteExcludeAddress.toRoutePrefixes(),
                inet6RouteExcludes = options.inet6RouteExcludeAddress.toRoutePrefixes(),
            ),
            ownerPackageName = packageName,
        )
        val builder = Builder()
            .setConfigureIntent(mainActivityPendingIntent())
            .setSession(getString(R.string.app_name))
            .setMtu(options.mtu)

        if (plan.bindActiveNetwork) {
            resolveUnderlyingNetwork()?.let { network ->
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP_MR1) {
                    builder.setUnderlyingNetworks(arrayOf(network))
                }
            }
        }
        plan.inet4Addresses.forEach { route -> builder.addAddress(route.address, route.prefix) }
        plan.inet6Addresses.forEach { route -> builder.addAddress(route.address, route.prefix) }
        plan.inet4Routes.forEach { route -> builder.addRoute(route.address, route.prefix) }
        plan.inet6Routes.forEach { route -> builder.addRoute(route.address, route.prefix) }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            plan.inet4RouteExcludes.forEach { route ->
                builder.excludeRoute(IpPrefix(InetAddress.getByName(route.address), route.prefix))
            }
            plan.inet6RouteExcludes.forEach { route ->
                builder.excludeRoute(IpPrefix(InetAddress.getByName(route.address), route.prefix))
            }
        } else if (plan.inet4RouteExcludes.isNotEmpty() || plan.inet6RouteExcludes.isNotEmpty()) {
            // Android added per-route exclusions in API 33. The app UID is already
            // excluded from its own VPN on older releases, so keep the config usable
            // while retaining the route-direct guard in sing-box.
            Log.w(TAG, "TUN route exclusions require API 33 or newer")
        }
        plan.dnsServers.forEach { dns -> builder.addDnsServer(dns) }
        plan.disallowedPackageNames.forEach { packageName ->
            builder.addDisallowedApplication(packageName)
            Log.i(TAG, "Excluded VPN owner package from captured traffic: $packageName")
        }

        val replacement = builder.establish() ?: throw Exception("VPN permission not granted")
        val previous = tunFd
        tunFd = replacement
        // libbox owns a dup of the descriptor, not this Android-side handle.
        runCatching { previous?.close() }.onFailure { Log.w(TAG, "Error closing replaced TUN", it) }
        Log.i(TAG, "TUN interface established, fd=${tunFd!!.fd}")
        return tunFd!!.fd
    }

    override fun useProcFS(): Boolean = false

    override fun findConnectionOwner(
        ipProtocol: Int,
        sourceAddress: String?,
        sourcePort: Int,
        destinationAddress: String?,
        destinationPort: Int,
    ): ConnectionOwner {
        return ConnectionOwner()
    }

    override fun readWIFIState(): WIFIState? = null

    override fun startDefaultInterfaceMonitor(listener: InterfaceUpdateListener?) {
        if (listener != null) networkMonitor.start(listener)
    }

    override fun closeDefaultInterfaceMonitor(listener: InterfaceUpdateListener?) {
        if (listener != null) networkMonitor.remove(listener)
    }

    override fun startNeighborMonitor(listener: NeighborUpdateListener?) {}

    override fun closeNeighborMonitor(listener: NeighborUpdateListener?) {}

    override fun getInterfaces(): NetworkInterfaceIterator {
        val items = networkMonitor.interfaces().map { physical ->
            io.nekohasekai.libbox.NetworkInterface().apply {
                name = physical.name
                index = physical.index
                mtu = physical.mtu.coerceAtLeast(1280)
                flags = 1 or 64 // IFF_UP | IFF_RUNNING, not java.net flag ordinals.
                addresses = stringIterator(physical.addresses)
                dnsServer = stringIterator(physical.dnsServers)
                metered = physical.metered
                type = when (physical.transport) {
                    NetworkCapabilities.TRANSPORT_WIFI -> Libbox.InterfaceTypeWIFI
                    NetworkCapabilities.TRANSPORT_CELLULAR -> Libbox.InterfaceTypeCellular
                    NetworkCapabilities.TRANSPORT_ETHERNET -> Libbox.InterfaceTypeEthernet
                    else -> Libbox.InterfaceTypeOther
                }
            }
        }.iterator()
        return object : NetworkInterfaceIterator {
            override fun hasNext() = items.hasNext()
            override fun next(): io.nekohasekai.libbox.NetworkInterface = items.next()
        }
    }

    private fun stringIterator(values: List<String>): StringIterator {
        val items = values.iterator()
        return object : StringIterator {
            override fun hasNext() = items.hasNext()
            override fun next(): String = items.next()
            override fun len(): Int = values.size
        }
    }

    override fun underNetworkExtension(): Boolean = false

    override fun includeAllNetworks(): Boolean = false

    override fun clearDNSCache() {}

    override fun autoDetectInterfaceControl(fd: Int) {
        if (!protect(fd)) throw java.io.IOException("protect_socket_failed")
    }

    override fun usePlatformAutoDetectInterfaceControl(): Boolean = true

    override fun localDNSTransport(): LocalDNSTransport? = null

    override fun registerMyInterface(name: String?) {}

    override fun sendNotification(notification: io.nekohasekai.libbox.Notification?) {}

    override fun systemCertificates(): StringIterator? = null

    // === CommandServerHandler ===

    override fun serviceReload() {
        val config = configJson ?: return
        try {
            commandServer?.startOrReloadService(config, OverrideOptions())
        } catch (e: Exception) {
            Log.e(TAG, "Service reload failed", e)
        }
    }

    override fun serviceStop() {
        stopVpn()
    }

    override fun getSystemProxyStatus(): SystemProxyStatus? = null

    override fun setSystemProxyEnabled(enabled: Boolean) {}

    override fun triggerNativeCrash() {}

    override fun writeDebugMessage(message: String?) {
        Log.d(TAG, "debug: $message")
    }

    // === Notification ===

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                getString(R.string.vpn_notification_channel_name),
                NotificationManager.IMPORTANCE_LOW,
            ).apply { description = getString(R.string.vpn_notification_channel_description) }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }

    /**
     * Shows (or, with null, withdraws) the device-limit refusal on the ongoing VPN notification so
     * the user sees it without opening the app. The full guidance goes in the expanded text.
     */
    fun showDeviceLimitNotice(notice: DeviceLimitNotice?) {
        if (!isRunning) return
        if (notice == null) {
            updateNotification(getString(R.string.vpn_notification_connected))
        } else {
            updateNotification(getString(R.string.vpn_notification_device_limit), deviceLimitMessage(notice))
        }
    }

    private fun buildNotification(status: String, detail: String? = null): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pending = PendingIntent.getActivity(this, 0, intent, PendingIntent.FLAG_IMMUTABLE)

        val stopIntent = Intent(this, LeikwanVpnService::class.java).apply { action = "STOP" }
        val stopPending = PendingIntent.getService(this, 1, stopIntent, PendingIntent.FLAG_IMMUTABLE)

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(status)
            .apply { detail?.let { setStyle(NotificationCompat.BigTextStyle().bigText(it)) } }
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentIntent(pending)
            .addAction(android.R.drawable.ic_media_pause, getString(R.string.vpn_notification_disconnect), stopPending)
            .setOngoing(true)
            .build()
    }

    private fun updateNotification(status: String, detail: String? = null) {
        getSystemService(NotificationManager::class.java)
            .notify(NOTIFICATION_ID, buildNotification(status, detail))
    }

    private fun mainActivityPendingIntent(): PendingIntent {
        val intent = Intent(this, MainActivity::class.java)
        return PendingIntent.getActivity(
            this,
            2,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    private fun resolveUnderlyingNetwork(): Network? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.LOLLIPOP_MR1) return null
        val connectivity = getSystemService(ConnectivityManager::class.java) ?: return null
        val candidates = connectivity.allNetworks.mapNotNull { network ->
            val capabilities = connectivity.getNetworkCapabilities(network) ?: return@mapNotNull null
            UnderlyingNetworkCandidate(
                value = network,
                isVpn = capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN),
                hasInternet = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET),
            )
        }
        return UnderlyingNetworkSelector.select(connectivity.activeNetwork, candidates)
    }

    private fun RoutePrefixIterator?.toRoutePrefixes(): List<RoutePrefix> {
        if (this == null) return emptyList()
        val routes = mutableListOf<RoutePrefix>()
        while (hasNext()) {
            val route = next()
            routes += RoutePrefix(route.address(), route.prefix())
        }
        return routes
    }

    private fun startCommandClientDiagnostics() {
        val options = CommandClientOptions().apply {
            addCommand(Libbox.CommandLog)
            addCommand(Libbox.CommandConnections)
            addCommand(Libbox.CommandStatus)
        }
        runCatching {
            CommandClient(BoxDiagnosticsHandler(), options).also { client ->
                client.connect()
                commandClient = client
            }
        }.onFailure { error ->
            Log.w(TAG, "CommandClient diagnostics unavailable", error)
        }
    }

    private class BoxDiagnosticsHandler : CommandClientHandler {
        override fun connected() {
            Log.i(TAG, "box command client connected")
        }

        override fun disconnected(message: String?) {
            Log.i(TAG, "box command client disconnected: $message")
        }

        override fun clearLogs() {
            Log.d(TAG, "box logs cleared")
        }

        override fun setDefaultLogLevel(level: Int) {
            Log.d(TAG, "box default log level=$level")
        }

        override fun writeLogs(iterator: LogIterator?) {
            var emitted = 0
            while (iterator?.hasNext() == true && emitted < 80) {
                val entry = iterator.next()
                Log.d(TAG, "box log[${entry.level}]: ${entry.message}")
                emitted += 1
            }
            if (iterator?.hasNext() == true) {
                Log.d(TAG, "box log batch truncated")
            }
        }

        override fun writeConnectionEvents(events: ConnectionEvents?) {
            val iterator = events?.iterator() ?: return
            var emitted = 0
            while (iterator.hasNext() && emitted < 80) {
                val event = iterator.next()
                val connection = event.connection
                Log.d(
                    TAG,
                    "box conn type=${event.type} id=${event.id} " +
                        "network=${connection?.network} dest=${connection?.displayDestination()} " +
                        "domain=${connection?.domain} outbound=${connection?.outbound} " +
                        "chain=${connection?.chain()?.toListForLog()}",
                )
                emitted += 1
            }
            if (iterator.hasNext()) {
                Log.d(TAG, "box connection batch truncated")
            }
        }

        override fun writeStatus(message: StatusMessage?) {
            if (message == null) return
            Log.d(
                TAG,
                "box status in=${message.connectionsIn} out=${message.connectionsOut} " +
                    "up=${message.uplinkTotal} down=${message.downlinkTotal}",
            )
        }

        override fun writeGroups(iterator: OutboundGroupIterator?) {
            var emitted = 0
            while (iterator?.hasNext() == true && emitted < 40) {
                val group = iterator.next()
                Log.d(TAG, "box group tag=${group.tag} type=${group.type} selected=${group.selected}")
                emitted += 1
            }
        }

        override fun writeOutbounds(iterator: OutboundGroupItemIterator?) {
            var emitted = 0
            while (iterator?.hasNext() == true && emitted < 40) {
                val outbound = iterator.next()
                Log.d(TAG, "box outbound tag=${outbound.tag} type=${outbound.type} delay=${outbound.urlTestDelay}")
                emitted += 1
            }
        }

        override fun initializeClashMode(modeList: StringIterator?, currentMode: String?) {
            Log.d(TAG, "box clash modes=${modeList.toListForLog()} current=$currentMode")
        }

        override fun updateClashMode(newMode: String?) {
            Log.d(TAG, "box clash mode=$newMode")
        }

        private fun StringIterator?.toListForLog(): List<String> {
            if (this == null) return emptyList()
            val values = mutableListOf<String>()
            while (hasNext() && values.size < 16) {
                values += next()
            }
            return values
        }
    }
}
