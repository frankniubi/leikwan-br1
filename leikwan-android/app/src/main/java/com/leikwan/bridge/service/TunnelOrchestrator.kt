package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.util.Log
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.runtime.BondForwardRunner
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.runtime.SingleForwardRunner
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File

private const val TAG = "TunnelOrchestrator"

class TunnelOrchestrator(private val context: Context) {
    private val panelRepo = PanelRepository(context)
    private val prefs = Prefs.get(context)
    private val db = AppDatabase.get(context)
    private val binaryInstaller = RuntimeBinaryInstaller(context)
    private val processRunner = ProcessRunner()

    private var activeRuntime: RuntimeContext? = null

    suspend fun startForForward(forwardId: Long) = withContext(Dispatchers.IO) {
        Log.i(TAG, "Pulling forward configs...")
        val connectionMode = prefs.proxyConnectionMode
        val proxyNodes = db.proxyNodeDao().getAllList()
        val selectedProxyId = prefs.selectedProxyId
        if (selectedProxyId > 0L && proxyNodes.none { it.id == selectedProxyId }) {
            prefs.selectedProxyId = 0L
        }

        val catalog = panelRepo.pullCatalog(forwardId.takeIf { it > 0L } ?: prefs.selectedForwardId)
        val selectedForward = forwardId.takeIf { it > 0L }

        if (
            connectionMode == ProxyConnectionMode.SERVICES &&
            (selectedForward == null || catalog.visibleForwards.none { it.forwardId == selectedForward })
        ) {
            throw Exception("Select a service before starting in Services mode")
        }

        val selection = ProxySelectionPolicy.validate(
            selectedProxyId = prefs.selectedProxyId,
            forwardId = selectedForward ?: 0L,
            connectionMode = connectionMode,
            proxyNodes = proxyNodes,
        )
        if (selection is ProxySelectionResult.Error) {
            throw Exception(selection.message)
        }
        selection as ProxySelectionResult.Success

        stop()

        val runtimeContext = if (connectionMode == ProxyConnectionMode.SERVICES) {
            val forward = panelRepo.getCachedContract(selectedForward ?: 0L)
                ?: throw Exception("Forward ${selectedForward ?: 0L} not found")
            Log.i(TAG, "Starting forward ${forward.forwardId}: ${forward.serviceName}")
            val binaries = binaryInstaller.prepareBinaryPaths()
            val planned = when (forward.mode) {
                ForwardMode.SINGLE -> SingleForwardRunner.buildPlan(forward, binaries)
                ForwardMode.BOND -> BondForwardRunner.buildPlan(forward, binaries)
                else -> throw Exception("Forward ${forward.forwardId} mode ${forward.mode.wireValue} is not supported on Android")
            }
            processRunner.start(forward.forwardId, planned)
            planned
        } else {
            null
        }

        val compiled = SingboxConfigBuilder
            .fromPrefs(prefs)
            .build(
                proxyNodes = listOf(selection.selectedNode),
                runtimeContext = runtimeContext,
                routingPolicySnapshot = null, // T5 wires the real RoutingPolicySnapshot here
                connectionMode = connectionMode,
            )
        val configJson = compiled.configJson
        writeDebugConfig(configJson)
        Log.d(TAG, "Generated sing-box config: $configJson")

        LeikwanVpnService.configJson = configJson
        val intent = Intent(context, LeikwanVpnService::class.java)
        context.startForegroundService(intent)

        when (val vpnResult = VpnStartupMonitor.await(
            timeoutMs = 10_000,
            readState = { LeikwanVpnService.startupSnapshot() },
        )) {
            VpnStartupAwaitResult.Running -> {
                activeRuntime = runtimeContext
            }
            is VpnStartupAwaitResult.Failed -> {
                runtimeContext?.let { processRunner.stopForward(it.forwardId) }
                throw Exception(vpnResult.errorMessage)
            }
            VpnStartupAwaitResult.Timeout -> {
                runtimeContext?.let { processRunner.stopForward(it.forwardId) }
                throw Exception("VPN service start timed out")
            }
        }
    }

    fun stop() {
        try {
            LeikwanVpnService.instance?.stopVpn()
        } catch (e: Exception) {
            Log.e(TAG, "Error stopping VPN service", e)
        }
        processRunner.stopAll()
        activeRuntime = null
    }

    fun isRunning(): Boolean = LeikwanVpnService.isRunning

    private fun writeDebugConfig(configJson: String) {
        runCatching {
            File(context.filesDir, "latest-singbox-config.json").writeText(configJson)
        }
    }
}
