package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PanelRepositoryRouteRuleSyncTest {
    private lateinit var context: Context
    private lateinit var store: ClientRouteRuleStore
    private lateinit var api: FakePanelApi
    private lateinit var repo: PanelRepository

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        val prefs = Prefs(context.getSharedPreferences("route-rule-repo-test-prefs", Context.MODE_PRIVATE).also {
            it.edit().clear().commit()
        })
        prefs.token = "agent-token"
        context.getSharedPreferences("route-rule-repo-store", Context.MODE_PRIVATE).edit().clear().commit()
        store = ClientRouteRuleStore(
            prefs = context.getSharedPreferences("route-rule-repo-store", Context.MODE_PRIVATE),
            clock = { 10L },
        )
        api = FakePanelApi()
        repo = PanelRepository(context, prefsOverride = prefs, apiOverride = api, routeRuleStore = store)
    }

    @Test
    fun `pullCatalog advertises Android route rule capabilities before pulling and stores payload`() = runBlocking {
        api.pullResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = emptyList(),
            routeDirections = listOf(RouteDirectionDto(id = "dir_sh_jp_1", label = "沪日1", status = "available", sortOrder = 10)),
            routeRuleEntitlements = listOf(
                RouteRuleEntitlementDto(
                    serviceScopeId = "svc_scope_7",
                    serviceId = 7L,
                    subscriptionId = 7007L,
                    maxRules = 5,
                    remainingRules = 4,
                    allowedDirectionIds = listOf("dir_sh_jp_1"),
                )
            ),
            clientRules = listOf(
                ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = "active",
                    syncVersion = 1L,
                )
            ),
            directionRuntimeBindings = listOf(
                DirectionRuntimeBindingDto(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    clientRuntimeBindingId = "binding_1",
                    runtimeRef = DirectionRuntimeRefDto(type = "forward_ref", forwardId = 77L, selectable = false, transport = "tcp_udp"),
                    localTcpPort = 12001,
                    localUdpPort = 12001,
                )
            ),
            routeRuleCapabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )

        repo.pullCatalog()

        assertEquals(listOf("heartbeat", "pull"), api.calls)
        assertEquals("Bearer agent-token", api.lastHeartbeatAuth)
        assertEquals(true, api.lastHeartbeatRequest?.supportsRouteDirectionRules)
        assertEquals(true, api.lastHeartbeatRequest?.supportsExactDestinationRules)
        assertEquals("沪日1", store.current().directions.single().label)
        assertEquals("rule_1", store.current().rules.single().id)
        assertEquals("binding_1", store.current().bindings.single().clientRuntimeBindingId)
        assertTrue(store.current().capabilities.routeDirectionRules)
    }

    @Test
    fun `pullCatalog keeps caching all forwards while storing route rules`() = runBlocking {
        api.pullResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = listOf(sampleForward(77L, "helper-direction", 12077), sampleForward(78L, "selectable-service", 12078)),
            routeDirections = listOf(RouteDirectionDto(id = "dir_sh_jp_1", label = "沪日1", status = "available", sortOrder = 10)),
            clientRules = listOf(
                ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = "active",
                    syncVersion = 1L,
                )
            ),
        )

        repo.pullCatalog()

        assertEquals("helper-direction", repo.getCachedContract(77L)?.serviceName)
        assertEquals("selectable-service", repo.getCachedContract(78L)?.serviceName)
        assertEquals("rule_1", store.current().rules.single().id)
    }

    @Test
    fun `create update and delete use pending lifecycle and server acknowledgements`() = runBlocking {
        api.createResponse = Response.success(
            ClientRuleWriteResponse(
                ok = true,
                clientRule = ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = "active",
                    syncVersion = 1L,
                ),
            )
        )

        val created = repo.createClientRule(
            ClientRuleWriteDraft(
                serviceScopeId = "svc_scope_7",
                destinationIp = "93.184.216.34",
                destinationPort = 443,
                directionId = "dir_sh_jp_1",
                enabled = true,
            ),
        )

        assertEquals("rule_1", created.id)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)
        assertEquals("93.184.216.34", api.lastCreateRequest?.destinationIp)
        assertEquals("Bearer agent-token", api.lastWriteAuth)

        api.updateResponse = Response.success(
            ClientRuleWriteResponse(
                ok = true,
                clientRule = ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = false,
                    effectiveState = "disabled",
                    syncVersion = 2L,
                ),
            )
        )

        val updated = repo.updateClientRule("rule_1", ClientRuleWriteDraft(enabled = false, syncVersion = 1L))

        assertFalse(updated.enabled)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)
        assertEquals(1L, api.lastUpdateRequest?.syncVersion)
        assertEquals("rule_1", api.lastUpdateRuleId)

        api.deleteResponse = Response.success(
            ClientRuleWriteResponse(
                ok = true,
                clientRule = ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = false,
                    effectiveState = "disabled",
                    syncVersion = 3L,
                ),
            )
        )

        val deleted = repo.deleteClientRule("rule_1")

        assertEquals("rule_1", deleted.id)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)
        assertEquals(listOf("rule_1"), api.deletedRuleIds)
    }

    @Test
    fun `write failures mark pending error and throw stable server message`() = runBlocking {
        api.createResponse = Response.success(ClientRuleWriteResponse(ok = false, error = "duplicate_destination", message = "already exists"))

        val thrown = runCatching {
            repo.createClientRule(ClientRuleWriteDraft("svc_scope_7", "93.184.216.34", 443, "dir_sh_jp_1", true))
        }.exceptionOrNull()

        assertEquals("duplicate_destination", thrown?.message)
        val pending = store.current().rules.single()
        assertEquals(PendingClientRuleOperation.CREATE, pending.pendingOperation)
        assertEquals(ClientRuleEffectiveState.ERROR, pending.effectiveState)
        assertEquals("duplicate_destination", pending.pendingError)
    }

    @Test
    fun `logout clears route rule cache`() = runBlocking {
        store.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto(id = "dir_sh_jp_1", label = "沪日1", status = "available", sortOrder = 10)),
            entitlements = emptyList(),
            rules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(true, true),
        )

        repo.logout()

        assertTrue(store.current().rules.isEmpty())
        assertTrue(store.current().directions.isEmpty())
    }

    private fun sampleForward(forwardId: Long, serviceName: String, listenPort: Int): JsonElement = JsonParser.parseString(
        """
        {
          "forward_id": $forwardId,
          "service_name": "$serviceName",
          "target": "93.184.216.34:443",
          "listen_port": $listenPort,
          "mode": "single",
          "backend_mode": "leigod_mode",
          "contract_version": 1,
          "transport": "tcp_udp",
          "service_status": "active",
          "endpoint_mode": "manual",
          "route_rule_helper_only": true,
          "endpoints": [
            {"endpoint_id":1,"node":"n1","host":"198.51.100.10","port":7001,"priority":1,"weight":1,"health_status":"healthy"}
          ]
        }
        """.trimIndent()
    )

    private class FakePanelApi : PanelApi {
        var pullResponse = PullConfigsResponse(true, JsonObject(), emptyList())
        var createResponse: Response<ClientRuleWriteResponse> = Response.success(ClientRuleWriteResponse(false, error = "not_configured"))
        var updateResponse: Response<ClientRuleWriteResponse> = Response.success(ClientRuleWriteResponse(false, error = "not_configured"))
        var deleteResponse: Response<ClientRuleWriteResponse> = Response.success(ClientRuleWriteResponse(false, error = "not_configured"))
        val calls = mutableListOf<String>()
        var lastHeartbeatAuth: String? = null
        var lastHeartbeatRequest: HeartbeatRequest? = null
        var lastWriteAuth: String? = null
        var lastCreateRequest: ClientRuleWriteRequest? = null
        var lastUpdateRuleId: String? = null
        var lastUpdateRequest: ClientRuleWriteRequest? = null
        val deletedRuleIds = mutableListOf<String>()

        override suspend fun login(request: LoginRequest): Response<LoginResponse> = Response.success(LoginResponse(ok = true))

        override suspend fun selectClient(request: ClientSelectionRequest): Response<ClientSelectionResponse> = throw UnsupportedOperationException()

        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse {
            calls += "heartbeat"
            lastHeartbeatAuth = auth
            lastHeartbeatRequest = request
            return HeartbeatResponse(ok = true)
        }

        override suspend fun pullConfigs(auth: String): PullConfigsResponse {
            calls += "pull"
            return pullResponse
        }

        override suspend fun createClientRule(auth: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> {
            lastWriteAuth = auth
            lastCreateRequest = request
            return createResponse
        }

        override suspend fun updateClientRule(auth: String, ruleId: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> {
            lastWriteAuth = auth
            lastUpdateRuleId = ruleId
            lastUpdateRequest = request
            return updateResponse
        }

        override suspend fun deleteClientRule(auth: String, ruleId: String): Response<ClientRuleWriteResponse> {
            lastWriteAuth = auth
            deletedRuleIds += ruleId
            return deleteResponse
        }

        override suspend fun getForwardConfig(auth: String, forwardId: Long): JsonElement = JsonObject()
    }
}
