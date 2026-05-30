package com.leikwan.bridge.routing

/**
 * Authoritative set of system-protected destinations (panel host, panel mirror, loopback + RFC-1918).
 *
 * This is the single source of truth that replaces the ad-hoc `panelDirectDomains()` injection, so
 * there is no duplicate panel/mirror routing rule. Protected paths are non-overridable: a user
 * TUNNEL rule can never capture them.
 */
class SafePathRegistry private constructor(
    private val entries: List<SafePathEntry>,
) {
    fun protectedEntries(): List<SafePathEntry> = entries

    fun containsDomain(domain: String): Boolean =
        entries.any { entry -> entry.domains.any { it.equals(domain, ignoreCase = true) } }

    fun containsIpCidr(cidr: String): Boolean =
        entries.any { entry -> entry.ipCidrs.contains(cidr) }

    companion object {
        /**
         * Baseline protected set for Android.
         *
         * @param panelUrl the panel base URL; its host is parsed and protected as `panel-api`.
         * @param mirrorHost the panel mirror host, protected as `panel-mirror`.
         */
        fun androidBaseline(panelUrl: String, mirrorHost: String): SafePathRegistry {
            val panelHost = panelUrl
                .substringAfter("://", panelUrl)
                .substringBefore("/")
                .substringBefore(":")
                .trim()
                .lowercase()
            return SafePathRegistry(
                listOf(
                    SafePathEntry(
                        id = "panel-api",
                        domains = setOf(panelHost),
                        ipCidrs = emptySet(),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                    SafePathEntry(
                        id = "panel-mirror",
                        domains = setOf(mirrorHost.trim().lowercase()),
                        ipCidrs = emptySet(),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                    SafePathEntry(
                        id = "loopback-control",
                        domains = emptySet(),
                        ipCidrs = setOf(
                            "127.0.0.0/8",
                            "10.0.0.0/8",
                            "172.16.0.0/12",
                            "192.168.0.0/16",
                        ),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                )
            )
        }
    }
}
