package com.leikwan.bridge.api

import com.google.gson.JsonParser
import com.google.gson.Gson
import com.leikwan.bridge.runtime.ForwardContractParser
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.session.DeviceControlEnvelope
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Test

class PanelModelsContractParsingTest {

    @Test
    fun `device control envelope parses terminal command without transport material`() {
        val parsed = Gson().fromJson(
            """{"ok":false,"error":"device_kicked","command":{"version":9,"action":"kick","reason":"tenant"},"endpoint":"secret"}""",
            DeviceControlEnvelope::class.java,
        )

        assertEquals("device_kicked", parsed.error)
        assertEquals(9L, parsed.command?.version)
        assertEquals("kick", parsed.command?.action)
        assertEquals("tenant", parsed.command?.reason)
    }

    @Test
    fun `parser keeps bond ingress contract fields`() {
        val raw = JsonParser.parseString(
            """
            {
              "forward_id": 26,
              "service_name": "bond-26",
              "target": "193.227.109.82:5201",
              "listen_port": 11024,
              "mode": "bond",
              "backend_mode": "leigod_mode",
              "contract_version": 1,
              "client_mode": "mptcp",
              "runtime_preference": "mptcp_preferred_bond_fallback",
              "bond_ingress_mode": "plain_tcp_mux",
              "active_family": "sidecar_aggregation",
              "aggregation_enabled": true,
              "target_contract": "sidecar_aggregation",
              "sidecar_required": true,
              "bond_sidecar_point_id": 9,
              "transport": "tcp",
              "service_status": "active",
              "endpoint_mode": "manual",
              "sni": "relay.example.com",
              "path_prefix": "/route-key",
              "speed_limit_mbps": 500,
              "bonding": {
                "route_key": "/route-key",
                "remote_target": "138.252.162.120:9000",
                "session_token": "tcp-token",
                "udp_session_token": "udp-token",
                "chunk_size": 131072,
                "max_window_bytes": 16777216,
                "retransmit_ms": 333,
                "connect_timeout_ms": 3500,
                "join_timeout_ms": 400,
                "adaptive_join_timeout": true,
                "join_timeout_floor_ms": 35,
                "join_timeout_ceiling_ms": 220,
                "join_timeout_per_leg_ms": 20,
                "interactive_chunk_bytes": 20000,
                "latency_guard_bytes": 300000,
                "interactive_rtt_slack_ms": 45,
                "warmup_trigger_bytes": 65536,
                "warmup_slice_bytes": 65536,
                "warmup_leg_delay_ms": 80,
                "bulk_promote_bytes": 262144,
                "leg_cooldown_ms": 3000,
                "quic_enabled": true,
                "quic_via_slave": true,
                "active_leg_limit": 1,
                "quic_active_leg_limit": 1,
                "wss_standby_enabled": true,
                "disable_local_rate_limit": true,
                "quic_server_name": "sidecar.example.com",
                "quic_insecure": true,
                "quic_priority": 0,
                "ws_priority": 10
              },
              "mptcp": {
                "sidecar_host": "198.51.100.10",
                "sidecar_port": 14969
              },
              "endpoints": [
                {
                  "endpoint_id": 184,
                  "node": "gz-1",
                  "host": "8.163.90.132",
                  "port": 7001,
                  "priority": 1,
                  "weight": 1,
                  "health_status": "healthy",
                  "supports_quic_leg": true,
                  "ingress_protocol": "plain_tcp_mux",
                  "ingress_auth_token": "auth-1"
                }
              ]
            }
            """.trimIndent()
        )

        val parsed = ForwardContractParser.parse(raw)

        assertNotNull(parsed)
        parsed!!
        assertEquals(26L, parsed.forwardId)
        assertEquals(ForwardMode.BOND, parsed.mode)
        assertEquals("leigod_mode", parsed.backendMode)
        assertEquals(1, parsed.contractVersion)
        assertEquals("mptcp", parsed.clientMode)
        assertEquals("mptcp_preferred_bond_fallback", parsed.runtimePreference)
        assertEquals("plain_tcp_mux", parsed.bondIngressMode)
        assertEquals("sidecar_aggregation", parsed.activeFamily)
        assertEquals(true, parsed.aggregationEnabled)
        assertEquals("sidecar_aggregation", parsed.targetContract)
        assertEquals(true, parsed.sidecarRequired)
        assertEquals(9L, parsed.bondSidecarPointId)
        assertEquals("plain_tcp_mux", parsed.bonding?.ingressMode)
        assertEquals("/route-key", parsed.bonding?.routeKey)
        assertEquals("138.252.162.120:9000", parsed.bonding?.remoteTarget)
        assertEquals("tcp-token", parsed.bonding?.sessionToken)
        assertEquals(131072, parsed.bonding?.chunkSize)
        assertEquals(333, parsed.bonding?.retransmitMs)
        assertEquals(3500, parsed.bonding?.connectTimeoutMs)
        assertEquals(400, parsed.bonding?.joinTimeoutMs)
        assertEquals(true, parsed.bonding?.adaptiveJoinTimeout)
        assertEquals(35, parsed.bonding?.joinTimeoutFloorMs)
        assertEquals(220, parsed.bonding?.joinTimeoutCeilingMs)
        assertEquals(20, parsed.bonding?.joinTimeoutPerLegMs)
        assertEquals(20000, parsed.bonding?.interactiveChunkBytes)
        assertEquals(1, parsed.bonding?.activeLegLimit)
        assertEquals(true, parsed.bonding?.wssStandbyEnabled)
        assertEquals("sidecar.example.com", parsed.bonding?.quicServerName)
        assertEquals(10, parsed.bonding?.wsPriority)
        assertEquals("plain_tcp_mux", parsed.endpoints.single().ingressProtocol)
        assertEquals("auth-1", parsed.endpoints.single().ingressAuthToken)
        assertEquals("198.51.100.10", parsed.mptcp?.sidecarHost)
        assertEquals(14969, parsed.mptcp?.sidecarPort)
    }

    @Test
    fun `route rule helper forwards are parsed but hidden from visible catalog`() {
        val helper = JsonParser.parseString(
            """
            {
              "forward_id": 77,
              "service_name": "helper-dir-sh-jp-1",
              "target": "93.184.216.34:443",
              "listen_port": 12001,
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
        val visible = JsonParser.parseString(
            """
            {
              "forward_id": 78,
              "service_name": "selectable-service",
              "target": "203.0.113.10:443",
              "listen_port": 12002,
              "mode": "single",
              "contract_version": 1,
              "transport": "tcp_udp",
              "service_status": "active",
              "endpoint_mode": "manual",
              "route_rule_helper_only": false,
              "endpoints": [
                {"endpoint_id":2,"node":"n2","host":"198.51.100.11","port":7001,"priority":1,"weight":1,"health_status":"healthy"}
              ]
            }
            """.trimIndent()
        )

        val helperContract = ForwardContractParser.parse(helper)!!
        val visibleContract = ForwardContractParser.parse(visible)!!
        assertEquals(true, helperContract.routeRuleHelperOnly)
        assertEquals(false, visibleContract.routeRuleHelperOnly)

        val catalog = com.leikwan.bridge.runtime.ForwardFilter.buildCatalog(listOf(helperContract, visibleContract), selectedForwardId = null)
        assertEquals(listOf(78L), catalog.visibleForwards.map { it.forwardId })
    }
}
