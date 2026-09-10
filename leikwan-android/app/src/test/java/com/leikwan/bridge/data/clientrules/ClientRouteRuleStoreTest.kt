package com.leikwan.bridge.data.clientrules

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionLocalEndpointDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.DirectionRuntimeRefDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.clientrules.toDomain
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ClientRouteRuleStoreTest {
    private lateinit var context: Context
    private lateinit var store: ClientRouteRuleStore
    private var now = 1_000L

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        context.getSharedPreferences("client-route-rule-store-test", Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit()
        store = ClientRouteRuleStore(
            prefs = context.getSharedPreferences("client-route-rule-store-test", Context.MODE_PRIVATE),
            clock = { now },
        )
    }

    @Test
    fun `replaceFromPull stores directions entitlements rules bindings and capabilities`() {
        store.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            entitlements = listOf(RouteRuleEntitlementDto("svc_scope_7", 7L, 7007L, 5, 4, listOf("dir_sh_jp_1"))),
            rules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 2L)),
            bindings = listOf(
                DirectionRuntimeBindingDto(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    clientRuntimeBindingId = "crb_svc_scope_7_dir_sh_jp_1",
                    runtimeRef = DirectionRuntimeRefDto(type = "forward_ref", forwardId = 77L, selectable = false, transport = "tcp_udp"),
                    localTcpPort = 12001,
                    localUdpPort = 12001,
                    localEndpoint = DirectionLocalEndpointDto(host = "127.0.0.1", tcpPort = 12001, udpPort = 12001),
                ),
            ),
            capabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )

        val snapshot = store.current()
        assertTrue(snapshot.capabilities.routeDirectionRules)
        assertTrue(snapshot.capabilities.exactDestinationRules)
        assertEquals("沪日1", snapshot.directions.single().label)
        assertEquals(5, snapshot.entitlements.single().maxRules)
        assertEquals(ClientRuleEffectiveState.ACTIVE, snapshot.rules.single().effectiveState)
        val binding = snapshot.bindings.single()
        assertEquals("svc_scope_7", binding.serviceScopeId)
        assertEquals("crb_svc_scope_7_dir_sh_jp_1", binding.clientRuntimeBindingId)
        assertEquals(12001, binding.localTcpPort)
        assertTrue(binding.readyForAndroid)
    }

    @Test
    fun `pending create update and tombstone survive pull replacement until server ack`() {
        val local = store.markPendingCreate(
            ClientRuleWriteDraft(
                serviceScopeId = "svc_scope_7",
                destinationIp = "93.184.216.34",
                destinationPort = 443,
                directionId = "dir_sh_jp_1",
                enabled = true,
            )
        )
        assertTrue(local.id.startsWith("local_"))
        assertEquals(PendingClientRuleOperation.CREATE, local.pendingOperation)

        store.replaceFromPull(
            routeDirections = emptyList(),
            entitlements = emptyList(),
            rules = emptyList(),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )
        assertEquals(PendingClientRuleOperation.CREATE, store.current().rules.single().pendingOperation)

        store.applyServerRule(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L), local.id)
        assertEquals("rule_1", store.current().rules.single().id)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)

        store.markPendingUpdate("rule_1", ClientRuleWriteDraft(serviceScopeId = null, destinationIp = null, destinationPort = null, directionId = "dir_sh_jp_2", enabled = false, syncVersion = 1L))
        assertEquals(PendingClientRuleOperation.UPDATE, store.current().rules.single().pendingOperation)
        assertEquals("dir_sh_jp_2", store.current().rules.single().directionId)
        assertFalse(store.current().rules.single().enabled)

        store.markPendingTombstone("rule_1")
        val tombstone = store.current().rules.single()
        assertEquals(PendingClientRuleOperation.DELETE, tombstone.pendingOperation)
        assertNotNull(tombstone.deletedAtMs)
        assertTrue(store.activeRulesForScope("svc_scope_7").isEmpty())
    }

    @Test
    fun `invalid exact destinations are rejected before local pending writes`() {
        assertEquals("invalid_destination_ip", ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "example.com", 443, "dir", true)).error)
        assertEquals("invalid_destination_port", ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "93.184.216.34", 0, "dir", true)).error)
        assertNull(ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "93.184.216.34", 443, "dir", true)).error)
    }

    @Test
    fun `runtime binding domain mapping falls back to local endpoint and enforces Android readiness`() {
        val fallback = DirectionRuntimeBindingDto(
            serviceScopeId = "svc_scope_7",
            directionId = "dir_sh_jp_1",
            bindingState = "ready",
            clientRuntimeBindingId = "crb_svc_scope_7_dir_sh_jp_1",
            runtimeRef = DirectionRuntimeRefDto(type = "forward_ref", forwardId = 77L, selectable = false, transport = "tcp_udp"),
            localTcpPort = 0,
            localUdpPort = 0,
            localEndpoint = DirectionLocalEndpointDto(host = "127.0.0.1", tcpPort = 12001, udpPort = 12001),
        ).toDomain()

        assertEquals("svc_scope_7", fallback.serviceScopeId)
        assertEquals("crb_svc_scope_7_dir_sh_jp_1", fallback.clientRuntimeBindingId)
        assertEquals(12001, fallback.localTcpPort)
        assertEquals(12001, fallback.localUdpPort)
        assertTrue(fallback.readyForAndroid)

        val notReadyBindings = listOf(
            fallback.copy(bindingState = "missing"),
            fallback.copy(runtimeForwardId = null),
            fallback.copy(localTcpPort = 0),
            fallback.copy(localUdpPort = 65536),
            fallback.copy(localUdpPort = 12002),
            fallback.copy(transport = "tcp"),
        )

        notReadyBindings.forEach { binding: DirectionRuntimeBinding ->
            assertFalse(binding.readyForAndroid)
        }
    }

    @Test
    fun `clear empties persisted snapshot and active rules exclude tombstones`() {
        store.replaceFromPull(
            routeDirections = emptyList(),
            entitlements = emptyList(),
            rules = listOf(
                ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L),
                ClientRouteRuleDto("rule_2", "svc_scope_7", "203.0.113.10", 8443, "tcp_udp", "dir_sh_jp_2", false, "disabled", 1L),
            ),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(true, true),
        )
        store.markPendingTombstone("rule_1")

        assertEquals(listOf("rule_2"), store.activeRulesForScope("svc_scope_7").map { it.id })

        store.clear()
        val reloaded = ClientRouteRuleStore(
            prefs = context.getSharedPreferences("client-route-rule-store-test", Context.MODE_PRIVATE),
            clock = { now },
        )
        assertTrue(reloaded.current().rules.isEmpty())
        assertFalse(reloaded.current().capabilities.exactDestinationRules)
    }
}
