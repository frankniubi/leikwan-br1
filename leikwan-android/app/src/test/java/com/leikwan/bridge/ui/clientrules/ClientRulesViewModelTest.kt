package com.leikwan.bridge.ui.clientrules

import com.leikwan.bridge.R
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding
import com.leikwan.bridge.clientrules.RouteDirection
import com.leikwan.bridge.clientrules.RouteRuleCapabilities
import com.leikwan.bridge.clientrules.RouteRuleEntitlement
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ClientRulesViewModelTest {

    private val dispatcher = StandardTestDispatcher()
    private lateinit var store: FakeClientRuleStore
    private lateinit var repository: FakeClientRulesRepository

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        store = FakeClientRuleStore(activeSnapshot())
        repository = FakeClientRulesRepository()
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun newViewModel(): ClientRulesViewModel = ClientRulesViewModel(
        store = store,
        repository = repository,
    )

    @Test
    fun `initial state selects first entitlement and exposes quota directions and rules`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        val state = vm.state.value
        assertTrue(state.enabled)
        assertEquals("scope-a", state.selectedServiceScopeId)
        assertEquals(ClientRuleQuotaLabel(remainingRules = 1, maxRules = 2), state.quota)
        assertEquals(
            ClientRulesText(R.string.client_rules_service_plan_label, listOf(10L, 100L)),
            state.serviceScopes.first { it.serviceScopeId == "scope-a" }.label,
        )
        assertEquals(listOf("japan", "singapore"), state.directions.map { it.id })
        assertEquals(listOf("rule-a"), state.rules.map { it.id })
        assertEquals("", state.draftDestinationIp)
        assertEquals("", state.draftDestinationPort)
        assertEquals("japan", state.draftDirectionId)
        assertTrue(state.draftEnabled)
        assertNull(state.errorCode)
    }

    @Test
    fun `changing service scope filters rules and allowed directions`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.selectServiceScope("scope-b")
        advanceUntilIdle()

        val state = vm.state.value
        assertEquals("scope-b", state.selectedServiceScopeId)
        assertEquals(ClientRuleQuotaLabel(remainingRules = 0, maxRules = 1), state.quota)
        assertEquals(listOf("singapore"), state.directions.map { it.id })
        assertEquals(listOf("rule-b"), state.rules.map { it.id })
        assertEquals("singapore", state.draftDirectionId)
    }

    @Test
    fun `service scope selection stays available when the selected service has no directions`() = runTest(dispatcher) {
        store.emit(
            activeSnapshot().copy(
                entitlements = listOf(
                    RouteRuleEntitlement(
                        serviceScopeId = "scope-empty",
                        serviceId = 30L,
                        subscriptionId = 300L,
                        maxRules = 1,
                        remainingRules = 1,
                        allowedDirectionIds = emptyList(),
                    ),
                    RouteRuleEntitlement(
                        serviceScopeId = "scope-a",
                        serviceId = 10L,
                        subscriptionId = 100L,
                        maxRules = 2,
                        remainingRules = 1,
                        allowedDirectionIds = listOf("japan", "singapore"),
                    ),
                ),
            ),
        )
        val vm = newViewModel()
        advanceUntilIdle()

        assertFalse(vm.state.value.enabled)
        assertTrue(vm.state.value.serviceScopeSelectionEnabled)
        assertEquals(ClientRulesText(R.string.client_rules_unavailable_no_directions), vm.state.value.unavailableReason)

        vm.selectServiceScope("scope-a")
        advanceUntilIdle()

        assertTrue(vm.state.value.enabled)
        assertEquals("scope-a", vm.state.value.selectedServiceScopeId)
        assertEquals(listOf("japan", "singapore"), vm.state.value.directions.map { it.id })
    }

    @Test
    fun `submit does not create when Client Rule quota is exhausted`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.selectServiceScope("scope-b")
        vm.updateDraftDestinationIp("198.51.100.7")
        vm.updateDraftDestinationPort("8443")
        vm.submitDraft()
        advanceUntilIdle()

        assertEquals("over_quota", vm.state.value.errorCode)
        assertTrue(repository.created.isEmpty())
    }

    @Test
    fun `submit validates exact IPv4 and port before creating`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.updateDraftDestinationIp("not an ip")
        vm.updateDraftDestinationPort("70000")
        vm.submitDraft()
        advanceUntilIdle()

        assertEquals("invalid_destination_ip", vm.state.value.errorCode)
        assertTrue(repository.created.isEmpty())

        vm.updateDraftDestinationIp("198.51.100.7")
        vm.updateDraftDestinationPort("8443")
        vm.selectDraftDirection("singapore")
        vm.setDraftEnabled(false)
        vm.submitDraft()
        advanceUntilIdle()

        assertNull(vm.state.value.errorCode)
        assertEquals(
            ClientRuleWriteDraft(
                serviceScopeId = "scope-a",
                destinationIp = "198.51.100.7",
                destinationPort = 8443,
                directionId = "singapore",
                enabled = false,
            ),
            repository.created.single(),
        )
    }

    @Test
    fun `toggle and delete delegate through repository`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        vm.toggleRuleEnabled("rule-a")
        vm.deleteRule("rule-a")
        advanceUntilIdle()

        assertEquals("rule-a", repository.updated.single().first)
        assertEquals(
            ClientRuleWriteDraft(
                serviceScopeId = "scope-a",
                destinationIp = "203.0.113.10",
                destinationPort = 443,
                directionId = "japan",
                enabled = false,
                syncVersion = 4L,
            ),
            repository.updated.single().second,
        )
        assertEquals(listOf("rule-a"), repository.deleted)
    }

    @Test
    fun `store snapshot changes update state`() = runTest(dispatcher) {
        val vm = newViewModel()
        advanceUntilIdle()

        store.emit(activeSnapshot().copy(rules = emptyList()))
        advanceUntilIdle()

        assertTrue(vm.state.value.rules.isEmpty())
        assertEquals("scope-a", vm.state.value.selectedServiceScopeId)
        assertEquals(ClientRuleQuotaLabel(remainingRules = 1, maxRules = 2), vm.state.value.quota)
    }

    @Test
    fun `unavailable snapshot disables creation with product reason`() = runTest(dispatcher) {
        store.emit(ClientRouteRuleSnapshot())
        val vm = newViewModel()
        advanceUntilIdle()

        assertFalse(vm.state.value.enabled)
        assertEquals("client_rules_unavailable", vm.state.value.errorCode)
        assertEquals(ClientRulesText(R.string.client_rules_unavailable_client), vm.state.value.unavailableReason)
        assertTrue(vm.state.value.directions.isEmpty())
        assertTrue(vm.state.value.rules.isEmpty())
    }

    private fun activeSnapshot(): ClientRouteRuleSnapshot = ClientRouteRuleSnapshot(
        directions = listOf(
            RouteDirection(id = "japan", label = "Japan", status = "available", sortOrder = 1),
            RouteDirection(id = "singapore", label = "Singapore", status = "available", sortOrder = 2),
            RouteDirection(id = "disabled", label = "Disabled", status = "disabled", sortOrder = 3),
        ),
        entitlements = listOf(
            RouteRuleEntitlement(
                serviceScopeId = "scope-a",
                serviceId = 10L,
                subscriptionId = 100L,
                maxRules = 2,
                remainingRules = 1,
                allowedDirectionIds = listOf("japan", "singapore"),
            ),
            RouteRuleEntitlement(
                serviceScopeId = "scope-b",
                serviceId = 20L,
                subscriptionId = 200L,
                maxRules = 1,
                remainingRules = 0,
                allowedDirectionIds = listOf("singapore"),
            ),
        ),
        rules = listOf(
            ClientRouteRule(
                id = "rule-a",
                serviceScopeId = "scope-a",
                destinationIp = "203.0.113.10",
                destinationPort = 443,
                directionId = "japan",
                enabled = true,
                effectiveState = ClientRuleEffectiveState.ACTIVE,
                syncVersion = 4L,
                updatedAtMs = 1_000L,
            ),
            ClientRouteRule(
                id = "rule-b",
                serviceScopeId = "scope-b",
                destinationIp = "198.51.100.20",
                destinationPort = 8443,
                directionId = "singapore",
                enabled = false,
                effectiveState = ClientRuleEffectiveState.DISABLED,
                syncVersion = 2L,
                updatedAtMs = 1_100L,
            ),
        ),
        bindings = listOf(
            DirectionRuntimeBinding(
                serviceScopeId = "scope-a",
                directionId = "japan",
                bindingState = "ready",
                clientRuntimeBindingId = "binding-a",
                localTcpPort = 12000,
                localUdpPort = 12000,
                runtimeForwardId = 10L,
                selectable = true,
                transport = "tcp_udp",
            ),
        ),
        capabilities = RouteRuleCapabilities(routeDirectionRules = true, exactDestinationRules = true),
        updatedAtMs = 1_200L,
    )

    private class FakeClientRuleStore(
        initialSnapshot: ClientRouteRuleSnapshot,
    ) : ClientRulesStore {
        private val flow = MutableStateFlow(initialSnapshot)
        override val snapshot: StateFlow<ClientRouteRuleSnapshot> = flow
        override fun current(): ClientRouteRuleSnapshot = flow.value
        override fun validateDraft(draft: ClientRuleWriteDraft) = com.leikwan.bridge.data.clientrules.ClientRouteRuleStore.validateDraft(draft)
        fun emit(snapshot: ClientRouteRuleSnapshot) {
            flow.value = snapshot
        }
    }

    private class FakeClientRulesRepository : ClientRulesRepository {
        val created = mutableListOf<ClientRuleWriteDraft>()
        val updated = mutableListOf<Pair<String, ClientRuleWriteDraft>>()
        val deleted = mutableListOf<String>()

        override suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule {
            created += draft
            return ClientRouteRule(
                id = "created",
                serviceScopeId = draft.serviceScopeId.orEmpty(),
                destinationIp = draft.destinationIp.orEmpty(),
                destinationPort = draft.destinationPort ?: 0,
                directionId = draft.directionId.orEmpty(),
                enabled = draft.enabled ?: true,
                effectiveState = ClientRuleEffectiveState.ACTIVE,
                syncVersion = 1L,
                updatedAtMs = 1_300L,
            )
        }

        override suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule {
            updated += ruleId to draft
            return ClientRouteRule(
                id = ruleId,
                serviceScopeId = draft.serviceScopeId.orEmpty(),
                destinationIp = draft.destinationIp.orEmpty(),
                destinationPort = draft.destinationPort ?: 0,
                directionId = draft.directionId.orEmpty(),
                enabled = draft.enabled ?: true,
                effectiveState = ClientRuleEffectiveState.ACTIVE,
                syncVersion = (draft.syncVersion ?: 0L) + 1L,
                updatedAtMs = 1_400L,
            )
        }

        override suspend fun deleteClientRule(ruleId: String): ClientRouteRule {
            deleted += ruleId
            return ClientRouteRule(
                id = ruleId,
                serviceScopeId = "scope-a",
                destinationIp = "203.0.113.10",
                destinationPort = 443,
                directionId = "japan",
                enabled = false,
                effectiveState = ClientRuleEffectiveState.DISABLED,
                syncVersion = 5L,
                updatedAtMs = 1_500L,
            )
        }
    }
}
