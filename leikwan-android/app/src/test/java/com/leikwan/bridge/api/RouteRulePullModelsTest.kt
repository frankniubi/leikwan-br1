package com.leikwan.bridge.api

import com.google.gson.Gson
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

class RouteRulePullModelsTest {
    private val gson = Gson()
    private var server: MockWebServer? = null

    @Before
    fun setUp() {
        server = null
    }

    @After
    fun tearDown() {
        server?.shutdown()
    }

    @Test
    fun `old pull payload remains parseable without route rule fields`() {
        val parsed = gson.fromJson(
            """
            {
              "ok": true,
              "client": {"id": 7, "name": "phone"},
              "forwards": []
            }
            """.trimIndent(),
            PullConfigsResponse::class.java,
        )

        assertTrue(parsed.ok)
        assertEquals(0, parsed.routeDirections.orEmpty().size)
        assertEquals(0, parsed.routeRuleEntitlements.orEmpty().size)
        assertEquals(0, parsed.clientRules.orEmpty().size)
        assertEquals(0, parsed.directionRuntimeBindings.orEmpty().size)
        assertNull(parsed.routeRuleCapabilities)
    }

    @Test
    fun `upgraded pull payload parses route directions entitlements rules bindings and capabilities`() {
        val parsed = gson.fromJson(
            """
            {
              "ok": true,
              "client": {"id": 7, "name": "phone"},
              "forwards": [],
              "route_directions": [
                {"id":"dir_sh_jp_1","label":"沪日1","status":"available","sort_order":10}
              ],
              "route_rule_entitlements": [
                {"service_scope_id":"svc_scope_7","service_id":7,"subscription_id":7007,"max_rules":5,"remaining_rules":4,"allowed_direction_ids":["dir_sh_jp_1"]}
              ],
              "client_rules": [
                {"id":"rule_abc","service_scope_id":"svc_scope_7","destination_ip":"93.184.216.34","destination_port":443,"protocol":"tcp_udp","direction_id":"dir_sh_jp_1","enabled":true,"effective_state":"active","sync_version":3}
              ],
              "direction_runtime_bindings": [
                {
                  "service_scope_id":"svc_scope_7",
                  "direction_id":"dir_sh_jp_1",
                  "binding_state":"ready",
                  "client_runtime_binding_id":"crb_svc_scope_7_dir_sh_jp_1",
                  "runtime_ref":{"type":"forward_ref","forward_id":77,"selectable":false,"transport":"tcp_udp"},
                  "local_tcp_port":12001,
                  "local_udp_port":12001,
                  "local_endpoint":{"host":"127.0.0.1","tcp_port":12001,"udp_port":12001}
                },
                {
                  "service_scope_id":"svc_scope_8",
                  "direction_id":"dir_sh_jp_1",
                  "binding_state":"ready",
                  "client_runtime_binding_id":"crb_svc_scope_8_dir_sh_jp_1",
                  "runtime_ref":{"type":"forward_ref","forward_id":78,"selectable":false,"transport":"tcp_udp"},
                  "local_tcp_port":12002,
                  "local_udp_port":12002,
                  "local_endpoint":{"host":"127.0.0.2","tcp_port":12002,"udp_port":12002}
                }
              ],
              "capabilities": {"route_direction_rules":true,"exact_destination_rules":true}
            }
            """.trimIndent(),
            PullConfigsResponse::class.java,
        )

        val direction = parsed.routeDirections.orEmpty().single()
        assertEquals("dir_sh_jp_1", direction.id)
        assertEquals("沪日1", direction.label)
        assertEquals("available", direction.status)
        assertEquals(10, direction.sortOrder)

        val entitlement = parsed.routeRuleEntitlements.orEmpty().single()
        assertEquals("svc_scope_7", entitlement.serviceScopeId)
        assertEquals(5, entitlement.maxRules)
        assertEquals(4, entitlement.remainingRules)
        assertEquals(listOf("dir_sh_jp_1"), entitlement.allowedDirectionIds)

        val rule = parsed.clientRules.orEmpty().single()
        assertEquals("rule_abc", rule.id)
        assertEquals("93.184.216.34", rule.destinationIp)
        assertEquals(443, rule.destinationPort)
        assertEquals("tcp_udp", rule.protocol)
        assertEquals("active", rule.effectiveState)
        assertEquals(3L, rule.syncVersion)

        val bindings = parsed.directionRuntimeBindings.orEmpty()
        assertEquals(2, bindings.size)
        val binding = bindings.first()
        assertEquals("svc_scope_7", binding.serviceScopeId)
        assertEquals("dir_sh_jp_1", binding.directionId)
        assertEquals("ready", binding.bindingState)
        assertEquals("crb_svc_scope_7_dir_sh_jp_1", binding.clientRuntimeBindingId)
        assertEquals(12001, binding.localTcpPort)
        assertEquals(12001, binding.localUdpPort)
        assertEquals("127.0.0.1", binding.localEndpoint?.host)
        assertEquals(12001, binding.localEndpoint?.tcpPort)
        assertEquals(12001, binding.localEndpoint?.udpPort)
        assertNotNull(binding.runtimeRef)
        assertEquals(77L, binding.runtimeRef?.forwardId)
        assertFalse(binding.runtimeRef?.selectable ?: true)

        val otherScopeBinding = bindings.last()
        assertEquals("svc_scope_8", otherScopeBinding.serviceScopeId)
        assertEquals("dir_sh_jp_1", otherScopeBinding.directionId)
        assertEquals("crb_svc_scope_8_dir_sh_jp_1", otherScopeBinding.clientRuntimeBindingId)
        assertEquals(12002, otherScopeBinding.localTcpPort)
        assertEquals(12002, otherScopeBinding.localUdpPort)
        assertEquals(12002, otherScopeBinding.localEndpoint?.tcpPort)

        assertTrue(parsed.routeRuleCapabilities?.routeDirectionRules == true)
        assertTrue(parsed.routeRuleCapabilities?.exactDestinationRules == true)
    }

