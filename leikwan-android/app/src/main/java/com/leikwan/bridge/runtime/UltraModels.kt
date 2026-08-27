package com.leikwan.bridge.runtime

import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import java.net.InetAddress
import java.security.MessageDigest
import java.time.Instant

private const val ULTRA_POLICY_PROTOCOL = "leikwan-ultra-policy-v2"
private const val ULTRA_MAX_SESSION_TICKET_LENGTH = 4096
private const val ULTRA_MAX_TTL_SECONDS = 300L
private const val ULTRA_MAX_QUOTA_BYTES = 107_374_182_400L
private const val ULTRA_MAX_RATE_MBPS = 100_000L

/** A short-lived, forward-bound authorization returned from the Panel. */
data class UltraPolicy(
    val forwardId: Long,
    val sessionTicket: String,
    val policyGeneration: String,
    val issuedAt: Instant,
    val renewAfter: Instant,
    val expiresAt: Instant,
    val transport: String,
    val rateLimitMbps: Long,
    val quotaRemainingBytes: Long,
    val targetDigest: String,
) {
    companion object {
        fun from(forward: ForwardContract, now: Instant = Instant.now()): UltraPolicy? {
            if (!forward.backendMode.equals("ultra", ignoreCase = true)
                || !forward.serviceStatus.equals("active", ignoreCase = true)) {
                return null
            }
            val access = forward.ultraAccess ?: return null
            val protocol = access.requiredString("protocol") ?: return null
            val forwardId = access.long("forward_id") ?: return null
            val ticket = access.requiredString("session_ticket") ?: return null
            val generation = access.requiredString("policy_generation") ?: return null
            val issuedAt = access.instant("issued_at") ?: return null
            val renewAfter = access.instant("renew_after") ?: return null
            val expiresAt = access.instant("expires_at") ?: return null
            val serviceState = access.requiredString("service_state") ?: return null
            val transport = access.requiredString("transport")?.lowercase() ?: return null
            val rateLimit = access.long("rate_limit_mbps") ?: return null
            val quota = access.long("quota_remaining_bytes") ?: return null
            val policyTargetDigest = access.requiredString("target_digest") ?: return null

            if (protocol != ULTRA_POLICY_PROTOCOL
                || forwardId != forward.forwardId
                || !serviceState.equals("active", ignoreCase = true)
                || ticket.length !in 1..ULTRA_MAX_SESSION_TICKET_LENGTH
                || !generation.isLowerHex64()
                || !policyTargetDigest.isLowerHex64()
                || policyTargetDigest != targetDigest(forward.target)
                || !issuedAt.isBefore(renewAfter)
                || !renewAfter.isBefore(expiresAt)
                || !expiresAt.isAfter(now)
                || expiresAt.isAfter(now.plusSeconds(ULTRA_MAX_TTL_SECONDS))
                || transport !in setOf("tcp", "udp", "tcp+udp")
                || rateLimit !in 0L..ULTRA_MAX_RATE_MBPS
                || quota !in 1L..ULTRA_MAX_QUOTA_BYTES) {
                return null
            }
            return UltraPolicy(
                forwardId = forwardId,
                sessionTicket = ticket,
                policyGeneration = generation,
                issuedAt = issuedAt,
                renewAfter = renewAfter,
                expiresAt = expiresAt,
                transport = transport,
                rateLimitMbps = rateLimit,
                quotaRemainingBytes = quota,
                targetDigest = policyTargetDigest,
            )
        }

        fun targetDigest(target: String): String = MessageDigest.getInstance("SHA-256")
            .digest(target.trim().toByteArray(Charsets.UTF_8))
            .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
    }
}

