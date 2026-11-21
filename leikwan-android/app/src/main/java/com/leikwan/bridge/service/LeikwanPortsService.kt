package com.leikwan.bridge.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.net.DirectPortNetwork
import com.leikwan.bridge.net.EgressSocketBroker
import com.leikwan.bridge.runtime.*
import com.leikwan.bridge.session.*
import com.leikwan.bridge.ui.MainActivity
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.io.File
import java.io.IOException
import java.time.Instant
import java.util.concurrent.TimeUnit

/** Official port forwarding owner. Deliberately a normal Service: no VPN permission, TUN or libbox. */
class LeikwanPortsService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val mutex = Mutex()
    private val processes = ProcessRunner()
    private val leaseClient = UltraLeaseClient()
    private val plans = mutableMapOf<Long, UltraRuntimePlan>()
    private val policies = mutableMapOf<Long, UltraPolicy>()
    private val sentAt = mutableMapOf<Long, Instant>()
    private var listenerLabels = emptyList<String>()
    private val repository by lazy { PanelRepository(this) }
    private val prefs by lazy { Prefs.get(this) }
    private var broker: EgressSocketBroker? = null
    private var loop: ClientControlLoop? = null
    private var selectedIds = emptySet<Long>()
    private var networkGeneration = -1L
    private var binaries: RuntimeBinaryPaths? = null
    @Volatile private var stopping = false

    override fun onCreate() {
        super.onCreate()
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, getString(R.string.ports_title), NotificationManager.IMPORTANCE_LOW))
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        // Foreground promotion must precede asynchronous panel/network/runtime work, including failure.
        showNotification(getString(R.string.ports_starting))
        if (intent?.action == ACTION_STOP) { shutdown(); return START_NOT_STICKY }
        if (loop != null) return START_NOT_STICKY
        selectedIds = intent?.getLongArrayExtra(EXTRA_IDS)?.filter { it > 0L }?.toSet().orEmpty()
        if (selectedIds.isEmpty() || LeikwanVpnService.isRunning) {
            fail("ports_stop_vpn_and_select_forward"); shutdown(); return START_NOT_STICKY
        }
        stateMutable.value = PortsServiceState(starting = true)
        try {
            DirectPortNetwork.start(this, prefs.portNetworkChoice) {
                // Generation changed synchronously; invalidate old IPC and existing remote sockets now.
                broker?.close(); broker = null
                processes.stopAll()
                if (!stopping) scope.launch {
                    mutex.withLock {
                        // Drop every existing remote socket on reroute/loss, not just future dials.
                        stopRuntimes()
                        broker?.close(); broker = null
                        stateMutable.value = PortsServiceState(starting = true, error = "ports_network_changed_reconnecting")
                    }
                    loop?.requestPollAfterNetworkRestored()
                }
            }
            loop = ClientControlLoop(scope = scope,
                poll = { mutex.withLock { reconcile() } },
                shouldContinue = { !stopping },
                onTerminal = { terminal ->
                    val acknowledgement = repository.captureTerminalAcknowledgement()
                    stopRuntimes()
                    repository.logout()
                    fail(terminal.message ?: terminal.code)
                    scope.launch {
                        acknowledgement?.let { runCatching { repository.acknowledgeTerminal(it, terminal.command?.version ?: 0L) } }
                        shutdown()
                    }
                },
                onDeviceLimitChanged = { refusal ->
                    if (refusal != null) { stopRuntimes(); DeviceLimitGate.report(refusal); fail(refusal.message ?: "device_limit_reached") }
                },
                onStateChanged = { state ->
                    if (state == ControlPlaneState.RECONNECTING && !stopping) {
                        val current = stateMutable.value
                        if (!current.running) showNotification(getString(R.string.ports_waiting_network))
                    }
                },
                onRetryScheduled = { if (!stopping && !stateMutable.value.running) showNotification(getString(R.string.ports_waiting_network)) },
            ).also { it.start() }
        } catch (error: Exception) { fail(error.message ?: "ports_start_failed"); shutdown() }
        return START_NOT_STICKY // No unattended restart or VPN boot receiver involvement.
    }

    private suspend fun reconcile() {
        try {
            if (stopping) throw CancellationException("ports_stopped")
            DirectPortNetwork.requireAvailable()
            val generation = DirectPortNetwork.generation
            repository.pullCatalog(selectedIds.first())
            val forwards = selectedIds.map { id -> repository.getCachedContract(id)
                ?: run { stopRuntimes(); throw IllegalStateException("ports_forward_removed_$id") } }
            try { PortModePolicy.validate(forwards) }
            catch (unsupported: IllegalArgumentException) { stopRuntimes(); throw unsupported }
            val nextPolicies = forwards.associate { it.forwardId to (UltraPolicy.from(it)
                ?: run { stopRuntimes(); throw IllegalStateException("ports_ultra_policy_unavailable_${it.forwardId}") }) }
            val paths = binaries ?: RuntimeBinaryInstaller(this).prepareBinaryPaths().also {
                checkCoreCapability(it.ultraBinary ?: throw IOException("ports_ultra_core_missing"))
                binaries = it
            }
            if (networkGeneration != generation || broker == null) {
                stopRuntimes()
                broker?.close()
                broker = EgressSocketBroker(File(filesDir, "eg.sock")) { fd ->
                    try { DirectPortNetwork.bindDescriptor(fd, generation) }
                    catch (refused: Exception) {
                        scope.launch {
                            mutex.withLock {
                                stopRuntimes()
                                if (!stopping) fail("ports_physical_binding_refused_system_vpn_policy")
                            }
                        }
                        throw refused
                    }
                }.also { it.start() }
                networkGeneration = generation
            }
            val refresh = repository.takeUltraRefreshRequests()
            for (forward in forwards) {
                val policy = nextPolicies.getValue(forward.forwardId)
                val existing = plans[forward.forwardId]
                val previous = policies[forward.forwardId]
                if (existing != null && (previous?.policyGeneration != policy.policyGeneration
                        || previous.targetDigest != policy.targetDigest || previous.transport != policy.transport
                        || existing.runtimeContext.localListenPort != forward.listenPort)) {
                    processes.stopForward(forward.forwardId)
                    plans.remove(forward.forwardId)
                    policies.remove(forward.forwardId); sentAt.remove(forward.forwardId)
                }
                var restarted = false
                var plan = plans[forward.forwardId]
                if (plan == null) {
                    PortModePolicy.requireListenerAvailable(forward.listenPort, policy.transport)
                    val fresh = UltraForwardRunner.buildPlan(forward, policy, paths, File(filesDir, "runtime"),
                        directEgress = DirectEgressConfig(File(filesDir, "eg.sock").absolutePath, DirectPortNetwork.dnsServers()))
                    if (stopping || DirectPortNetwork.generation != generation) throw IOException("ports_network_changed")
                    processes.start(forward.forwardId, fresh.runtimeContext, cleanupStale = plans.isEmpty())
                    if (stopping || DirectPortNetwork.generation != generation) {
                        processes.stopForward(forward.forwardId)
                        throw IOException("ports_network_changed")
                    }
                    restarted = true
                    plans[forward.forwardId] = fresh
                    plan = fresh
                }
                val now = Instant.now()
                val needsLease = restarted || sentAt[forward.forwardId] == null
                    || previous == null || !previous.renewAfter.isAfter(now.plusSeconds(30)) || forward.forwardId in refresh
                    || UltraRuntimeStatus.read(plan.statusFile.absolutePath)?.needsLease == true
                if (needsLease) {
                    val leases = repository.resolveUltraLeases(policy)
                    if (!leaseClient.send(plan.controlSocketFile.absolutePath, policy, leases)) throw IOException("ports_ultra_lease_delivery_failed")
                    sentAt[forward.forwardId] = now
                    policies[forward.forwardId] = policy
                }
                if (policy.transport != "udp") processes.awaitLocalPort(forward.listenPort)
            }
            if (DirectPortNetwork.generation != generation) throw IOException("ports_network_changed")
            if (!repository.heartbeat()) throw IOException("ports_panel_heartbeat_failed")
            listenerLabels = forwards.map { "127.0.0.1:${it.listenPort} → ${it.target} (${it.transport})" }
            stateMutable.value = PortsServiceState(running = true, listeners = listenerLabels)
            showNotification(getString(R.string.ports_running, forwards.size))
        } catch (cancelled: CancellationException) { stopRuntimes(); throw cancelled }
        catch (terminal: PanelControlException) { throw terminal }
        catch (limit: DeviceLimitReachedException) { throw limit }
        catch (error: Exception) {
            // The core enforces the installed lease's expiry. A panel outage must not kill a
            // healthy, still-authorized data plane. A physical loss/generation change is different.
            if (error is retrofit2.HttpException && error.code() == 426) {
                stopRuntimes()
                fail("ports_official_client_update_required")
                shutdown() // Preserve device identity/session; this is not a revocation or exemption.
                return
            }
            val sameNetwork = !stopping && networkGeneration == DirectPortNetwork.generation
                && runCatching { DirectPortNetwork.requireAvailable() }.isSuccess
            val now = Instant.now()
            if (!sameNetwork) stopRuntimes()
            else plans.keys.toList().filter { !PortModePolicy.retainAuthorizedRuntime(sameNetwork, policies[it]?.expiresAt, now) }.forEach { id ->
                processes.stopForward(id); plans.remove(id); policies.remove(id); sentAt.remove(id)
            }
            if (plans.isNotEmpty()) {
                stateMutable.value = PortsServiceState(running = true, listeners = listenerLabels, error = error.message)
                showNotification(getString(R.string.ports_degraded))
            } else fail(error.message ?: "ports_paused")
            throw error
        }
    }

    private fun checkCoreCapability(binary: String) {
        val process = ProcessBuilder(binary, "--check-direct-egress").redirectErrorStream(true).start()
        try {
            if (!process.waitFor(3, TimeUnit.SECONDS) || process.exitValue() != 0
                || process.inputStream.bufferedReader().readText().trim() != "direct-egress-v1") {
                throw IOException("ports_core_update_required")
            }
        } finally { process.destroy(); if (process.isAlive) process.destroyForcibly() }
    }

    private fun stopRuntimes() {
        processes.stopAll()
        plans.clear(); policies.clear(); sentAt.clear(); listenerLabels = emptyList()
    }
    private fun fail(message: String) {
        stateMutable.value = PortsServiceState(starting = !stopping, error = message)
        showNotification(getString(R.string.ports_paused, message))
    }
    private fun showNotification(message: String) {
        val stop = PendingIntent.getService(this, 0, Intent(this, LeikwanPortsService::class.java).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE)
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        startForeground(41, NotificationCompat.Builder(this, CHANNEL).setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(getString(R.string.ports_title)).setContentText(message).setContentIntent(open)
            .setOngoing(true).addAction(0, getString(R.string.ports_stop), stop).build())
    }
    private fun shutdown() {
        if (stopping) return
        stopping = true
        loop?.stop()
        broker?.close(); broker = null
        processes.stopAll()
        DirectPortNetwork.detachService()
        stateMutable.value = stateMutable.value.copy(starting = false, running = false, listeners = emptyList())
        scope.launch {
            mutex.withLock { stopRuntimes() }
            scope.cancel()
        }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }
    override fun onDestroy() { shutdown(); super.onDestroy() }
    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        private const val CHANNEL = "leikwan_ports"
        private const val EXTRA_IDS = "forward_ids"
        private const val ACTION_STOP = "com.leikwan.bridge.PORTS_STOP"
        private val stateMutable = MutableStateFlow(PortsServiceState())
        val state = stateMutable.asStateFlow()
        fun start(context: Context, ids: Set<Long>) {
            ContextCompat.startForegroundService(context, Intent(context, LeikwanPortsService::class.java).putExtra(EXTRA_IDS, ids.toLongArray()))
        }
        fun stop(context: Context) { context.stopService(Intent(context, LeikwanPortsService::class.java)); stateMutable.value = PortsServiceState() }
    }
}

data class PortsServiceState(val starting: Boolean = false, val running: Boolean = false, val error: String? = null, val listeners: List<String> = emptyList())
