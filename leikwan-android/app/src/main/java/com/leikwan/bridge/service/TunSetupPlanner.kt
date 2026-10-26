package com.leikwan.bridge.service

data class RoutePrefix(
    val address: String,
    val prefix: Int,
)

data class TunOptionSnapshot(
    val autoRoute: Boolean,
    val strictRoute: Boolean,
    val inet4Addresses: List<RoutePrefix>,
    val inet6Addresses: List<RoutePrefix>,
    val inet4Routes: List<RoutePrefix>,
    val inet6Routes: List<RoutePrefix>,
    val dnsServer: String?,
    val inet4RouteExcludes: List<RoutePrefix> = emptyList(),
    val inet6RouteExcludes: List<RoutePrefix> = emptyList(),
    /** Per-app scope from the config: only these apps use the VPN (empty = all apps). */
    val includePackages: List<String> = emptyList(),
    /** Per-app scope from the config: these apps never use the VPN. */
    val excludePackages: List<String> = emptyList(),
)

data class TunSetupPlan(
    val inet4Addresses: List<RoutePrefix>,
    val inet6Addresses: List<RoutePrefix>,
    val inet4Routes: List<RoutePrefix>,
    val inet6Routes: List<RoutePrefix>,
    val dnsServers: List<String>,
    val disallowedPackageNames: List<String>,
    val allowBypass: Boolean,
    val bindActiveNetwork: Boolean,
    val inet4RouteExcludes: List<RoutePrefix> = emptyList(),
    val inet6RouteExcludes: List<RoutePrefix> = emptyList(),
    /**
     * When non-empty, only these apps use the VPN (VpnService.Builder.addAllowedApplication) and
     * [disallowedPackageNames] is empty: Android forbids mixing the two lists. This app is never
     * listed, so it stays outside its own VPN either way.
     */
    val allowedPackageNames: List<String> = emptyList(),
)

object TunSetupPlanner {
    private val fallbackInet4Address = listOf(RoutePrefix("172.19.0.1", 30))
    private val fallbackInet6Address = listOf(RoutePrefix("fdfe:dcba:9876::1", 126))
    private val fallbackInet4Routes = listOf(RoutePrefix("0.0.0.0", 0))
    private val fallbackInet6Routes = listOf(RoutePrefix("::", 0))
    private const val fallbackDnsServer = "1.1.1.1"

    fun plan(
        snapshot: TunOptionSnapshot,
        ownerPackageName: String? = null,
    ): TunSetupPlan {
        // Both families always get an address and a default route into the VPN. A family the
        // VPN does not route stays on the physical network, so its traffic (WebRTC over IPv6,
        // for one) would show the device's real address; sing-box carries or rejects it instead.
        val inet4Addresses = snapshot.inet4Addresses.ifEmpty { fallbackInet4Address }
        val inet6Addresses = snapshot.inet6Addresses.ifEmpty { fallbackInet6Address }
        val inet4Routes = if (snapshot.autoRoute && snapshot.inet4Routes.isNotEmpty()) {
            snapshot.inet4Routes
        } else {
            fallbackInet4Routes
        }
        val inet6Routes = if (snapshot.autoRoute && snapshot.inet6Routes.isNotEmpty()) {
            snapshot.inet6Routes
        } else {
            fallbackInet6Routes
        }
        val dnsServers = listOf(snapshot.dnsServer?.takeIf { it.isNotBlank() } ?: fallbackDnsServer)
        val owner = ownerPackageName?.takeIf { it.isNotBlank() }
        val allowedPackageNames = snapshot.includePackages.filter { it.isNotBlank() && it != owner }.distinct()
        val disallowedPackageNames = if (allowedPackageNames.isNotEmpty()) {
            emptyList()
        } else {
            (listOfNotNull(owner) + snapshot.excludePackages.filter { it.isNotBlank() }).distinct()
        }

        return TunSetupPlan(
            inet4Addresses = inet4Addresses,
            inet6Addresses = inet6Addresses,
            inet4Routes = inet4Routes,
            inet6Routes = inet6Routes,
            dnsServers = dnsServers,
            disallowedPackageNames = disallowedPackageNames,
            allowBypass = false,
            bindActiveNetwork = true,
            inet4RouteExcludes = snapshot.inet4RouteExcludes,
            inet6RouteExcludes = snapshot.inet6RouteExcludes,
            allowedPackageNames = allowedPackageNames,
        )
    }
}
