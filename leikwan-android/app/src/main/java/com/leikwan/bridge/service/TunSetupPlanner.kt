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
        val hasExplicitAddress = snapshot.inet4Addresses.isNotEmpty() || snapshot.inet6Addresses.isNotEmpty()
        val inet4Addresses = snapshot.inet4Addresses.ifEmpty {
            if (hasExplicitAddress) emptyList() else fallbackInet4Address
        }
        val inet6Addresses = snapshot.inet6Addresses.ifEmpty {
            if (hasExplicitAddress) emptyList() else fallbackInet6Address
        }
        val inet4Routes = if (snapshot.autoRoute && snapshot.inet4Routes.isNotEmpty()) {
            snapshot.inet4Routes
        } else if (inet4Addresses.isNotEmpty()) {
            fallbackInet4Routes
        } else {
            emptyList()
        }
        val inet6Routes = if (snapshot.autoRoute && snapshot.inet6Routes.isNotEmpty()) {
            snapshot.inet6Routes
        } else if (inet6Addresses.isNotEmpty()) {
            fallbackInet6Routes
        } else {
            emptyList()
        }
        val dnsServers = listOf(snapshot.dnsServer?.takeIf { it.isNotBlank() } ?: fallbackDnsServer)
        val disallowedPackageNames = ownerPackageName
            ?.takeIf { it.isNotBlank() }
            ?.let(::listOf)
            ?: emptyList()

        return TunSetupPlan(
            inet4Addresses = inet4Addresses,
            inet6Addresses = inet6Addresses,
            inet4Routes = inet4Routes,
            inet6Routes = inet6Routes,
            dnsServers = dnsServers,
            disallowedPackageNames = disallowedPackageNames,
            allowBypass = false,
            bindActiveNetwork = true,
        )
    }
}
