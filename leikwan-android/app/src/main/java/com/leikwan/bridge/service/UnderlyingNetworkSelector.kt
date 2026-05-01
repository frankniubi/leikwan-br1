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
    ): T? {
        val activeCandidate = candidates.firstOrNull { it.value == active }
        if (activeCandidate != null && !activeCandidate.isVpn && activeCandidate.hasInternet) {
            return activeCandidate.value
        }
        return candidates.firstOrNull { !it.isVpn && it.hasInternet }?.value
    }
}
