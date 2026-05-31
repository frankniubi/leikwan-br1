package com.leikwan.bridge.runtime

import com.google.gson.JsonElement
import com.google.gson.JsonObject

data class ForwardEndpoint(
    val endpointId: Int,
    val node: String,
    val host: String,
    val port: Int,
    val cdnHost: String?,
    val priority: Int,
    val weight: Int,
    val healthStatus: String,
    val supportsQuicLeg: Boolean,
    val ingressProtocol: String?,
    val ingressAuthToken: String?,
    val dynamicPriority: Int? = null,
    val nodeId: Int? = null,
)

data class BondingContract(
    val ingressMode: String?,
    val routeKey: String?,
    val sessionToken: String?,
    val udpSessionToken: String?,
    val chunkSize: Int?,
    val maxWindowBytes: Int?,
    val retransmitMs: Int?,
    val connectTimeoutMs: Int?,
    val joinTimeoutMs: Int?,
    val interactiveChunkBytes: Int?,
    val latencyGuardBytes: Int?,
    val interactiveRttSlackMs: Int?,
    val warmupTriggerBytes: Int?,
    val warmupSliceBytes: Int?,
    val warmupLegDelayMs: Int?,
    val bulkPromoteBytes: Int?,
    val legCooldownMs: Int?,
    val remoteTarget: String? = null,
    val adaptiveJoinTimeout: Boolean? = null,
    val joinTimeoutFloorMs: Int? = null,
    val joinTimeoutCeilingMs: Int? = null,
    val joinTimeoutPerLegMs: Int? = null,
    val quicEnabled: Boolean? = null,
    val quicViaSlave: Boolean? = null,
    val activeLegLimit: Int? = null,
    val quicActiveLegLimit: Int? = null,
    val wssStandbyEnabled: Boolean? = null,
    val disableLocalRateLimit: Boolean? = null,
    val quicServerName: String? = null,
    val quicInsecure: Boolean? = null,
    val quicPriority: Int? = null,
    val wsPriority: Int? = null,
)

data class MptcpContract(
    val sidecarHost: String?,
    val sidecarPort: Int?,
)

data class ForwardContract(
    val forwardId: Long,
    val serviceName: String,
    val target: String,
    val listenPort: Int,
    val mode: ForwardMode,
    val transport: String,
    val serviceStatus: String,
    val endpointMode: String,
    val sni: String,
    val pathPrefix: String,
    val speedLimitMbps: Int?,
    val endpoints: List<ForwardEndpoint>,
    val bonding: BondingContract?,
    val mptcp: MptcpContract?,
) {
    fun toListItem(): ForwardListItem {
        return ForwardListItem(
            forwardId = forwardId,
            serviceName = serviceName,
            target = target,
            listenPort = listenPort,
            mode = mode,
            transport = transport,
            serviceStatus = serviceStatus,
            endpointMode = endpointMode,
            speedLimitMbps = speedLimitMbps,
        )
    }

    fun withListenPort(listenPort: Int): ForwardContract = copy(listenPort = listenPort)
}

data class ForwardListItem(
    val forwardId: Long,
    val serviceName: String,
    val target: String,
    val listenPort: Int,
    val mode: ForwardMode,
    val transport: String,
    val serviceStatus: String,
    val endpointMode: String,
    val speedLimitMbps: Int?,
)

data class ForwardCatalog(
    val visibleForwards: List<ForwardListItem>,
    val hiddenUnsupportedMptcpCount: Int,
    val hiddenForwardIds: Set<Long>,
    val selectedForwardId: Long?,
)

object ForwardContractParser {
    fun parse(raw: JsonElement): ForwardContract? {
        val obj = raw.takeIf { it.isJsonObject }?.asJsonObject ?: return null
        val forwardId = obj.longOrNull("forward_id") ?: return null
        val mode = ForwardMode.normalize(obj.stringOrEmpty("mode"))
        val endpoints = obj.arrayOrEmpty("endpoints").mapNotNull { endpoint ->
            val item = endpoint.takeIf { it.isJsonObject }?.asJsonObject ?: return@mapNotNull null
            val host = item.stringOrEmpty("host")
            val port = item.intOrNull("port") ?: 0
            if (host.isBlank() || port <= 0) {
                return@mapNotNull null
            }
            ForwardEndpoint(
                endpointId = item.intOrNull("endpoint_id") ?: item.intOrNull("id") ?: 0,
                node = item.stringOrEmpty("node"),
                host = host,
                port = port,
                cdnHost = item.stringOrNull("cdn_host"),
                priority = item.intOrNull("priority") ?: 0,
                weight = item.intOrNull("weight") ?: 0,
                healthStatus = item.stringOrEmpty("health_status"),
                supportsQuicLeg = item.boolOrNull("supports_quic_leg") ?: false,
                ingressProtocol = item.stringOrNull("ingress_protocol"),
                ingressAuthToken = item.stringOrNull("ingress_auth_token"),
                dynamicPriority = item.intOrNull("dynamic_priority"),
                nodeId = item.intOrNull("node_id"),
            )
        }

        return ForwardContract(
            forwardId = forwardId,
            serviceName = obj.stringOrEmpty("service_name"),
            target = obj.stringOrEmpty("target"),
            listenPort = obj.intOrNull("listen_port") ?: 0,
            mode = mode,
            transport = obj.stringOrEmpty("transport"),
            serviceStatus = obj.stringOrEmpty("service_status"),
            endpointMode = obj.stringOrEmpty("endpoint_mode"),
            sni = obj.stringOrEmpty("sni"),
            pathPrefix = obj.stringOrEmpty("path_prefix"),
            speedLimitMbps = obj.intOrNull("speed_limit_mbps"),
            endpoints = endpoints,
            bonding = obj.objectOrNull("bonding")?.toBondingContract(),
            mptcp = obj.objectOrNull("mptcp")?.toMptcpContract(),
        )
    }
}

