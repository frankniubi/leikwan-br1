package com.leikwan.bridge.clientrules

import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto

enum class RouteDirectionStatus(val wireValue: String) {
    AVAILABLE("available"),
    UNAVAILABLE("unavailable"),
    DISABLED("disabled"),
    UNKNOWN("unknown");

    companion object {
        fun fromWire(value: String?): RouteDirectionStatus = entries.firstOrNull { it.wireValue == value } ?: UNKNOWN
    }
}

enum class ClientRuleEffectiveState(val wireValue: String) {
    ACTIVE("active"),
    DISABLED("disabled"),
    UNAVAILABLE("unavailable"),
    NOT_ALLOWED_BY_PLAN("not_allowed_by_plan"),
    OVER_QUOTA("over_quota"),
    RUNTIME_MISSING("runtime_missing"),
    SYNCING("syncing"),
    ERROR("error");

    companion object {
        fun fromWire(value: String?): ClientRuleEffectiveState = entries.firstOrNull { it.wireValue == value } ?: UNAVAILABLE
    }
}

enum class PendingClientRuleOperation(val wireValue: String) {
    NONE("none"),
    CREATE("create"),
    UPDATE("update"),
    DELETE("delete");

    companion object {
        fun fromWire(value: String?): PendingClientRuleOperation = entries.firstOrNull { it.wireValue == value } ?: NONE
    }
}

data class RouteDirection(
    val id: String,
    val label: String,
    val status: String,
    val sortOrder: Int,
) {
    val state: RouteDirectionStatus
        get() = RouteDirectionStatus.fromWire(status)
}

data class RouteRuleEntitlement(
    val serviceScopeId: String,
    val serviceId: Long,
    val subscriptionId: Long?,
    val maxRules: Int,
    val remainingRules: Int,
    val allowedDirectionIds: List<String>,
)

data class ClientRouteRule(
    val id: String,
    val serviceScopeId: String,
    val destinationIp: String,
    val destinationPort: Int,
    val protocol: String = "tcp_udp",
    val directionId: String,
    val enabled: Boolean,
    val effectiveState: ClientRuleEffectiveState,
    val syncVersion: Long,
    val pendingOperation: PendingClientRuleOperation = PendingClientRuleOperation.NONE,
    val pendingError: String? = null,
    val deletedAtMs: Long? = null,
    val updatedAtMs: Long,
) {
    val installable: Boolean
        get() = enabled &&
            protocol == "tcp_udp" &&
            deletedAtMs == null &&
            pendingOperation != PendingClientRuleOperation.DELETE &&
            effectiveState == ClientRuleEffectiveState.ACTIVE
}

data class DirectionRuntimeBinding(
    val serviceScopeId: String,
    val directionId: String,
    val bindingState: String,
    val clientRuntimeBindingId: String,
    val localTcpPort: Int,
    val localUdpPort: Int,
    val runtimeForwardId: Long?,
    val selectable: Boolean,
    val transport: String,
) {
    val readyForAndroid: Boolean
        get() = bindingState == "ready" &&
            runtimeForwardId != null &&
            localTcpPort in 1..65535 &&
            localUdpPort in 1..65535 &&
            localTcpPort == localUdpPort &&
            transport == "tcp_udp"
}

data class RouteRuleCapabilities(
    val routeDirectionRules: Boolean = false,
    val exactDestinationRules: Boolean = false,
)

data class ClientRouteRuleSnapshot(
    val directions: List<RouteDirection> = emptyList(),
    val entitlements: List<RouteRuleEntitlement> = emptyList(),
    val rules: List<ClientRouteRule> = emptyList(),
    val bindings: List<DirectionRuntimeBinding> = emptyList(),
    val capabilities: RouteRuleCapabilities = RouteRuleCapabilities(),
    val updatedAtMs: Long = 0L,
) {
    fun directionLabel(directionId: String): String = directions.firstOrNull { it.id == directionId }?.label ?: directionId
    fun bindingFor(directionId: String): DirectionRuntimeBinding? = bindings.firstOrNull { it.directionId == directionId }
    fun entitlementFor(serviceScopeId: String): RouteRuleEntitlement? = entitlements.firstOrNull { it.serviceScopeId == serviceScopeId }
}

data class ClientRuleWriteDraft(
    val serviceScopeId: String? = null,
    val destinationIp: String? = null,
    val destinationPort: Int? = null,
    val directionId: String? = null,
    val enabled: Boolean? = null,
    val syncVersion: Long? = null,
)

data class ClientRuleValidationResult(val error: String? = null)

fun RouteDirectionDto.toDomain(): RouteDirection = RouteDirection(
    id = id,
    label = label,
    status = status,
    sortOrder = sortOrder,
)

fun RouteRuleEntitlementDto.toDomain(): RouteRuleEntitlement = RouteRuleEntitlement(
    serviceScopeId = serviceScopeId,
    serviceId = serviceId,
    subscriptionId = subscriptionId,
    maxRules = maxRules,
    remainingRules = remainingRules,
    allowedDirectionIds = allowedDirectionIds,
)

fun ClientRouteRuleDto.toDomain(nowMs: Long): ClientRouteRule = ClientRouteRule(
    id = id,
    serviceScopeId = serviceScopeId,
    destinationIp = destinationIp,
    destinationPort = destinationPort,
    protocol = protocol.ifBlank { "tcp_udp" },
    directionId = directionId,
    enabled = enabled,
    effectiveState = ClientRuleEffectiveState.fromWire(effectiveState),
    syncVersion = syncVersion,
    updatedAtMs = nowMs,
)

fun DirectionRuntimeBindingDto.toDomain(): DirectionRuntimeBinding {
    val effectiveTcpPort = localTcpPort.takeIf { it > 0 } ?: (localEndpoint?.tcpPort ?: 0)
    val effectiveUdpPort = localUdpPort.takeIf { it > 0 } ?: (localEndpoint?.udpPort ?: 0)
    return DirectionRuntimeBinding(
        serviceScopeId = serviceScopeId,
        directionId = directionId,
        bindingState = bindingState,
        clientRuntimeBindingId = clientRuntimeBindingId,
        localTcpPort = effectiveTcpPort,
        localUdpPort = effectiveUdpPort,
        runtimeForwardId = runtimeRef?.forwardId,
        selectable = runtimeRef?.selectable ?: false,
        transport = runtimeRef?.transport ?: "tcp_udp",
    )
}

fun RouteRuleCapabilitiesDto?.toDomain(): RouteRuleCapabilities = RouteRuleCapabilities(
    routeDirectionRules = this?.routeDirectionRules == true,
    exactDestinationRules = this?.exactDestinationRules == true,
)