    @Test
    fun `heartbeat request advertises Android route rule capabilities by default`() {
        val request = HeartbeatRequest()

        assertEquals("android", request.clientPlatform)
        assertTrue(request.supportsRouteDirectionRules)
        assertTrue(request.supportsExactDestinationRules)
    }

    @Test
    fun `delete client rule request uses rule id path without request body`() = runBlocking {
        val mockServer = MockWebServer()
        server = mockServer
        mockServer.enqueue(MockResponse().setResponseCode(200).setBody("""
            {"ok":true,"client_rule":{"id":"rule_abc","service_scope_id":"svc_scope_7","destination_ip":"93.184.216.34","destination_port":443,"protocol":"tcp_udp","direction_id":"dir_sh_jp_1","enabled":false,"effective_state":"disabled","sync_version":4}}
        """.trimIndent()))
        mockServer.start()
        val api = Retrofit.Builder()
            .baseUrl(mockServer.url("/"))
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PanelApi::class.java)

        api.deleteClientRule("Bearer token", "rule_abc")

        val request = mockServer.takeRequest()
        assertEquals("DELETE", request.method)
        assertEquals("/api/client/client-rules/rule_abc", request.path)
        assertEquals("Bearer token", request.getHeader("Authorization"))
        assertEquals("", request.body.readUtf8())
    }

    @Test
    fun `write response parses stable server error and successful rule`() {
        val error = gson.fromJson(
            """
            {"ok":false,"error":"duplicate_destination","message":"该目标已存在规则"}
            """.trimIndent(),
            ClientRuleWriteResponse::class.java,
        )
        assertFalse(error.ok)
        assertEquals("duplicate_destination", error.error)
        assertEquals("该目标已存在规则", error.message)

        val success = gson.fromJson(
            """
            {"ok":true,"client_rule":{"id":"rule_abc","service_scope_id":"svc_scope_7","destination_ip":"93.184.216.34","destination_port":443,"protocol":"tcp_udp","direction_id":"dir_sh_jp_1","enabled":true,"effective_state":"active","sync_version":1}}
            """.trimIndent(),
            ClientRuleWriteResponse::class.java,
        )
        assertTrue(success.ok)
        assertEquals("rule_abc", success.clientRule?.id)
        assertEquals(1L, success.clientRule?.syncVersion)
    }
}
