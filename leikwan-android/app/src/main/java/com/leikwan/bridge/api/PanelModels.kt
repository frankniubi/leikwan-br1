package com.leikwan.bridge.api

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

data class PullConfigsResponse(
    val ok: Boolean,
    val client: JsonElement,
    val forwards: List<JsonElement>,
    @SerializedName("route_directions") val routeDirections: List<RouteDirectionDto>? = null,
    @SerializedName("route_rule_entitlements") val routeRuleEntitlements: List<RouteRuleEntitlementDto>? = null,
    @SerializedName("client_rules") val clientRules: List<ClientRouteRuleDto>? = null,
    @SerializedName("direction_runtime_bindings") val directionRuntimeBindings: List<DirectionRuntimeBindingDto>? = null,
    @SerializedName("capabilities") val routeRuleCapabilities: RouteRuleCapabilitiesDto? = null,
)

data class HeartbeatRequest(
    @SerializedName("client_platform") val clientPlatform: String = "android",
    @SerializedName("os_release") val osRelease: String = android.os.Build.VERSION.RELEASE.orEmpty(),
    @SerializedName("supports_route_direction_rules") val supportsRouteDirectionRules: Boolean = true,
    @SerializedName("supports_exact_destination_rules") val supportsExactDestinationRules: Boolean = true,
)

data class HeartbeatResponse(val ok: Boolean)

data class RouteDirectionDto(
    val id: String = "",
    val label: String = "",
    val status: String = "",
    @SerializedName("sort_order") val sortOrder: Int = 100,
)

data class RouteRuleEntitlementDto(
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("service_id") val serviceId: Long = 0L,
    @SerializedName("subscription_id") val subscriptionId: Long? = null,
    @SerializedName("max_rules") val maxRules: Int = 0,
    @SerializedName("remaining_rules") val remainingRules: Int = 0,
    @SerializedName("allowed_direction_ids") val allowedDirectionIds: List<String> = emptyList(),
)

data class ClientRouteRuleDto(
    val id: String = "",
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("destination_ip") val destinationIp: String = "",
    @SerializedName("destination_port") val destinationPort: Int = 0,
    val protocol: String = "tcp_udp",
    @SerializedName("direction_id") val directionId: String = "",
    val enabled: Boolean = false,
    @SerializedName("effective_state") val effectiveState: String = "unavailable",
    @SerializedName("sync_version") val syncVersion: Long = 0L,
)

data class DirectionRuntimeBindingDto(
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("direction_id") val directionId: String = "",
    @SerializedName("binding_state") val bindingState: String = "missing",
    @SerializedName("client_runtime_binding_id") val clientRuntimeBindingId: String = "",
    @SerializedName("runtime_ref") val runtimeRef: DirectionRuntimeRefDto? = null,
    @SerializedName("local_tcp_port") val localTcpPort: Int = 0,
    @SerializedName("local_udp_port") val localUdpPort: Int = 0,
    @SerializedName("local_endpoint") val localEndpoint: DirectionLocalEndpointDto? = null,
)

data class DirectionRuntimeRefDto(
    val type: String = "",
    @SerializedName("forward_id") val forwardId: Long? = null,
    val selectable: Boolean = false,
    val transport: String = "tcp_udp",
)

data class DirectionLocalEndpointDto(
    val host: String = "",
    @SerializedName("tcp_port") val tcpPort: Int = 0,
    @SerializedName("udp_port") val udpPort: Int = 0,
)

data class RouteRuleCapabilitiesDto(
    @SerializedName("route_direction_rules") val routeDirectionRules: Boolean? = null,
    @SerializedName("exact_destination_rules") val exactDestinationRules: Boolean? = null,
)

data class ClientRuleWriteRequest(
    @SerializedName("service_scope_id") val serviceScopeId: String? = null,
    @SerializedName("destination_ip") val destinationIp: String? = null,
    @SerializedName("destination_port") val destinationPort: Int? = null,
    @SerializedName("direction_id") val directionId: String? = null,
    val enabled: Boolean? = null,
    @SerializedName("sync_version") val syncVersion: Long? = null,
)

data class ClientRuleWriteResponse(
    val ok: Boolean,
    val error: String? = null,
    val message: String? = null,
    @SerializedName("client_rule") val clientRule: ClientRouteRuleDto? = null,
)

data class ForwardInfo(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("service_name") val serviceName: String = "",
    val target: String = "",
    @SerializedName("listen_port") val listenPort: Int = 0,
    val mode: String = "",
    val transport: String = "",
    @SerializedName("service_status") val serviceStatus: String = "",
    @SerializedName("endpoint_mode") val endpointMode: String = "",
    val endpoints: List<JsonElement> = emptyList(),
    @SerializedName("speed_limit_mbps") val speedLimitMbps: Int? = null,
)
