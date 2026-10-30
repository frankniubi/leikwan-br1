package com.leikwan.bridge.service

data class UnderlyingNetworkCandidate<T>(
    val value: T,
    val isVpn: Boolean,
    val hasInternet: Boolean,
)

object UnderlyingNetworkSelector {
    fun <T> select(
        active: T?,
        candidates: List<UnderlyingNetworkCandidate<T>>,
    ): T? = selectAll(active, candidates).firstOrNull()

    /**
     * Every usable physical network, the active one first when it qualifies. Callers publish the
     * whole list as the VPN's underlying networks so tunnel traffic survives a Wi-Fi <-> cellular
     * switch instead of blackholing on the one pinned network the monitor has not re-pinned yet.
     */
    fun <T> selectAll(
        active: T?,
        candidates: List<UnderlyingNetworkCandidate<T>>,
    ): List<T> {
        val usable = candidates.filter { !it.isVpn && it.hasInternet }.map { it.value }
        if (active != null && usable.contains(active)) {
            return listOf(active) + usable.filter { it != active }
        }
        return usable
    }
}
