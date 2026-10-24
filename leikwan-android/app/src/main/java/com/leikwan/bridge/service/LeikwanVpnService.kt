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
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.util.Log
import androidx.annotation.VisibleForTesting
import androidx.core.app.NotificationCompat
import com.leikwan.bridge.R
import com.leikwan.bridge.data.Prefs
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
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val TAG = "LeikwanVpnService"
private const val CHANNEL_ID = "leikwan_vpn"
private const val NOTIFICATION_ID = 1
private const val RECOVERY_FAILED_NOTIFICATION_ID = 2

class LeikwanVpnService : VpnService(), PlatformInterface, CommandServerHandler {

    private var tunFd: ParcelFileDescriptor? = null
    private var commandServer: CommandServer? = null
    private var commandClient: CommandClient? = null
    private val networkMonitor by lazy {
        AndroidNetworkMonitor(this) { network ->
            setUnderlyingNetworks(network?.let { arrayOf(it) })
        }
    }
    private val mainHandler = Handler(Looper.getMainLooper())
    private val recoveryScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var recoveryJob: Job? = null


    companion object {
        var instance: LeikwanVpnService? = null
            private set
        var isRunning: Boolean = false
            private set
        var configJson: String? = null
        /** True while this service rebuilds a connection Android killed (see [VpnRecovery]). */
        @Volatile
        var recovering: Boolean = false
            private set
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
            // Disconnected from the notification: the tunnel must not come back on its own.
            runCatching { Prefs.get(this).vpnConnectIntent = false }
            recovering = false
            stopVpn()
            return START_NOT_STICKY
        }

        val config = configJson
        if (config == null) {
            if (recovering) return START_STICKY
            if (VpnRecovery.shouldRecover(intent, connectIntentSet())) return recoverConnection()
            Log.e(TAG, "No config provided")
            startupState = VpnStartupState.FAILED
            lastStartError = getString(R.string.vpn_error_no_config)
            stopSelf()
            return START_NOT_STICKY
        }

