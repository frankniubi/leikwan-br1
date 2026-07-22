package com.leikwan.bridge.api

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class RouteRulePullModelsTest {
    private val gson = Gson()

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
                {"direction_id":"dir_sh_jp_1","binding_state":"ready","local_tcp_port":12001,"local_udp_port":12001,"runtime_ref":{"type":"forward_ref","forward_id":77,"selectable":false,"transport":"tcp_udp"}}
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

        val binding = parsed.directionRuntimeBindings.orEmpty().single()
        assertEquals("dir_sh_jp_1", binding.directionId)
        assertEquals("ready", binding.bindingState)
        assertEquals(12001, binding.localTcpPort)
        assertEquals(12001, binding.localUdpPort)
        assertNotNull(binding.runtimeRef)
        assertEquals(77L, binding.runtimeRef?.forwardId)
        assertFalse(binding.runtimeRef?.selectable ?: true)

        assertTrue(parsed.routeRuleCapabilities?.routeDirectionRules == true)
        assertTrue(parsed.routeRuleCapabilities?.exactDestinationRules == true)
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
