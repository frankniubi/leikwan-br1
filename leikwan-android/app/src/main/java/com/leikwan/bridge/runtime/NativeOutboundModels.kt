package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import java.net.InetAddress
import java.security.MessageDigest
import java.time.Instant

internal const val NATIVE_OUTBOUND_LEASE_PROTOCOL = "leikwan-ultra-native-socks5-v1"
internal const val NATIVE_OUTBOUND_PURPOSE = "native_outbound"
internal const val NATIVE_ROUTE_SCOPE_CN_ONLY = "cn_only"
internal const val NATIVE_ROUTE_SCOPE_INTL_ONLY = "intl_only"
internal const val NATIVE_ROUTE_SCOPE_GLOBAL = "global"

/** Pull-scoped, non-secret entitlement. It is absent when the plan does not allow native exit. */
data class NativeOutboundCapability(
    val enabled: Boolean,
    val protocol: String,
    val leaseProtocol: String,
    val transport: String,
    val forwardIds: List<Long>,
    val defaultForwardId: Long?,
    val routeScopes: List<String>,
    val defaultRouteScope: String,
    val forwards: List<NativeOutboundCarrier>,
) {
    fun carrier(forwardId: Long): NativeOutboundCarrier? = forwards.firstOrNull { it.forwardId == forwardId }

    fun isUsable(): Boolean = enabled
        && protocol.equals("socks5", ignoreCase = true)
        && leaseProtocol == NATIVE_OUTBOUND_LEASE_PROTOCOL
        && transport.equals("tcp+udp", ignoreCase = true)
        && forwards.isNotEmpty()
        && forwards.all { it.isUsable() }

    companion object {
        fun from(dto: NativeOutboundCapabilityDto?): NativeOutboundCapability? {
            if (dto == null) return null
            val scopes = dto.routeScopes.mapNotNull(::normalizeScope).distinct()
            val carriers = dto.forwards.mapNotNull { item ->
                val itemScopes = item.routeScopes.mapNotNull(::normalizeScope).distinct()
                if (item.forwardId <= 0L || itemScopes.isEmpty()) return@mapNotNull null
                NativeOutboundCarrier(
                    forwardId = item.forwardId,
                    direction = item.direction.trim().take(191),
                    scopeDigest = item.scopeDigest.trim().lowercase(),
                    transport = item.transport.trim().lowercase(),
                    routeScopes = itemScopes,
                    defaultRouteScope = normalizeScope(item.defaultRouteScope) ?: itemScopes.first(),
                )
            }
            val result = NativeOutboundCapability(
                enabled = dto.enabled,
                protocol = dto.protocol.trim(),
                leaseProtocol = dto.leaseProtocol.trim(),
                transport = dto.transport.trim().lowercase(),
                forwardIds = dto.forwardIds.filter { it > 0L }.distinct(),
                defaultForwardId = dto.defaultForwardId?.takeIf { it > 0L },
                routeScopes = scopes,
                defaultRouteScope = normalizeScope(dto.defaultRouteScope) ?: "global",
                forwards = carriers,
            )
            return result.takeIf { it.isUsable() }
        }

        fun normalizeScope(value: String?): String? {
            val normalized = value?.trim()?.lowercase() ?: return null
            return when (normalized) {
                NATIVE_ROUTE_SCOPE_CN_ONLY,
                "mainland_only",
                "china_only" -> NATIVE_ROUTE_SCOPE_CN_ONLY
                NATIVE_ROUTE_SCOPE_INTL_ONLY,
                "international_only",
                "overseas_only" -> NATIVE_ROUTE_SCOPE_INTL_ONLY
                NATIVE_ROUTE_SCOPE_GLOBAL -> NATIVE_ROUTE_SCOPE_GLOBAL
                else -> null
            }
        }
    }
}

data class NativeOutboundCarrier(
    val forwardId: Long,
    val direction: String,
    val scopeDigest: String,
    val transport: String,
    val routeScopes: List<String>,
    val defaultRouteScope: String,
) {
    fun isUsable(): Boolean = forwardId > 0L
        && direction.isNotBlank()
        && scopeDigest.matches(Regex("^[a-f0-9]{64}$"))
        && transport == "tcp+udp"
        && routeScopes.isNotEmpty()
        && routeScopes.all { NativeOutboundCapability.normalizeScope(it) == it }
        && NativeOutboundCapability.normalizeScope(defaultRouteScope) == defaultRouteScope
        && defaultRouteScope in routeScopes
}

