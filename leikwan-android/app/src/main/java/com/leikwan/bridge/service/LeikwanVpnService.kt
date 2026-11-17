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
import com.leikwan.bridge.diagnostics.VpnEventJournal
import com.leikwan.bridge.diagnostics.DiagnosticLogBudget
import com.leikwan.bridge.diagnostics.ResourceSnapshot
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
import java.util.concurrent.atomic.AtomicBoolean
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.launch

private const val TAG = "LeikwanVpnService"
private const val CHANNEL_ID = "leikwan_vpn"
private const val NOTIFICATION_ID = 1
private const val RECOVERY_FAILED_NOTIFICATION_ID = 2
/** Resource journal cadence: one short line, cheap enough for every running tunnel. */
private const val RESOURCE_SAMPLE_INTERVAL_MS = 60_000L
/** Journal every fifth sample (5 min) so lifecycle history survives the 64 KiB rotation. */
private const val RESOURCE_JOURNAL_EVERY = 5
/** App fd ceiling on Android 9+ is 32768; used only when /proc/self/limits is unreadable. */
private const val DEFAULT_FD_LIMIT = 32_768

class LeikwanVpnService : VpnService(), PlatformInterface, CommandServerHandler {

    private var tunFd: ParcelFileDescriptor? = null
    private var commandServer: CommandServer? = null
    private var commandClient: CommandClient? = null
    private var relayLatencyJob: Job? = null
    private var resourceJournalJob: Job? = null
    /** One self-heal attempt per process: a rebuild cannot fix a leak that outlives it. */
    private val fdGuardTripped = AtomicBoolean(false)
    private val networkMonitor by lazy {
        AndroidNetworkMonitor(this, onUnderlyingNetwork = { network ->
            applyUnderlyingNetworks(network)
        })
    }
    private val mainHandler = Handler(Looper.getMainLooper())
    private val recoveryScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var recoveryJob: Job? = null
    /** Wi-Fi <-> cellular switch recovery (see [onDefaultInterfaceChanged]). */
    private var switchRecoveryJob: Job? = null
    @Volatile private var lastDefaultInterfaceName: String? = null


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
            stopVpn("notification")
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
        Log.i(TAG, "Rebuilding the connection after the service was restarted")
        rebuildInPlace("service restarted")
        return START_STICKY
    }

    /**
     * Rebuilds the user's last connection while this service stays in the foreground. With
     * [recovering] set, the orchestrator's stop of the old tunnel keeps the service alive and
     * [startRecoveredConfig] starts the rebuilt config in place, so Android is never asked for a
     * background foreground-service start. Runs as [recoveryJob], never inside
     * [switchRecoveryJob]: [stopVpn] cancels that job, so a rebuild running there cancelled itself
     * right after stopping the tunnel and left the VPN off until the user reconnected.
     */
    private fun rebuildInPlace(reason: String) {
        recovering = true
        startupState = VpnStartupState.IDLE
        lastStartError = null
        VpnEventJournal.record(this, "rebuild start: $reason")
        recoveryJob?.cancel()
        recoveryJob = recoveryScope.launch {
            val outcome = VpnRecovery.runRebuildAttempts(
                reconnect = { TunnelOrchestrator.forApplication(applicationContext).reconnectSaved() },
                stillRecovering = { recovering },
                connectIntent = ::connectIntentSet,
                tunnelStillUp = { isRunning },
                onAttemptFailed = { attempt, error ->
                    Log.w(TAG, "Reconnect attempt $attempt failed", error)
                    VpnEventJournal.record(this@LeikwanVpnService, "rebuild attempt $attempt failed: ${error.message}")
                },
            )
            VpnEventJournal.record(this@LeikwanVpnService, "rebuild end: $outcome")
            when (outcome) {
                RebuildOutcome.Reconnected, RebuildOutcome.CalledOff -> Unit
                is RebuildOutcome.KeptCurrent -> mainHandler.post {
                    if (!recovering) return@post
                    recovering = false
                    if (isRunning) {
                        updateNotification(getString(R.string.vpn_notification_connected))
                        return@post
                    }
                    // The kept tunnel stopped meanwhile; do not linger in the foreground without it.
                    if (connectIntentSet()) {
                        showRecoveryFailed(outcome.failure.message ?: getString(R.string.vpn_error_no_config))
                    }
                    stopVpn("kept tunnel stopped")
                }
                is RebuildOutcome.GaveUp -> mainHandler.post {
                    if (!recovering) return@post
                    recovering = false
                    if (connectIntentSet()) {
                        showRecoveryFailed(outcome.failure?.message ?: getString(R.string.vpn_error_no_config))
                    }
                    stopVpn("rebuild gave up")
                }
            }
        }
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

            // A rebuild can reach here while an earlier server object is still referenced;
            // replacing it without closing leaks the native service and its gRPC streams.
            closeCommandChannel()
            val server = CommandServer(this, this)
            commandServer = server
            server.start()
            startCommandClientDiagnostics()
            startRelayLatencyProbe()
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
            VpnEventJournal.record(this, "vpn started")
            journalResources("vpn started")
            startResourceJournal()
        } catch (e: Exception) {
            startupState = VpnStartupState.FAILED
            lastStartError = e.message ?: "Failed to start VPN"
            Log.e(TAG, "Failed to start VPN", e)
            VpnEventJournal.record(this, "vpn start failed: ${e.message}")
            stopVpn("start failed")
        }
    }

    private fun closeRuntime() {
        relayLatencyJob?.cancel()
        relayLatencyJob = null
        resourceJournalJob?.cancel()
        resourceJournalJob = null
        closeCommandChannel()
        networkMonitor.close()
        try {
            tunFd?.close()
        } catch (e: Exception) {
            Log.e(TAG, "Error closing TUN", e)
        }
        tunFd = null
    }

    private fun closeCommandChannel() {
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
    }

    /**
     * Records the process fd/memory footprint. An abort caused by an exhausted fd table leaves
     * no trace of the resource itself, so one short line is the only cross-process record of
     * whether it was growing before the crash.
     */
    private fun journalResources(label: String) {
        runCatching { ResourceSnapshot.capture() }.getOrNull()?.let { snapshot ->
            VpnEventJournal.record(this, "$label resources: ${snapshot.summary()}")
        }
    }

    /**
     * Samples the fd table while the tunnel runs and rebuilds once per process when it nears the
     * ceiling. Rebuilding is a best-effort self-heal: if the leak outlives the runtime it only
     * buys the diagnostics upload that names the leaking fd family.
     */
    private fun startResourceJournal() {
        resourceJournalJob?.cancel()
        resourceJournalJob = recoveryScope.launch {
            var samples = 0
            while (true) {
                delay(RESOURCE_SAMPLE_INTERVAL_MS)
                val snapshot = runCatching { ResourceSnapshot.capture() }.getOrNull() ?: continue
                samples += 1
                if (samples % RESOURCE_JOURNAL_EVERY == 0) {
                    VpnEventJournal.record(
                        this@LeikwanVpnService,
                        "resources: ${snapshot.summary()}",
                    )
                }
                if (isRunning && !recovering && underFdPressure(snapshot)
                    && fdGuardTripped.compareAndSet(false, true)
                ) {
                    Log.w(TAG, "fd pressure ${snapshot.summary()}; rebuilding the tunnel")
                    VpnEventJournal.record(
                        this@LeikwanVpnService,
                        "fd pressure: ${snapshot.summary()}",
                    )
                    mainHandler.post { rebuildInPlace("fd pressure") }
                }
            }
        }
    }

    private fun underFdPressure(snapshot: ResourceSnapshot.Value): Boolean {
        if (snapshot.fdCount < 0) return false
        val limit = if (snapshot.fdLimit > 0) snapshot.fdLimit else DEFAULT_FD_LIMIT
        return snapshot.fdCount >= limit * 3 / 4
    }

    /** [reason] only labels the lifecycle journal; every external caller is the orchestrator. */
    fun stopVpn(reason: String = "orchestrator") {
        VpnEventJournal.record(this, "vpn stop: $reason (running=$isRunning recovering=$recovering)")
        switchRecoveryJob?.cancel()
        switchRecoveryJob = null
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
        VpnEventJournal.record(this, "service destroyed (running=$isRunning)")
        recovering = false
        switchRecoveryJob = null
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
        stopVpn("revoked by system")
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
            resolveUnderlyingNetworks().takeIf { it.isNotEmpty() }?.let { networks ->
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP_MR1) {
                    builder.setUnderlyingNetworks(networks.toTypedArray())
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
        if (listener != null) {
            networkMonitor.start(listener)
            networkMonitor.addOnDefaultInterfaceChanged(::onDefaultInterfaceChanged)
            networkMonitor.addOnDataResumed(::onDataResumed)
        }
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
        stopVpn("libbox service stop")
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
        return resolveUnderlyingNetworks().firstOrNull()
    }

    private fun resolveUnderlyingNetworks(): List<Network> {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.LOLLIPOP_MR1) return emptyList()
        val connectivity = getSystemService(ConnectivityManager::class.java) ?: return emptyList()
        val candidates = connectivity.allNetworks.mapNotNull { network ->
            val capabilities = connectivity.getNetworkCapabilities(network) ?: return@mapNotNull null
            UnderlyingNetworkCandidate(
                value = network,
                isVpn = capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN),
                hasInternet = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET),
            )
        }
        return UnderlyingNetworkSelector.selectAll(connectivity.activeNetwork, candidates)
    }

    /**
     * Publishes the physical networks the tunnel may leave through. Passing every physical
     * network (not only the selected one) lets the kernel keep tunnel traffic flowing across a
     * Wi-Fi <-> cellular switch: on 4G/5G the selected network can die seconds before the
     * monitor sees it, and a single pinned network blackholed the tunnel until the next
     * `onLost` callback re-pinned it. When nothing physical is up, fall back to default
     * routing (null) so the tunnel follows the system as networks return.
     */
    private fun applyUnderlyingNetworks(preferred: Network?) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.LOLLIPOP_MR1) return
        val networks = resolveUnderlyingNetworks()
        val ordered = buildList {
            if (preferred != null && networks.contains(preferred)) add(preferred)
            networks.forEach { if (it != preferred) add(it) }
        }
        try {
            setUnderlyingNetworks(ordered.ifEmpty { null }?.toTypedArray())
        } catch (e: Exception) {
            Log.w(TAG, "Unable to update underlying VPN networks", e)
        }
    }

    /**
     * Wi-Fi <-> cellular switch recovery.
     *
     * Even with every physical network published as underlying, a transport switch can leave the
     * tunnel unusable: libbox's established outbound sockets keep pointing at the dead interface
     * until they are rebuilt, and sing-box's DNS/route caches keep the old one. On a default
     * interface change while the tunnel is up, tell libbox to rebind ([CommandServer.resetNetwork]),
     * then check the new network; if it does not answer, rebuild the VPN in place through
     * [rebuildInPlace] (which restarts only the runtimes and re-opens the TUN, not the whole
     * service). A brief silence first: right after a switch the new network is still validating
     * and a probe would fail spuriously.
     */
    private fun onDefaultInterfaceChanged(name: String?) {
        val previous = lastDefaultInterfaceName
        lastDefaultInterfaceName = name
        if (!isRunning || recovering) return
        if (previous == null && name == null) return
        Log.i(TAG, "Default interface changed: ${previous ?: "none"} -> ${name ?: "none"}")
        VpnEventJournal.record(this, "default interface ${previous ?: "none"} -> ${name ?: "none"}")
        try {
            commandServer?.resetNetwork()
        } catch (e: Exception) {
            Log.w(TAG, "resetNetwork after interface switch failed", e)
        }
        switchRecoveryJob?.cancel()
        switchRecoveryJob = recoveryScope.launch {
            // Let the new network validate and libbox rebind before judging the tunnel.
            delay(VpnRecovery.SWITCH_PROBE_DELAY_MS)
            if (!isRunning || recovering) return@launch
            if (probeSwitchedNetwork()) {
                // The new network answers: renew now rather than when a retry backoff that grew
                // while the old network was gone happens to expire.
                wakeControlPlane()
                return@launch
            }
            // The probe blocks in socket reads; a newer switch or a stop may have cancelled us.
            ensureActive()
            Log.w(TAG, "Tunnel still unusable after the interface switch; rebuilding it")
            // Not from this job: the rebuild's stop of the old tunnel cancels it.
            mainHandler.post { rebuildAfterSwitch() }
        }
    }

    /**
     * Cellular data came back on the same network after [suspendedMs] (see
     * [AndroidNetworkMonitor.addOnDataResumed]). Flows opened before the suspension sit in TCP
     * retransmit backoff, so after a long one they are closed and apps reconnect at once; the
     * control plane is polled now so a lease that lapsed during the call is renewed immediately.
     */
    private fun onDataResumed(suspendedMs: Long) {
        if (!isRunning || recovering) return
        Log.i(TAG, "Cellular data resumed after ${suspendedMs}ms")
        VpnEventJournal.record(this, "data resumed after ${suspendedMs / 1000}s suspended")
        if (suspendedMs >= VpnRecovery.RESUME_RESET_AFTER_MS) {
            try {
                commandServer?.resetNetwork()
            } catch (e: Exception) {
                Log.w(TAG, "resetNetwork after data resumed failed", e)
            }
        }
        wakeControlPlane()
    }

    private fun wakeControlPlane() {
        runCatching { TunnelOrchestrator.forApplication(applicationContext).onNetworkRestored() }
            .onFailure { Log.w(TAG, "Unable to wake the control plane after a network change", it) }
    }

    private fun rebuildAfterSwitch() {
        if (!isRunning || recovering) return
        updateNotification(getString(R.string.vpn_notification_reconnecting))
        rebuildInPlace("interface switch")
    }

    /**
     * Whether the network after a switch answers DNS over UDP, asked of its own resolvers for a
     * few rounds while it finishes validating. This app and its runtimes are excluded from the
     * VPN (see [openTun]), so for this process the active network is the physical one and the
     * query does not cross the tunnel: it checks the path the runtimes and the panel depend on.
     * A false negative costs one in-place rebuild.
     */
    private suspend fun probeSwitchedNetwork(): Boolean {
        val healthy = VpnRecovery.anyResolverAnswers(activeNetworkResolvers(), query = ::queryResolver)
        if (!healthy) VpnEventJournal.record(this, "post-switch probe failed")
        return healthy
    }

    private fun queryResolver(dnsServer: String): Boolean {
        return runCatching {
            val socket = java.net.DatagramSocket()
            try {
                socket.soTimeout = VpnRecovery.SWITCH_PROBE_TIMEOUT_MS
                val address = java.net.InetSocketAddress(dnsServer, 53)
                // An A query for the panel host; the answer content does not matter.
                val query = dnsQueryBytes("connectivity.leikwanhost.com")
                socket.send(java.net.DatagramPacket(query, query.size, address))
                val buffer = ByteArray(512)
                val packet = java.net.DatagramPacket(buffer, buffer.size)
                socket.receive(packet)
                packet.length >= 12
            } finally {
                socket.close()
            }
        }.getOrElse { error ->
            Log.i(TAG, "Network probe via $dnsServer failed: ${error.message}")
            false
        }
    }

    private fun activeNetworkResolvers(): List<String> {
        val connectivity = getSystemService(ConnectivityManager::class.java) ?: return emptyList()
        val properties = connectivity.getLinkProperties(connectivity.activeNetwork) ?: return emptyList()
        return properties.dnsServers.mapNotNull { it.hostAddress }
    }

    /** A minimal recursive A query; no external DNS library needed for a boolean probe. */
    private fun dnsQueryBytes(name: String): ByteArray {
        val id = byteArrayOf(0x12, 0x34)
        val header = byteArrayOf(id[0], id[1], 0x01, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00)
        val labels = name.split('.').flatMap { label ->
            (byteArrayOf(label.length.toByte()) + label.toByteArray(Charsets.US_ASCII)).asIterable()
        }.fold(byteArrayOf()) { acc, part -> acc + part }
        val tail = byteArrayOf(0x00, 0x00, 0x01, 0x00, 0x01)
        return header + labels + tail
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

    /**
     * In-tunnel latency for Ultra (whose config leaves the sing-box probe group out): every 30 s,
     * time a TCP handshake to the relay entry in use on a protected socket. A refused connect
     * (UDP-only relay port) is still one round trip and counts; a timeout does not.
     */
    private fun startRelayLatencyProbe() {
        relayLatencyJob?.cancel()
        relayLatencyJob = recoveryScope.launch {
            delay(3_000)
            while (true) {
                val endpoint = runCatching {
                    TunnelOrchestrator.forApplication(applicationContext).activeUltraRelayEndpoint()
                }.getOrNull()
                if (endpoint != null) {
                    measureHandshakeMs(endpoint.first, endpoint.second)?.let(TrafficMonitor::reportRelayLatency)
                }
                delay(30_000)
            }
        }
    }

    private fun measureHandshakeMs(host: String, port: Int): Int? {
        val socket = java.net.Socket()
        return try {
            protect(socket)
            val started = System.nanoTime()
            try {
                socket.connect(java.net.InetSocketAddress(InetAddress.getByName(host), port), 3_000)
            } catch (refused: java.net.ConnectException) {
                if (refused.message?.contains("ECONNREFUSED") != true && refused.message?.contains("refused", ignoreCase = true) != true) {
                    return null
                }
            }
            ((System.nanoTime() - started) / 1_000_000).toInt().coerceAtLeast(1)
        } catch (_: Exception) {
            null
        } finally {
            runCatching { socket.close() }
        }
    }

    private fun startCommandClientDiagnostics() {
        val options = CommandClientOptions().apply {
            addCommand(Libbox.CommandLog)
            addCommand(Libbox.CommandConnections)
            addCommand(Libbox.CommandStatus)
            // Group stream: the config's synthetic `probe` urltest group (see SingboxConfigBuilder)
            // self-tests on its interval; writeGroups turns the exit's URL-test delay into the
            // dashboard's in-tunnel latency number.
            addCommand(Libbox.CommandGroup)
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
        private fun reportSuppressed() {
            val count = detailBudget.advance()
            if (count > 0) Log.i(TAG, "box diagnostic detail suppressed=$count in previous 10s window")
        }

        // Process-wide: reconnecting the command stream does not replenish the budget.
        companion object { private val detailBudget = DiagnosticLogBudget() }

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
            reportSuppressed()
            while (iterator?.hasNext() == true) {
                val entry = iterator.next()
                // sing-box levels: panic=0, fatal=1, error=2, warn=3, info=4, debug=5, trace=6.
                if (entry.level <= 3) {
                    Log.println(if (entry.level <= 2) Log.ERROR else Log.WARN, TAG, "box log[${entry.level}]: ${entry.message}")
                } else if (detailBudget.admit()) {
                    Log.d(TAG, "box log[${entry.level}]: ${entry.message}")
                }
            }
        }

        override fun writeConnectionEvents(events: ConnectionEvents?) {
            reportSuppressed()
            val iterator = events?.iterator() ?: return
            while (iterator.hasNext()) {
                val event = iterator.next()
                if (!detailBudget.admit()) continue
                val connection = event.connection
                Log.d(
                    TAG,
                    "box conn type=${event.type} id=${event.id} " +
                        "network=${connection?.network} dest=${connection?.displayDestination()} " +
                        "domain=${connection?.domain} outbound=${connection?.outbound} " +
                        "chain=${connection?.chain()?.toListForLog()}",
                )
            }
        }

        override fun writeStatus(message: StatusMessage?) {
            reportSuppressed()
            if (message == null) return
            TrafficMonitor.report(
                uplink = message.uplink,
                downlink = message.downlink,
                uplinkTotal = message.uplinkTotal,
                downlinkTotal = message.downlinkTotal,
                connections = message.connectionsOut,
            )
            if (detailBudget.admit()) Log.d(
                TAG,
                "box status in=${message.connectionsIn} out=${message.connectionsOut} " +
                    "up=${message.uplinkTotal} down=${message.downlinkTotal}",
            )
        }

        override fun writeGroups(iterator: OutboundGroupIterator?) {
            reportSuppressed()
            var emitted = 0
            while (iterator?.hasNext() == true && emitted < 40) {
                val group = iterator.next()
                if (group.tag == "probe") {
                    // The probe group holds the active exit and "direct"; the exit's URL-test
                    // delay is the in-tunnel latency shown on the dashboard.
                    val items = group.items
                    while (items.hasNext()) {
                        val item = items.next()
                        if (item.tag != "direct" && item.urlTestDelay > 0) {
                            TrafficMonitor.reportLatency(item.urlTestDelay.toInt())
                        }
                    }
                }
                if (detailBudget.admit()) Log.d(TAG, "box group tag=${group.tag} type=${group.type} selected=${group.selected}")
                emitted += 1
            }
        }

        override fun writeOutbounds(iterator: OutboundGroupItemIterator?) {
            reportSuppressed()
            var emitted = 0
            while (iterator?.hasNext() == true && emitted < 40) {
                val outbound = iterator.next()
                if (detailBudget.admit()) Log.d(TAG, "box outbound tag=${outbound.tag} type=${outbound.type} delay=${outbound.urlTestDelay}")
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
