package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.nio.file.Files
import java.time.Instant

class UltraRuntimeStatusTest {

    @Test
    fun `policy_state awaiting and lapsed are idle by design and need a lease`() {
        val awaiting = UltraRuntimeStatus.parse(
            """{"state":"degraded","policy_state":"awaiting","error_code":"awaiting_policy","updated_at":"2026-09-24T00:00:05Z"}""",
        )!!
        val lapsed = UltraRuntimeStatus.parse(
            """{"state":"degraded","policy_state":"lapsed","error_code":"policy_lapsed"}""",
        )!!

        assertEquals(UltraPolicyState.AWAITING, awaiting.policyState)
        assertTrue(awaiting.isIdleByDesign)
        assertTrue(awaiting.needsLease)
        assertEquals(Instant.parse("2026-09-24T00:00:05Z"), awaiting.updatedAt)
        assertEquals(UltraPolicyState.LAPSED, lapsed.policyState)
        assertTrue(lapsed.isIdleByDesign)
        assertTrue(lapsed.needsLease)
    }

    @Test
    fun `revoked is idle but does not ask for a lease and active is neither`() {
        val revoked = UltraRuntimeStatus.parse("""{"policy_state":"revoked","error_code":"policy_revoked"}""")!!
        val active = UltraRuntimeStatus.parse("""{"state":"ready","policy_state":"active"}""")!!

        assertTrue(revoked.isIdleByDesign)
        assertFalse(revoked.needsLease)
        assertFalse(active.isIdleByDesign)
        assertFalse(active.needsLease)
    }

    @Test
    fun `older runtimes without policy_state map their error codes`() {
        val legacyAwaiting = UltraRuntimeStatus.parse("""{"state":"ready","error_code":"awaiting_policy"}""")!!
        val legacyLastError = UltraRuntimeStatus.parse("""{"last_error":"policy_lapsed"}""")!!
        val dataPlaneError = UltraRuntimeStatus.parse("""{"state":"degraded","error_code":"tcp_dial_failed"}""")!!

        assertEquals(UltraPolicyState.AWAITING, legacyAwaiting.policyState)
        assertEquals(UltraPolicyState.LAPSED, legacyLastError.policyState)
        assertEquals(UltraPolicyState.UNKNOWN, dataPlaneError.policyState)
        assertFalse(dataPlaneError.isIdleByDesign)
    }

    @Test
    fun `missing or malformed status files read as null`() {
        val dir = Files.createTempDirectory("ultra-status").toFile()
        try {
            val file = File(dir, "forward.status.json")
            assertNull(UltraRuntimeStatus.read(file.absolutePath))
            assertNull(UltraRuntimeStatus.read(""))
            file.writeText("not json")
            assertNull(UltraRuntimeStatus.read(file.absolutePath))
            file.writeText("""{"policy_state":"awaiting"}""")
            assertEquals(UltraPolicyState.AWAITING, UltraRuntimeStatus.read(file.absolutePath)!!.policyState)
        } finally {
            dir.deleteRecursively()
        }
    }

    @Test
    fun `native lease accepts a long offline grant within the ceiling`() {
        val now = Instant.now()
        assertTrue(nativeLease(now.plusSeconds(1800)).isUsable(now))
        assertTrue(nativeLease(now.plusSeconds(3600)).isUsable(now))
        assertFalse(nativeLease(now.plusSeconds(3700)).isUsable(now))
    }

    @Test
    fun `ultra runtime plans treat only exit status 1 as a configuration error`() {
        assertEquals(setOf(1), ULTRA_PERMANENT_EXIT_CODES)
        assertTrue(RuntimeProcessPlan(RuntimeProcessRole.BOND, listOf("bond")).permanentExitCodes.isEmpty())
    }

    private fun nativeLease(expiresAt: Instant): NativeOutboundLease {
        val direction = "China Mainland -> International"
        return NativeOutboundLease(
            forwardId = 42,
            leaseId = "native-lease-offline",
            policyGeneration = "a".repeat(64),
            expiresAt = expiresAt,
            transport = "tcp+udp",
            direction = direction,
            routeScope = "intl_only",
            scopeDigest = NativeOutboundLease.scopeDigest(42, direction, "tcp+udp", "intl_only"),
            endpointIp = "203.0.113.20",
            endpointPort = 5438,
            user = "line-user".toByteArray(),
            password = "line-password".toByteArray(),
            rateLimitMbps = 20,
            quotaRemainingBytes = 1_000_000,
            selectionRef = "selection_ref_offline",
            selectionTier = "paid",
        )
    }
}