        startRuntime(config)
        return START_STICKY
    }

    /**
     * Android restarted this service (sticky restart, always-on VPN or boot) without the config,
     * which lived in the killed process. Stay in the foreground and rebuild the user's last
     * connection through the orchestrator; its config is started in place by [startRecoveredConfig].
     */
    private fun recoverConnection(): Int {
        if (prepare(this) != null) {
            // VPN consent was revoked; only the app can ask for it again.
            runCatching { Prefs.get(this).vpnConnectIntent = false }
            showRecoveryFailed(getString(R.string.vpn_recovery_needs_permission))
            stopSelf()
            return START_NOT_STICKY
        }
        try {
            startForeground(NOTIFICATION_ID, buildNotification(getString(R.string.vpn_notification_reconnecting)))
        } catch (e: Exception) {
            // Android 12+ refuses a background foreground start unless the app is exempt from
            // battery optimization; the user has to reconnect from the app.
            Log.w(TAG, "Cannot return to the foreground to reconnect", e)
            showRecoveryFailed(getString(R.string.vpn_recovery_background_blocked))
            stopSelf()
            return START_NOT_STICKY
        }
        recovering = true
        startupState = VpnStartupState.IDLE
        lastStartError = null
        Log.i(TAG, "Rebuilding the connection after the service was restarted")
        recoveryJob = recoveryScope.launch {
            var failure: Exception? = null
            for (attempt in 1..VpnRecovery.ATTEMPTS) {
                if (attempt > 1) delay(VpnRecovery.RETRY_DELAYS_MS[attempt - 2])
                if (!recovering) return@launch
                // The user disconnected in the app meanwhile.
                if (!connectIntentSet()) break
                try {
                    TunnelOrchestrator.forApplication(applicationContext).reconnectSaved()
                    return@launch
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    failure = e
                    Log.w(TAG, "Reconnect attempt $attempt failed", e)
                }
            }
            mainHandler.post {
                if (!recovering) return@post
                recovering = false
                if (connectIntentSet()) showRecoveryFailed(failure?.message ?: getString(R.string.vpn_error_no_config))
                stopVpn()
            }
        }
        return START_STICKY
    }

    private fun connectIntentSet(): Boolean = runCatching { Prefs.get(this).vpnConnectIntent }.getOrDefault(false)

    /**
     * Starts the rebuilt config in this service while it recovers; false when not recovering, and
     * the caller then starts the service the usual way. Android may refuse a second background
     * foreground start, which the running foreground service does not need.
     */
    fun startRecoveredConfig(): Boolean {
        if (!recovering) return false
        val config = configJson ?: return false
        startupState = VpnStartupState.STARTING
        lastStartError = null
        mainHandler.post { startRuntime(config) }
        return true
    }

    private fun startRuntime(config: String) {
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
            // A failed start keeps recovering set, so the stop below leaves the service in the
            // foreground for the next rebuild attempt.
            recovering = false
            getSystemService(NotificationManager::class.java)?.cancel(RECOVERY_FAILED_NOTIFICATION_ID)
            TrafficMonitor.onStarted()
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
        TrafficMonitor.onStopped()
        // The orchestrator stops the old tunnel before every rebuild; while recovering, the
        // service stays in the foreground so the rebuilt config can start in place.
        if (recovering) return
        recoveryJob?.cancel()
        recoveryJob = null
        if (startupState != VpnStartupState.FAILED) {
            startupState = VpnStartupState.IDLE
            lastStartError = null
        }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        recovering = false
        recoveryScope.cancel()
        TrafficMonitor.onStopped()
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
        // Another VPN took over or the user turned this one off in system settings.
        runCatching { Prefs.get(this).vpnConnectIntent = false }
        recovering = false
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
                includePackages = options.includePackage.toList(),
                excludePackages = options.excludePackage.toList(),
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
        // Apps that are not installed are skipped; Android rejects unknown packages.
        val allowed = plan.allowedPackageNames.count { packageName ->
            runCatching { builder.addAllowedApplication(packageName) }.isSuccess
        }
        if (plan.allowedPackageNames.isNotEmpty()) {
            Log.i(TAG, "Per-app VPN: $allowed of ${plan.allowedPackageNames.size} selected apps use the VPN")
        }
        if (allowed == 0) {
            // No selected app is installed (or every app uses the VPN): capture all apps but
            // keep this app, and its runtimes, outside its own VPN.
            val disallowed = plan.disallowedPackageNames.ifEmpty { listOf(packageName) }
            disallowed.forEach { excluded ->
                runCatching { builder.addDisallowedApplication(excluded) }
                    .onFailure { Log.w(TAG, "Cannot exclude $excluded from the VPN", it) }
            }
            Log.i(TAG, "Per-app VPN: ${disallowed.size} apps excluded from captured traffic")
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

    /** Owning app of a connection, for sing-box `package_name` rules (Android 10+). */
    override fun findConnectionOwner(
        ipProtocol: Int,
        sourceAddress: String?,
        sourcePort: Int,
        destinationAddress: String?,
        destinationPort: Int,
    ): ConnectionOwner {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q || sourceAddress == null || destinationAddress == null) {
            throw java.io.IOException("connection_owner_unavailable")
        }
        val connectivity = getSystemService(ConnectivityManager::class.java)
            ?: throw java.io.IOException("connection_owner_unavailable")
        val uid = connectivity.getConnectionOwnerUid(
            ipProtocol,
            java.net.InetSocketAddress(sourceAddress, sourcePort),
            java.net.InetSocketAddress(destinationAddress, destinationPort),
        )
        if (uid == android.os.Process.INVALID_UID) throw java.io.IOException("connection_owner_not_found")
        val packages = packageManager.getPackagesForUid(uid)?.toList().orEmpty()
        return ConnectionOwner().apply {
            userId = uid
            setAndroidPackageNames(stringIterator(packages))
        }
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

    private fun StringIterator?.toList(): List<String> {
        if (this == null) return emptyList()
        val values = mutableListOf<String>()
        while (hasNext()) values += next()
        return values
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

    /** A rebuild after Android killed the VPN did not succeed; tapping opens the app to reconnect. */
    private fun showRecoveryFailed(reason: String) {
        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.vpn_recovery_failed_title))
            .setContentText(reason)
            .setStyle(NotificationCompat.BigTextStyle().bigText(getString(R.string.vpn_recovery_failed_body, reason)))
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentIntent(mainActivityPendingIntent())
            .setAutoCancel(true)
            .build()
        runCatching {
            getSystemService(NotificationManager::class.java).notify(RECOVERY_FAILED_NOTIFICATION_ID, notification)
        }.onFailure { Log.w(TAG, "Cannot post the reconnect failure", it) }
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
            // Go time.Duration: one dashboard sample per second.
            statusInterval = 1_000_000_000L
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
            TrafficMonitor.report(
                uplink = message.uplink,
                downlink = message.downlink,
                uplinkTotal = message.uplinkTotal,
                downlinkTotal = message.downlinkTotal,
                connections = message.connectionsOut,
            )
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