/** One-shot provider material. It is never persisted and is wiped after control-socket injection. */
data class UltraLease(
    val leaseId: String,
    val forwardId: Long,
    val sessionTicket: String,
    val policyGeneration: String,
    val expiresAt: Instant,
    val transport: String,
    val rateLimitMbps: Long,
    val quotaRemainingBytes: Long,
    val selectionRef: String,
    val targetDigest: String,
    val nonceHash: String,
    val endpointIp: String,
    val endpointPort: Int,
    val user: ByteArray,
    val password: ByteArray,
    val directConnectivity: ProtectedConnectivitySet,
) {
    fun toControlPayload(): JsonObject = JsonObject().apply {
        addProperty("protocol", ULTRA_POLICY_PROTOCOL)
        addProperty("forward_id", forwardId)
        addProperty("session_ticket", sessionTicket)
        addProperty("policy_generation", policyGeneration)
        addProperty("lease_id", leaseId)
        addProperty("expires_at", expiresAt.toString())
        addProperty("transport", transport)
        addProperty("rate_limit_mbps", rateLimitMbps)
        addProperty("quota_remaining_bytes", quotaRemainingBytes)
        addProperty("target_digest", targetDigest)
        addProperty("selection_ref", selectionRef)
        addProperty("nonce_hash", nonceHash)
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
        fun from(raw: JsonObject, policy: UltraPolicy, now: Instant = Instant.now()): UltraLease? {
            val protocol = raw.requiredString("protocol") ?: return null
            val forwardId = raw.long("forward_id") ?: return null
            val sessionTicket = raw.requiredString("session_ticket") ?: return null
            val policyGeneration = raw.requiredString("policy_generation") ?: return null
            val leaseId = raw.requiredString("lease_id") ?: return null
            val expiresAt = raw.instant("expires_at") ?: return null
            val transport = raw.requiredString("transport")?.lowercase() ?: return null
            val rateLimit = raw.long("rate_limit_mbps") ?: return null
            val quota = raw.long("quota_remaining_bytes") ?: return null
            val selectionRef = raw.requiredString("selection_ref") ?: return null
            val targetDigest = raw.requiredString("target_digest") ?: return null
            val nonceHash = raw.requiredString("nonce_hash") ?: return null
            val endpoint = raw.objectValue("endpoint") ?: return null
            val endpointIp = endpoint.requiredString("ip")?.normalizedIpLiteral() ?: return null
            val endpointPort = endpoint.long("port")?.toInt() ?: return null
            val credential = raw.objectValue("credential") ?: return null
            val userText = credential.requiredString("user") ?: return null
            val passwordText = credential.requiredString("password") ?: return null
            val user = userText.toByteArray(Charsets.UTF_8)
            val password = passwordText.toByteArray(Charsets.UTF_8)
            val directConnectivity = directConnectivity(raw.arrayValue("direct_ip_cidrs"), endpointIp)

            if (directConnectivity == null
                || protocol != ULTRA_POLICY_PROTOCOL
                || forwardId != policy.forwardId
                || sessionTicket != policy.sessionTicket
                || policyGeneration != policy.policyGeneration
                || leaseId.length !in 16..128
                || !expiresAt.isAfter(now)
                || expiresAt.isAfter(policy.expiresAt.plusSeconds(1))
                || transport != policy.transport
                || rateLimit !in 0L..policy.rateLimitMbps
                || quota !in 1L..policy.quotaRemainingBytes
                || selectionRef.matches(Regex("^[A-Za-z0-9_-]{16,128}$")).not()
                || targetDigest != policy.targetDigest
                || !nonceHash.isLowerHex64()
                || endpointPort !in 1..65535
                || user.size !in 1..255
                || password.size !in 1..255) {
                user.fill(0)
                password.fill(0)
                return null
            }

            return UltraLease(
                leaseId = leaseId,
                forwardId = forwardId,
                sessionTicket = sessionTicket,
                policyGeneration = policyGeneration,
                expiresAt = expiresAt,
                transport = transport,
                rateLimitMbps = rateLimit,
                quotaRemainingBytes = quota,
                selectionRef = selectionRef,
                targetDigest = targetDigest,
                nonceHash = nonceHash,
                endpointIp = endpointIp,
                endpointPort = endpointPort,
                user = user,
                password = password,
                directConnectivity = directConnectivity,
            )
        }

        private fun directConnectivity(rawCidrs: JsonArray?, endpointIp: String): ProtectedConnectivitySet? {
            val cidrs = rawCidrs?.map { item -> item.requiredStringValue() ?: return null }.orEmpty()
            if (cidrs.any { cidr -> ProtectedConnectivitySet.of(cidrs = listOf(cidr)).ipCidrs.isEmpty() }) {
                return null
            }
            return ProtectedConnectivitySet.fromEndpoints(listOf(endpointIp))
                .merge(ProtectedConnectivitySet.of(cidrs = cidrs))
        }
    }
}

private fun JsonObject.requiredString(name: String): String? = get(name).requiredStringValue()

private fun JsonElement?.requiredStringValue(): String? {
    if (this == null || isJsonNull || !isJsonPrimitive) return null
    return runCatching { asString.trim() }.getOrNull()?.takeIf { it.isNotEmpty() }
}

private fun JsonObject.long(name: String): Long? {
    val value = get(name) ?: return null
    if (value.isJsonNull || !value.isJsonPrimitive) return null
    return runCatching { value.asLong }.getOrNull()
}

private fun JsonObject.instant(name: String): Instant? = requiredString(name)
    ?.let { value -> runCatching { Instant.parse(value) }.getOrNull() }

private fun JsonObject.objectValue(name: String): JsonObject? = get(name)?.takeIf { it.isJsonObject }?.asJsonObject

private fun JsonObject.arrayValue(name: String): JsonArray? = get(name)?.takeIf { it.isJsonArray }?.asJsonArray

private fun String.isLowerHex64(): Boolean = matches(Regex("^[a-f0-9]{64}$"))

private fun String.normalizedIpLiteral(): String? {
    val value = trim().removeSurrounding("[", "]")
    if (value.isBlank() || (!value.contains('.') && !value.contains(':')) || !value.matches(Regex("^[0-9a-fA-F:.]+$"))) {
        return null
    }
    val address = runCatching { InetAddress.getByName(value) }.getOrNull() ?: return null
    return address.hostAddress?.substringBefore('%')?.lowercase()
}
