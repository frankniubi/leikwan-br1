package com.leikwan.bridge.runtime

import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import kotlin.math.max
import kotlin.math.min

object BondForwardRunner {
    fun buildPlan(
        forward: ForwardContract,
        binaries: RuntimeBinaryPaths,
        portSalt: Int = 0,
    ): RuntimeContext {
        val bonding = forward.bonding
            ?: throw IllegalArgumentException("bond mode missing bonding config")
        val bondBinary = binaries.bondBinary?.takeIf { it.isNotBlank() }
            ?: throw IllegalArgumentException("bond mode requires packaged leikwan-bond")
        if (forward.endpoints.isEmpty()) {
            throw IllegalArgumentException("bond mode requires at least one usable endpoint")
        }

        val ingressMode = bonding.ingressMode?.ifBlank { null } ?: "wss_path_mux"
        val routeKey = bonding.routeKey?.ifBlank { null } ?: forward.pathPrefix.ifBlank { "" }
        val remoteTarget = bonding.remoteTarget?.ifBlank { null }
        val selectedQuicLegs = selectActiveQuicLegs(forward.endpoints, bonding)
        val quicViaSlave = bonding.quicViaSlave ?: true
        val standbyWssEnabled = bonding.wssStandbyEnabled ?: true
        val useWssAsActiveLegs = selectedQuicLegs.isEmpty()
        val wsPriority = bonding.wsPriority ?: 10
        val joinTimeoutMs = adaptiveJoinTimeoutMs(bonding, forward.endpoints)

        val bypassHosts = linkedSetOf<String>()
        forward.sni.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        bonding.quicServerName?.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        forward.endpoints.forEach { endpoint ->
            bypassHosts += endpoint.host
            endpoint.cdnHost?.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        }

        val basePort = 20000 + (((forward.forwardId.toInt() * 37) + portSalt) % 15000)
        val localLegPorts = mutableListOf<Int>()
        val processPlans = mutableListOf<RuntimeProcessPlan>()
        val protocolPlans = mutableListOf<ProtocolPlan>()

        for (segment in protocolSegments(forward, bonding)) {
            val legTargets = mutableListOf<String>()
            if (quicViaSlave) {
                legTargets += selectedQuicLegs
            }

            forward.endpoints.forEachIndexed { index, endpoint ->
                val localPort = basePort + segment.portOffset + index
                localLegPorts += localPort
                processPlans += RuntimeProcessPlan(
                    role = RuntimeProcessRole.INGRESS,
                    command = buildIngressCommand(
                        endpoint = endpoint,
                        localPort = localPort,
                        ingressMode = ingressMode,
                        routeKey = routeKey,
                        remoteTarget = remoteTarget,
                        forward = forward,
                        binaries = binaries,
                    ),
                    readyPort = localPort,
                )

                val endpointPriority = endpoint.dynamicPriority ?: wsPriority
                if (useWssAsActiveLegs || !standbyWssEnabled) {
                    legTargets += renderTcpLeg("127.0.0.1:$localPort", endpointPriority, endpoint)
                }
            }

            protocolPlans += ProtocolPlan(
                protocol = segment.protocol,
                sessionToken = segment.sessionToken,
                legTargets = legTargets,
            )
        }

        protocolPlans.forEach { protocolPlan ->
            processPlans += RuntimeProcessPlan(
                role = RuntimeProcessRole.BOND,
                command = buildBondCommand(
                    bondBinary = bondBinary,
                    protocol = protocolPlan.protocol,
                    listenPort = forward.listenPort,
                    legTargets = protocolPlan.legTargets,
                    sessionToken = protocolPlan.sessionToken,
                    bonding = bonding,
                    joinTimeoutMs = joinTimeoutMs,
                ),
            )
        }

        return RuntimeContext(
            forwardId = forward.forwardId,
            mode = ForwardMode.BOND,
            localListenPort = forward.listenPort,
            localLegPorts = localLegPorts,
            processPlans = processPlans,
            bypassHosts = bypassHosts,
        )
    }