data class NativeOutboundCapabilityDto(
    val enabled: Boolean = false,
    val protocol: String = "",
    @com.google.gson.annotations.SerializedName("lease_protocol") val leaseProtocol: String = "",
    val transport: String = "",
    @com.google.gson.annotations.SerializedName("forward_ids") val forwardIds: List<Long> = emptyList(),
    @com.google.gson.annotations.SerializedName("default_forward_id") val defaultForwardId: Long? = null,
    @com.google.gson.annotations.SerializedName("route_scopes") val routeScopes: List<String> = emptyList(),
    @com.google.gson.annotations.SerializedName("default_route_scope") val defaultRouteScope: String = "global",
    val forwards: List<NativeOutboundCarrierDto> = emptyList(),
)

data class NativeOutboundCarrierDto(
    @com.google.gson.annotations.SerializedName("forward_id") val forwardId: Long = 0L,
    val direction: String = "",
    @com.google.gson.annotations.SerializedName("scope_digest") val scopeDigest: String = "",
    val transport: String = "",
    @com.google.gson.annotations.SerializedName("route_scopes") val routeScopes: List<String> = emptyList(),
    @com.google.gson.annotations.SerializedName("default_route_scope") val defaultRouteScope: String = "global",
)

/** One-shot SOCKS5 credential lease. Never persist this object; call [wipe] after config creation. */
data class NativeOutboundLease(
    val forwardId: Long,
    val leaseId: String,
    val policyGeneration: String,
    val expiresAt: Instant,
    val transport: String,
    val direction: String,
    val routeScope: String,
    val scopeDigest: String,
    val endpointIp: String,
    val endpointPort: Int,
    val user: ByteArray,
    val password: ByteArray,
    val rateLimitMbps: Long = 0L,
    val quotaRemainingBytes: Long = 0L,
    val selectionRef: String = "",
    val selectionTier: String = "unknown",
) {
    fun isUsable(now: Instant = Instant.now()): Boolean = forwardId > 0L
        && leaseId.isNotBlank()
        && policyGeneration.matches(Regex("^[a-f0-9]{64}$"))
        && expiresAt.isAfter(now)
        && !expiresAt.isAfter(now.plusSeconds(330))
        && transport == "tcp+udp"
        && routeScope in setOf(
            NATIVE_ROUTE_SCOPE_CN_ONLY,
            NATIVE_ROUTE_SCOPE_INTL_ONLY,
            NATIVE_ROUTE_SCOPE_GLOBAL,
        )
        && scopeDigest.matches(Regex("^[a-f0-9]{64}$"))
        && direction.isNotBlank()
        && endpointIp.isValidIp()
        && endpointPort in 1..65535
        && user.size in 1..255
        && password.size in 1..255
        && rateLimitMbps in 0L..100_000L
        && quotaRemainingBytes in 1L..107_374_182_400L
        && selectionRef.matches(Regex("^[A-Za-z0-9_-]{16,128}$"))
        && selectionTier in setOf("paid", "mixed", "free", "unknown")

    fun toControlPayload(): JsonObject = JsonObject().apply {
        addProperty("protocol", NATIVE_OUTBOUND_LEASE_PROTOCOL)
        addProperty("purpose", NATIVE_OUTBOUND_PURPOSE)
        addProperty("forward_id", forwardId)
        addProperty("lease_id", leaseId)
        addProperty("policy_generation", policyGeneration)
        addProperty("expires_at", expiresAt.toString())
        addProperty("transport", transport)
        addProperty("rate_limit_mbps", rateLimitMbps)
        addProperty("quota_remaining_bytes", quotaRemainingBytes)
        addProperty("target_digest", scopeDigest)
        addProperty("selection_ref", selectionRef)
        addProperty("selection_tier", selectionTier)
        addProperty("direction", direction)
        addProperty("route_scope", routeScope)
        add("endpoint", JsonObject().apply {
            addProperty("ip", endpointIp)
            addProperty("port", endpointPort)
        })
        add("credential", JsonObject().apply {
            addProperty("user", user.toString(Charsets.UTF_8))
            addProperty("password", password.toString(Charsets.UTF_8))
        })
    }

    fun wipe() {
        user.fill(0)
        password.fill(0)
    }

    companion object {
        fun scopeDigest(forwardId: Long, direction: String, transport: String, routeScope: String): String {
            val canonicalScope = NativeOutboundCapability.normalizeScope(routeScope)
                ?: routeScope.trim().lowercase()
            val canonical = listOf(
                "native-outbound:v1",
                forwardId.toString(),
                direction.trim().lowercase(),
                transport.trim().lowercase(),
                canonicalScope,
            ).joinToString("\n")
            return MessageDigest.getInstance("SHA-256")
                .digest(canonical.toByteArray(Charsets.UTF_8))
                .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
        }

        fun from(
            raw: JsonObject,
            policy: UltraPolicy,
            carrier: NativeOutboundCarrier,
            routeScope: String,
            now: Instant = Instant.now(),
        ): NativeOutboundLease? {
            val protocol = raw.stringOrNull("protocol") ?: return null
            val purpose = raw.stringOrNull("purpose") ?: return null
            val forwardId = raw.longOrNull("forward_id") ?: return null
            val generation = raw.stringOrNull("policy_generation") ?: return null
            val leaseId = raw.stringOrNull("lease_id") ?: return null
            val expiresAt = raw.instantOrNull("expires_at") ?: return null
            val transport = raw.stringOrNull("transport")?.lowercase() ?: return null
            val rateLimit = raw.longOrNull("rate_limit_mbps") ?: policy.rateLimitMbps
            val quota = raw.longOrNull("quota_remaining_bytes") ?: policy.quotaRemainingBytes
            val selectionRef = raw.stringOrNull("selection_ref") ?: derivedSelectionRef(leaseId)
            val selectionTier = raw.stringOrNull("selection_tier")?.lowercase() ?: "unknown"
            val direction = raw.stringOrNull("direction") ?: return null
            val requestedScope = NativeOutboundCapability.normalizeScope(routeScope) ?: return null
            if (requestedScope !in carrier.routeScopes) return null
            val returnedScope = NativeOutboundCapability.normalizeScope(raw.stringOrNull("route_scope")) ?: return null
            val digest = raw.stringOrNull("scope_digest")?.lowercase() ?: return null
            val endpoint = raw.objectOrNull("endpoint") ?: return null
            val endpointIp = endpoint.stringOrNull("ip")?.normalizeIp() ?: return null
            val endpointPort = endpoint.longOrNull("port")?.toInt() ?: return null
            val credential = raw.objectOrNull("credential") ?: return null
            val userText = credential.stringOrNull("user") ?: return null
            val passwordText = credential.stringOrNull("password") ?: return null
            val user = userText.toByteArray(Charsets.UTF_8)
            val password = passwordText.toByteArray(Charsets.UTF_8)
            val expected = scopeDigest(forwardId, carrier.direction, transport, requestedScope)
            val lease = NativeOutboundLease(
                forwardId = forwardId,
                leaseId = leaseId,
                policyGeneration = generation,
                expiresAt = expiresAt,
                transport = transport,
                direction = direction,
                routeScope = returnedScope,
                scopeDigest = digest,
                endpointIp = endpointIp,
                endpointPort = endpointPort,
                user = user,
                password = password,
                rateLimitMbps = rateLimit,
                quotaRemainingBytes = quota,
                selectionRef = selectionRef,
                selectionTier = selectionTier,
            )
            val valid = protocol == NATIVE_OUTBOUND_LEASE_PROTOCOL
                && purpose == NATIVE_OUTBOUND_PURPOSE
                && forwardId == policy.forwardId
                && generation == policy.policyGeneration
                && direction == carrier.direction
                && returnedScope == requestedScope
                && digest == expected
                && digest == scopeDigest(forwardId, carrier.direction, carrier.transport, requestedScope)
                && rateLimit in 0L..policy.rateLimitMbps
                && quota in 1L..policy.quotaRemainingBytes
                && selectionRef.matches(Regex("^[A-Za-z0-9_-]{16,128}$"))
                && selectionTier in setOf("paid", "mixed", "free", "unknown")
                && leaseId.length in 1..128
                && expiresAt.isAfter(now)
                && !expiresAt.isAfter(policy.expiresAt.plusSeconds(1))
                && lease.isUsable(now)
            if (!valid) {
                lease.wipe()
                return null
            }
            return lease
        }

        private fun derivedSelectionRef(leaseId: String): String {
            val digest = MessageDigest.getInstance("SHA-256")
                .digest(("native-selection\n" + leaseId).toByteArray(Charsets.UTF_8))
            return "native-" + digest.joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
        }
    }
}

private fun JsonObject.stringOrNull(name: String): String? = get(name)
    ?.takeIf { !it.isJsonNull && it.isJsonPrimitive }
    ?.let { runCatching { it.asString.trim().takeIf(String::isNotEmpty) }.getOrNull() }

private fun JsonObject.longOrNull(name: String): Long? = get(name)
    ?.takeIf { !it.isJsonNull && it.isJsonPrimitive }
    ?.let { runCatching { it.asLong }.getOrNull() }

private fun JsonObject.objectOrNull(name: String): JsonObject? = get(name)
    ?.takeIf { it.isJsonObject }
    ?.asJsonObject

private fun JsonObject.instantOrNull(name: String): Instant? = stringOrNull(name)
    ?.let { runCatching { Instant.parse(it) }.getOrNull() }

private fun String.isValidIp(): Boolean = runCatching { InetAddress.getByName(this) }.isSuccess

private fun String.normalizeIp(): String? {
    val value = trim().removeSurrounding("[", "]")
    if (value.isBlank() || (!value.contains('.') && !value.contains(':'))) return null
    return runCatching { InetAddress.getByName(value).hostAddress?.substringBefore('%')?.lowercase() }.getOrNull()
}
