package com.leikwan.bridge.service

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class TunSetupPlannerTest {

    @Test
    fun `planner falls back to dual-stack addresses default routes and dns`() {
        val plan = TunSetupPlanner.plan(
            TunOptionSnapshot(
                autoRoute = false,
                strictRoute = false,
                inet4Addresses = emptyList(),
                inet6Addresses = emptyList(),
                inet4Routes = emptyList(),
                inet6Routes = emptyList(),
                dnsServer = null,
            )
        )

        assertEquals(listOf(RoutePrefix("172.19.0.1", 30)), plan.inet4Addresses)
        assertEquals(listOf(RoutePrefix("fdfe:dcba:9876::1", 126)), plan.inet6Addresses)
        assertEquals(listOf(RoutePrefix("0.0.0.0", 0)), plan.inet4Routes)
        assertEquals(listOf(RoutePrefix("::", 0)), plan.inet6Routes)
        assertEquals(listOf("1.1.1.1"), plan.dnsServers)
        assertFalse(plan.allowBypass)
        assertTrue(plan.bindActiveNetwork)
    }

    @Test
    fun `planner preserves libbox routes and dns but does not request bypass`() {
        val plan = TunSetupPlanner.plan(
            TunOptionSnapshot(
                autoRoute = true,
                strictRoute = true,
                inet4Addresses = listOf(RoutePrefix("10.0.0.2", 30)),
                inet6Addresses = listOf(RoutePrefix("fd00::2", 126)),
                inet4Routes = listOf(RoutePrefix("198.18.0.0", 15)),
                inet6Routes = listOf(RoutePrefix("2000::", 3)),
                dnsServer = "172.19.0.2",
            )
        )

        assertEquals(listOf(RoutePrefix("10.0.0.2", 30)), plan.inet4Addresses)
        assertEquals(listOf(RoutePrefix("fd00::2", 126)), plan.inet6Addresses)
        assertEquals(listOf(RoutePrefix("198.18.0.0", 15)), plan.inet4Routes)
        assertEquals(listOf(RoutePrefix("2000::", 3)), plan.inet6Routes)
        assertEquals(listOf("172.19.0.2"), plan.dnsServers)
        assertFalse(plan.allowBypass)
        assertTrue(plan.bindActiveNetwork)
    }

    @Test
    fun `planner does not synthesize ipv6 when libbox supplies ipv4 only tun options`() {
        val plan = TunSetupPlanner.plan(
            TunOptionSnapshot(
                autoRoute = true,
                strictRoute = true,
                inet4Addresses = listOf(RoutePrefix("172.19.0.1", 30)),
                inet6Addresses = emptyList(),
                inet4Routes = listOf(RoutePrefix("0.0.0.0", 0)),
                inet6Routes = emptyList(),
                dnsServer = "172.19.0.2",
            )
        )

        assertEquals(listOf(RoutePrefix("172.19.0.1", 30)), plan.inet4Addresses)
        assertEquals(emptyList<RoutePrefix>(), plan.inet6Addresses)
        assertEquals(listOf(RoutePrefix("0.0.0.0", 0)), plan.inet4Routes)
        assertEquals(emptyList<RoutePrefix>(), plan.inet6Routes)
    }

    @Test
    fun `planner excludes the vpn owner package from captured app traffic`() {
        val plan = TunSetupPlanner.plan(
            snapshot = TunOptionSnapshot(
                autoRoute = true,
                strictRoute = true,
                inet4Addresses = emptyList(),
                inet6Addresses = emptyList(),
                inet4Routes = emptyList(),
                inet6Routes = emptyList(),
                dnsServer = "172.19.0.2",
            ),
            ownerPackageName = "com.leikwan.bridge",
        )

        assertEquals(listOf("com.leikwan.bridge"), plan.disallowedPackageNames)
    }
}