    private fun protocolSegments(
        forward: ForwardContract,
        bonding: BondingContract,
    ): List<ProtocolSegment> {
        val tcpToken = bonding.sessionToken?.takeIf { it.isNotBlank() }
        val udpToken = bonding.udpSessionToken?.takeIf { it.isNotBlank() } ?: tcpToken
        return when (forward.transport.trim().lowercase()) {
            "tcp" -> listOf(
                ProtocolSegment(
                    protocol = "tcp",
                    portOffset = 0,
                    sessionToken = tcpToken
                        ?: throw IllegalArgumentException("bond mode missing bonding.session_token for tcp transport"),
                )
            )
            "udp" -> listOf(
                ProtocolSegment(
                    protocol = "udp",
                    portOffset = 200,
                    sessionToken = udpToken
                        ?: throw IllegalArgumentException("bond mode missing bonding.udp_session_token for udp transport"),
                )
            )
            "tcp+udp" -> listOf(
                ProtocolSegment(
                    protocol = "tcp",
                    portOffset = 0,
                    sessionToken = tcpToken
                        ?: throw IllegalArgumentException("bond mode missing bonding.session_token for tcp transport"),
                ),
                ProtocolSegment(
                    protocol = "udp",
                    portOffset = 200,
                    sessionToken = udpToken
                        ?: throw IllegalArgumentException("bond mode missing bonding.udp_session_token for udp transport"),
                ),
            )
            else -> throw IllegalArgumentException("bond mode transport ${forward.transport} is not supported on Android")
        }
    }

    private fun buildIngressCommand(
        endpoint: ForwardEndpoint,
        localPort: Int,
        ingressMode: String,
        routeKey: String,
        remoteTarget: String?,
        forward: ForwardContract,
        binaries: RuntimeBinaryPaths,
    ): List<String> {
        return when (ingressMode) {
            "plain_tcp_mux" -> {
                val ingressBinary = binaries.ingressBinary?.takeIf { it.isNotBlank() }
                    ?: throw IllegalArgumentException("bond plain_tcp_mux requires packaged leikwan-ingress")
                val authToken = endpoint.ingressAuthToken?.takeIf { it.isNotBlank() }
                    ?: throw IllegalArgumentException("bond plain_tcp_mux requires ingress auth token for endpoint ${endpoint.endpointId}")
                if (routeKey.isBlank()) {
                    throw IllegalArgumentException("bond plain_tcp_mux requires bonding.route_key or path_prefix")
                }
                listOf(
                    ingressBinary,
                    "client",
                    "--listen",
                    "127.0.0.1:$localPort",
                    "--remote",
                    "${endpoint.host}:${endpoint.port}",
                    "--route-key",
                    routeKey,
                    "--auth-token",
                    authToken,
                )
            }
            else -> {
                val wstunnelBinary = binaries.wstunnelBinary?.takeIf { it.isNotBlank() }
                    ?: throw IllegalArgumentException("bond fallback ingress requires packaged wstunnel")
                val resolvedRemoteTarget = remoteTarget?.takeIf { it.isNotBlank() }
                    ?: throw IllegalArgumentException("bond mode missing bonding.remote_target")
                listOf(
                    wstunnelBinary,
                    "client",
                    "-L",
                    "tcp://127.0.0.1:$localPort:$resolvedRemoteTarget",
                    "--tls-sni-override",
                    forward.sni,
                    "--http-headers",
                    "Host: ${forward.sni}",
                    "--http-upgrade-path-prefix",
                    forward.pathPrefix,
                    "wss://${endpoint.host}:${endpoint.port}",
                )
            }
        }
    }

    private fun buildBondCommand(
        bondBinary: String,
        protocol: String,
        listenPort: Int,
        legTargets: List<String>,
        sessionToken: String,
        bonding: BondingContract,
        joinTimeoutMs: Int,
    ): List<String> {
        return mutableListOf(
            bondBinary,
            "client",
            "--protocol",
            protocol,
            "--listen",
            "127.0.0.1:$listenPort",
            "--legs",
            legTargets.joinToString(","),
            "--session-token",
            sessionToken,
            "--chunk-size",
            (bonding.chunkSize ?: 65536).toString(),
            "--max-window-bytes",
            (bonding.maxWindowBytes ?: 8388608).toString(),
            "--retransmit-ms",
            (bonding.retransmitMs ?: 700).toString(),
            "--connect-timeout-ms",
            (bonding.connectTimeoutMs ?: 3000).toString(),
            "--join-timeout-ms",
            joinTimeoutMs.toString(),
            "--interactive-chunk-bytes",
            (bonding.interactiveChunkBytes ?: 16384).toString(),
            "--latency-guard-bytes",
            (bonding.latencyGuardBytes ?: 262144).toString(),
            "--interactive-rtt-slack-ms",
            (bonding.interactiveRttSlackMs ?: 30).toString(),
            "--warmup-trigger-bytes",
            (bonding.warmupTriggerBytes ?: 65536).toString(),
            "--warmup-slice-bytes",
            (bonding.warmupSliceBytes ?: 65536).toString(),
            "--warmup-leg-delay-ms",
            (bonding.warmupLegDelayMs ?: 80).toString(),
            "--bulk-promote-bytes",
            (bonding.bulkPromoteBytes ?: 262144).toString(),
            "--leg-cooldown-ms",
            (bonding.legCooldownMs ?: 3000).toString(),
        )
    }

