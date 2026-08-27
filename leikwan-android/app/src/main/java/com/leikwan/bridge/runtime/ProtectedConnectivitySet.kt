package com.leikwan.bridge.runtime

import java.net.InetAddress

/**
 * Non-overridable network paths needed to keep control-plane and transport
 * connectivity out of the VPN tunnel. CIDRs are intentionally narrow.
 */
data class ProtectedConnectivitySet private constructor(
    val hosts: Set<String>,
    val ipCidrs: Set<String>,
) {
    fun merge(other: ProtectedConnectivitySet): ProtectedConnectivitySet = of(
        hosts = hosts + other.hosts,
        cidrs = ipCidrs + other.ipCidrs,
    )

    fun isEmpty(): Boolean = hosts.isEmpty() && ipCidrs.isEmpty()

    companion object {
        fun empty(): ProtectedConnectivitySet = ProtectedConnectivitySet(emptySet(), emptySet())

        fun fromEndpoints(values: Iterable<String>): ProtectedConnectivitySet = of(hosts = values)

        fun of(
            hosts: Iterable<String> = emptyList(),
            cidrs: Iterable<String> = emptyList(),
        ): ProtectedConnectivitySet {
            val normalizedHosts = linkedSetOf<String>()
            val normalizedCidrs = linkedSetOf<String>()
            hosts.forEach { value ->
                normalizeEndpoint(value)?.let { normalized ->
                    if (normalized.isCidr) normalizedCidrs += normalized.value else normalizedHosts += normalized.value
                }
            }
            cidrs.forEach { value ->
                normalizeCidr(value)?.let(normalizedCidrs::add)
            }
            return ProtectedConnectivitySet(normalizedHosts, normalizedCidrs)
        }

        private data class Endpoint(val value: String, val isCidr: Boolean)

        private fun normalizeEndpoint(value: String): Endpoint? {
            val trimmed = value.trim().removeSurrounding("[", "]")
            if (trimmed.isBlank()) return null
            normalizeCidr(trimmed)?.let { return Endpoint(it, isCidr = true) }
            val literal = normalizeIp(trimmed)
            if (literal != null) {
                val suffix = if (literal.contains(':')) "/128" else "/32"
                return Endpoint("$literal$suffix", isCidr = true)
            }
            val host = trimmed.lowercase().removeSuffix(".")
            return if (HOSTNAME.matches(host)) Endpoint(host, isCidr = false) else null
        }

        private fun normalizeCidr(value: String): String? {
            val parts = value.trim().split('/', limit = 2)
            if (parts.size != 2) return null
            val literal = normalizeIp(parts[0].trim()) ?: return null
            val prefix = parts[1].trim().toIntOrNull() ?: return null
            val isIpv6 = literal.contains(':')
            val maxPrefix = if (isIpv6) 128 else 32
            val minimumPrefix = if (isIpv6) 32 else 8
            if (prefix !in minimumPrefix..maxPrefix) return null
            return "$literal/$prefix"
        }

        private fun normalizeIp(value: String): String? {
            if (value.isBlank() || (!value.contains('.') && !value.contains(':'))) return null
            if (!IP_LITERAL.matches(value)) return null
            val address = runCatching { InetAddress.getByName(value) }.getOrNull() ?: return null
            val normalized = address.hostAddress?.substringBefore('%')?.lowercase() ?: return null
            return normalized.takeIf { it.contains('.') || it.contains(':') }
        }

        private val HOSTNAME = Regex("^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$")
        private val IP_LITERAL = Regex("^[0-9a-fA-F:.]+$")
    }
}
