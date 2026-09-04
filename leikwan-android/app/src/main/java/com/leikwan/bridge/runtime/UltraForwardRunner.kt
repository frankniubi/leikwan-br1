package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import java.io.File

data class UltraRuntimePlan(
    val runtimeContext: RuntimeContext,
    val configFile: File,
    val controlSocketFile: File,
    val statusFile: File,
)

/** Builds only static, app-private Ultra runtime state. Provider credentials never enter this file. */
object UltraForwardRunner {
    fun buildPlan(
        forward: ForwardContract,
        policy: UltraPolicy,
        binaries: RuntimeBinaryPaths,
        runtimeDir: File,
        nativeSocks5: Boolean = false,
        listenPortOverride: Int? = null,
        nativeRoutePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    ): UltraRuntimePlan {
        require(forward.forwardId == policy.forwardId) { "ultra_policy_forward_mismatch" }
        val binary = binaries.ultraBinary?.takeIf { it.isNotBlank() }
            ?: throw IllegalArgumentException("ultra mode requires packaged ultra-client")
        val directory = File(runtimeDir, "ultra").apply {
            if (!exists() && !mkdirs()) throw IllegalStateException("ultra_runtime_directory_unavailable")
        }
        val suffix = "forward-${forward.forwardId}-${policy.policyGeneration.take(12)}"
        val configFile = File(directory, "$suffix.json")
        val controlSocketFile = File(directory, "$suffix.control.sock")
        val statusFile = File(directory, "$suffix.status.json")
        val locationCacheFile = File(directory, "$suffix.location.json")
        val locationLockFile = File(directory, "$suffix.location.lock")
        val privateFiles = listOf(configFile, controlSocketFile, statusFile, locationCacheFile, locationLockFile)
        privateFiles.forEach { file -> runCatching { file.delete() } }
        val listenPort = listenPortOverride ?: forward.listenPort
        require(listenPort in 1..65535) { "ultra_listen_port_invalid" }
        val normalizedNativeRoutePolicy = if (nativeSocks5) {
            require(isNativeRoutePolicyValue(nativeRoutePolicy)) { "native_route_policy_invalid" }
            normalizeNativeRoutePolicy(nativeRoutePolicy)
        } else {
            NATIVE_ROUTE_POLICY_RESTRICTED
        }

        val config = JsonObject().apply {
            addProperty("forward_id", forward.forwardId)
            addProperty("listen_host", "127.0.0.1")
            addProperty("listen_port", listenPort)
            addProperty("target", forward.target.trim())
            if (nativeSocks5) {
                addProperty("native_socks5", true)
                addProperty("native_route_policy", normalizedNativeRoutePolicy)
                addProperty("native_port_min", 1000)
                addProperty(
                    "native_filter_profile",
                    if (normalizedNativeRoutePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED) "off" else "opengfw_tcp",
                )
            }
            addProperty("control_socket", controlSocketFile.absolutePath)
            addProperty("policy_generation", policy.policyGeneration)
            addProperty("target_digest", policy.targetDigest)
            addProperty("transport", policy.transport)
            addProperty("max_tcp_connections", 512)
            addProperty("udp_queue_packets", 1024)
            addProperty("connect_timeout_seconds", 5)
            addProperty("location_cache_path", locationCacheFile.absolutePath)
            addProperty("location_lock_path", locationLockFile.absolutePath)
            addProperty("status_path", statusFile.absolutePath)
            addProperty("probe_timeout_ms", 800)
            if (nativeSocks5) {
                // Native batches are probed by the local adapter before one path is activated.
                // Keep the window bounded so Android startup remains responsive while allowing
                // the provider-side UDP/SID association scan to complete.
                addProperty("auto_select", true)
                addProperty("probe_duration_seconds", 5)
                addProperty("probe_samples", 20)
                addProperty("probe_interval_ms", 250)
                addProperty("probe_workers", 4)
            } else {
                addProperty("probe_workers", 2)
            }
        }
        configFile.writeText(config.toString(), Charsets.UTF_8)
        makePrivate(configFile)

        val runtimeContext = RuntimeContext(
            forwardId = forward.forwardId,
            mode = ForwardMode.ULTRA,
            localListenPort = listenPort,
            localLegPorts = emptyList(),
            processPlans = listOf(
                RuntimeProcessPlan(
                    role = RuntimeProcessRole.ULTRA,
                    command = listOf(binary, "--config", configFile.absolutePath),
                )
            ),
            bypassHosts = emptySet(),
            protectedConnectivity = ProtectedConnectivitySet.empty(),
            privateRuntimePaths = privateFiles.map(File::getAbsolutePath),
        )
        return UltraRuntimePlan(runtimeContext, configFile, controlSocketFile, statusFile)
    }

    fun cleanup(runtimeContext: RuntimeContext) {
        runtimeContext.privateRuntimePaths.sortedByDescending { it.length }.forEach { path ->
            runCatching { File(path).delete() }
        }
    }

    private fun makePrivate(file: File) {
        file.setReadable(false, false)
        file.setWritable(false, false)
        file.setReadable(true, true)
        file.setWritable(true, true)
    }
}