    private fun adaptiveJoinTimeoutMs(
        bonding: BondingContract,
        endpoints: List<ForwardEndpoint>,
    ): Int {
        val configured = max(40, bonding.joinTimeoutMs ?: 400)
        val adaptiveEnabled = bonding.adaptiveJoinTimeout ?: true
        val floor = max(20, bonding.joinTimeoutFloorMs ?: 35)
        val perLeg = max(0, bonding.joinTimeoutPerLegMs ?: 20)
        val ceiling = max(floor, bonding.joinTimeoutCeilingMs ?: min(configured, 220))
        if (!adaptiveEnabled) {
            return max(floor, min(ceiling, configured))
        }

        val wssLegs = max(1, endpoints.size)
        val quicLegs = endpoints.count { renderQuicLegForEndpoint(it, bonding) != null }
        if (quicLegs == 0) {
            val wssOnlyCeiling = max(ceiling, configured)
            return max(floor, min(wssOnlyCeiling, configured))
        }

        val totalLegs = max(1, wssLegs + quicLegs)
        val adaptive = floor + max(0, totalLegs - 1) * perLeg
        return max(floor, min(ceiling, adaptive))
    }

    private fun selectActiveQuicLegs(
        endpoints: List<ForwardEndpoint>,
        bonding: BondingContract,
    ): List<String> {
        val limit = max(0, bonding.activeLegLimit ?: bonding.quicActiveLegLimit ?: 0)
        val selected = mutableListOf<String>()
        endpoints.forEach { endpoint ->
            val leg = renderQuicLegForEndpoint(endpoint, bonding) ?: return@forEach
            selected += leg
            if (limit > 0 && selected.size >= limit) {
                return selected
            }
        }
        return selected
    }

    private fun renderQuicLegForEndpoint(
        endpoint: ForwardEndpoint,
        bonding: BondingContract,
    ): String? {
        val quicEnabled = bonding.quicEnabled ?: true
        val quicViaSlave = bonding.quicViaSlave ?: false
        if (!quicEnabled && !quicViaSlave) {
            return null
        }
        if (!endpoint.supportsQuicLeg) {
            return null
        }
        if (endpoint.host.isBlank() || endpoint.port <= 0) {
            return null
        }

        val params = renderLegQueryParams(bonding.quicPriority ?: 0, endpoint).toMutableList()
        val serverName = bonding.quicServerName?.takeIf { it.isNotBlank() } ?: endpoint.host
        if (serverName.isNotBlank()) {
            params += "server_name=${encodeQueryValue(serverName)}"
        }
        if (bonding.quicInsecure ?: true) {
            params += "insecure=1"
        }
        return "quic://${endpoint.host}:${endpoint.port}?${params.joinToString("&")}"
    }

    private fun renderTcpLeg(
        target: String,
        priority: Int,
        endpoint: ForwardEndpoint,
    ): String {
        return "tcp://$target?${renderLegQueryParams(priority, endpoint).joinToString("&")}"
    }

    private fun renderLegQueryParams(
        priority: Int,
        endpoint: ForwardEndpoint,
    ): List<String> {
        val params = mutableListOf("priority=$priority")
        if (endpoint.endpointId > 0) {
            params += "endpoint_id=${endpoint.endpointId}"
        }
        endpoint.nodeId?.takeIf { it > 0 }?.let { params += "node_id=$it" }
        if (endpoint.host.isNotBlank()) {
            params += "display_host=${encodeQueryValue(endpoint.host)}"
        }
        return params
    }

    private fun encodeQueryValue(value: String): String {
        return URLEncoder.encode(value, StandardCharsets.UTF_8.toString()).replace("+", "%20")
    }

    private data class ProtocolSegment(
        val protocol: String,
        val portOffset: Int,
        val sessionToken: String,
    )

    private data class ProtocolPlan(
        val protocol: String,
        val sessionToken: String,
        val legTargets: List<String>,
    )
}