private fun JsonObject.toBondingContract(): BondingContract {
    return BondingContract(
        ingressMode = stringOrNull("ingress_mode"),
        routeKey = stringOrNull("route_key"),
        sessionToken = stringOrNull("session_token"),
        udpSessionToken = stringOrNull("udp_session_token"),
        chunkSize = intOrNull("chunk_size"),
        maxWindowBytes = intOrNull("max_window_bytes"),
        retransmitMs = intOrNull("retransmit_ms"),
        connectTimeoutMs = intOrNull("connect_timeout_ms"),
        joinTimeoutMs = intOrNull("join_timeout_ms"),
        interactiveChunkBytes = intOrNull("interactive_chunk_bytes"),
        latencyGuardBytes = intOrNull("latency_guard_bytes"),
        interactiveRttSlackMs = intOrNull("interactive_rtt_slack_ms"),
        warmupTriggerBytes = intOrNull("warmup_trigger_bytes"),
        warmupSliceBytes = intOrNull("warmup_slice_bytes"),
        warmupLegDelayMs = intOrNull("warmup_leg_delay_ms"),
        bulkPromoteBytes = intOrNull("bulk_promote_bytes"),
        legCooldownMs = intOrNull("leg_cooldown_ms"),
        remoteTarget = stringOrNull("remote_target"),
        adaptiveJoinTimeout = boolOrNull("adaptive_join_timeout"),
        joinTimeoutFloorMs = intOrNull("join_timeout_floor_ms"),
        joinTimeoutCeilingMs = intOrNull("join_timeout_ceiling_ms"),
        joinTimeoutPerLegMs = intOrNull("join_timeout_per_leg_ms"),
        quicEnabled = boolOrNull("quic_enabled"),
        quicViaSlave = boolOrNull("quic_via_slave"),
        activeLegLimit = intOrNull("active_leg_limit"),
        quicActiveLegLimit = intOrNull("quic_active_leg_limit"),
        wssStandbyEnabled = boolOrNull("wss_standby_enabled"),
        disableLocalRateLimit = boolOrNull("disable_local_rate_limit"),
        quicServerName = stringOrNull("quic_server_name"),
        quicInsecure = boolOrNull("quic_insecure"),
        quicPriority = intOrNull("quic_priority"),
        wsPriority = intOrNull("ws_priority"),
    )
}

private fun JsonObject.toMptcpContract(): MptcpContract {
    return MptcpContract(
        sidecarHost = stringOrNull("sidecar_host"),
        sidecarPort = intOrNull("sidecar_port"),
    )
}

private fun JsonObject.stringOrEmpty(name: String): String = stringOrNull(name) ?: ""

private fun JsonObject.stringOrNull(name: String): String? {
    val value = get(name) ?: return null
    if (value.isJsonNull) return null
    return runCatching { value.asString.trim() }.getOrNull()?.takeIf { it.isNotEmpty() }
}

private fun JsonObject.intOrNull(name: String): Int? {
    val value = get(name) ?: return null
    if (value.isJsonNull) return null
    return runCatching { value.asInt }.getOrElse {
        runCatching { value.asString.trim().toInt() }.getOrNull()
    }
}

private fun JsonObject.longOrNull(name: String): Long? {
    val value = get(name) ?: return null
    if (value.isJsonNull) return null
    return runCatching { value.asLong }.getOrElse {
        runCatching { value.asString.trim().toLong() }.getOrNull()
    }
}

private fun JsonObject.boolOrNull(name: String): Boolean? {
    val value = get(name) ?: return null
    if (value.isJsonNull) return null
    return runCatching { value.asBoolean }.getOrElse {
        when (value.asString.trim().lowercase()) {
            "1", "true", "yes", "on" -> true
            "0", "false", "no", "off" -> false
            else -> null
        }
    }
}

private fun JsonObject.objectOrNull(name: String): JsonObject? {
    val value = get(name) ?: return null
    return value.takeIf { it.isJsonObject }?.asJsonObject
}

private fun JsonObject.arrayOrEmpty(name: String): List<JsonElement> {
    val value = get(name) ?: return emptyList()
    return value.takeIf { it.isJsonArray }?.asJsonArray?.toList() ?: emptyList()
}
