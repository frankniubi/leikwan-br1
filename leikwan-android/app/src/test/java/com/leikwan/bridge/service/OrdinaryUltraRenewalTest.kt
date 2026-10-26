package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.ClientRuleWriteRequest
import com.leikwan.bridge.api.ClientRuleWriteResponse
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.DirectionRuntimeRefDto
import com.leikwan.bridge.api.HeartbeatRequest
import com.leikwan.bridge.api.HeartbeatResponse
import com.leikwan.bridge.api.PanelApi
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.api.PullConfigsResponse
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.DeviceAckRequest
import com.leikwan.bridge.session.DeviceAckResponse
import com.leikwan.bridge.session.DeviceChallengeRequest
import com.leikwan.bridge.session.DeviceChallengeResponse
import com.leikwan.bridge.session.DeviceEnrollRequest
import com.leikwan.bridge.session.DeviceEnrollResponse
import com.leikwan.bridge.session.DeviceIdentityEnrollRequest
import com.leikwan.bridge.session.DeviceRenewRequest
import com.leikwan.bridge.session.EnrollmentCodeChallengeRequest
import com.leikwan.bridge.session.EnrollmentCodeEnrollRequest
import com.leikwan.bridge.session.EnrollmentCodeIdentityEnrollRequest
import com.leikwan.bridge.runtime.BondingContract
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardEndpoint
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NativeOutboundCapabilityDto
import com.leikwan.bridge.runtime.NativeOutboundCarrierDto
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.runtime.NodeLeg
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import com.leikwan.bridge.singbox.SingboxSettings
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

/** Ordinary Ultra lease renewals must preserve a runtime with an unchanged static contract. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class OrdinaryUltraRenewalTest {

    private lateinit var db: AppDatabase

    @Before
    fun resetVpnState() {
        LeikwanVpnService.startupState = VpnStartupState.RUNNING
        LeikwanVpnService.lastStartError = null
        LeikwanVpnService.configJson = ""
    }

    @After
    fun tearDown() {
        if (::db.isInitialized) db.close()
    }

    @Test
    fun `ordinary Ultra rotates lease generation without restarting a compatible tunnel`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-test-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val target = "origin.example:443"
        val generation = "a".repeat(64)
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":90,
              "service_name":"Ultra service",
              "target":"$target",
              "listen_port":11090,
              "mode":"ultra",
              "backend_mode":"ultra",
              "client_mode":"ultra",
              "transport":"tcp",
              "service_status":"active",
              "endpoint_mode":"managed",
              "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2",
                "forward_id":90,
                "session_ticket":"ticket-90",
                "policy_generation":"$generation",
                "issued_at":"${now.minusSeconds(20)}",
                "renew_after":"${now.minusSeconds(10)}",
                "expires_at":"${now.plusSeconds(180)}",
                "service_state":"active",
                "transport":"tcp",
                "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576,
                "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
              }
            }
            """.trimIndent(),
        )
        val lease = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2",
              "forward_id":90,
              "session_ticket":"ticket-90",
              "policy_generation":"$generation",
              "lease_id":"ul2_test_lease_1234567890",
              "expires_at":"${now.plusSeconds(180)}",
              "transport":"tcp",
              "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576,
              "selection_ref":"selection_ref_1234567890",
              "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}",
              "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            ultraLeaseResponse = Response.success(
                com.leikwan.bridge.api.UltraLeaseResponse(ok = true, leases = listOf(lease)),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        var sentSocketPath = ""
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { socketPath, policy, runtimeLease ->
                sentSocketPath = socketPath
                assertEquals(90L, policy.forwardId)
                assertEquals("203.0.113.90/32", runtimeLease.directConnectivity.ipCidrs.single())
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        orchestrator.startForForward(90L)

        assertEquals(listOf(90L), processRunner.started.map { it.forwardId })
        assertTrue(processRunner.started.single().runtimeContext.processPlans.single().command.contains("--config"))
        assertTrue(sentSocketPath.endsWith(".control.sock"))
        assertEquals(listOf(11090), processRunner.awaitedPorts)
        val config = JsonParser.parseString(LeikwanVpnService.configJson).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }
        assertTrue(
            rules.any { rule ->
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.90/32" } == true
                    && rule.get("outbound")?.asString == "direct"
            }
        )

        suspend fun refreshForAudit() = orchestrator.refreshUltraForTest()
        val stopsBeforeRefresh = processRunner.stopAllCalls
        refreshForAudit()
        assertEquals("same generation must hot refresh", 1, processRunner.started.size)
        assertEquals(stopsBeforeRefresh, processRunner.stopAllCalls)
        val newGeneration = "c".repeat(64)
        forward.asJsonObject.getAsJsonObject("ultra_access").addProperty("policy_generation", newGeneration)
        lease.addProperty("policy_generation", newGeneration)
        refreshForAudit()
        assertEquals("generation-only rotation must hot refresh", 1, processRunner.started.size)
        assertEquals(stopsBeforeRefresh, processRunner.stopAllCalls)

        orchestrator.stop()
    }

    @Test
    fun `credential-suspect runtime failures renew the lease once per burst without a restart`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-failure-renewal-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-failure-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val target = "origin.example:443"
        val generation = "a".repeat(64)
        val digest = com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)
        // renew_after is minutes away: only the failure signal can make a renewal due.
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":90, "service_name":"Ultra service", "target":"$target", "listen_port":11090,
              "mode":"ultra", "backend_mode":"ultra", "client_mode":"ultra", "transport":"tcp",
              "service_status":"active", "endpoint_mode":"managed", "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
                "policy_generation":"$generation", "issued_at":"${now.minusSeconds(20)}",
                "renew_after":"${now.plusSeconds(600)}", "expires_at":"${now.plusSeconds(1500)}",
                "service_state":"active", "transport":"tcp", "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576, "target_digest":"$digest"
              }
            }
            """.trimIndent(),
        )
        val lease = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
              "policy_generation":"$generation", "lease_id":"ul2_test_lease_1234567890",
              "expires_at":"${now.plusSeconds(1500)}", "transport":"tcp", "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576, "selection_ref":"selection_ref_1234567890",
              "target_digest":"$digest", "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            ultraLeaseResponse = Response.success(
                com.leikwan.bridge.api.UltraLeaseResponse(ok = true, leases = listOf(lease)),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        var leaseInjections = 0
        var clock = now
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { _, _, _ ->
                leaseInjections += 1
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
            ultraFailureClock = { clock },
        )

        orchestrator.startForForward(90L)
        assertEquals(1, panelApi.resolveUltraLeaseCalls)
        assertEquals(1, leaseInjections)
        val stopsAfterStart = processRunner.stopAllCalls

        // Not due and no failure: no renewal.
        orchestrator.refreshUltraForTest()
        assertEquals(1, panelApi.resolveUltraLeaseCalls)

        // Ordinary telemetry and local-side closes do not renew.
        processRunner.publishRuntimeLine(90L, "[ultra-client] 2026/09/24 17:02:22 tcp stream ended forward_id=90 direction=upstream stage=local_read reason=reset generation=g")
        processRunner.publishRuntimeLine(90L, "[ultra-client] 2026/09/24 17:02:22 active candidate forwarding test succeeded protocol=tcp forward_id=90")
        orchestrator.refreshUltraForTest()
        assertEquals(1, panelApi.resolveUltraLeaseCalls)

        // A line re-acquisition: every stream is aborted by the relay at once -> one renewal.
        repeat(40) {
            processRunner.publishRuntimeLine(
                90L,
                "[ultra-client] 2026/09/24 17:02:22 tcp stream ended forward_id=90 direction=upstream stage=remote_write reason=aborted generation=g",
            )
        }
        orchestrator.refreshUltraForTest()
        assertEquals(2, panelApi.resolveUltraLeaseCalls)
        assertEquals(2, leaseInjections)
        assertEquals("renewal is injected in place", 1, processRunner.started.size)
        assertEquals(stopsAfterStart, processRunner.stopAllCalls)
        orchestrator.refreshUltraForTest()
        assertEquals("the signal is consumed by one renewal", 2, panelApi.resolveUltraLeaseCalls)

        // More failures inside the 20 s window: no second renewal.
        clock = now.plusSeconds(10)
        processRunner.publishRuntimeLine(90L, "[ultra-client] 2026/09/24 17:02:32 tcp stream setup failed forward_id=90: provider handshake failed")
        orchestrator.refreshUltraForTest()
        assertEquals(2, panelApi.resolveUltraLeaseCalls)

        // Still failing after the window (the first renewal raced the upload): renew again.
        clock = now.plusSeconds(21)
        processRunner.publishRuntimeLine(90L, "[ultra-client] 2026/09/24 17:02:43 external UoT carrier reconnect forward_id=90 elapsed=1s reconnects=2 drops=16 reason=EOF")
        orchestrator.refreshUltraForTest()
        assertEquals(3, panelApi.resolveUltraLeaseCalls)
        assertEquals(1, processRunner.started.size)

        orchestrator.stop()
    }

    @Test
    fun `a changed relay set never restarts the VPN service`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-relay-change-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-relay-change-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val target = "origin.example:443"
        val generation = "a".repeat(64)
        val digest = com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)
        // renew_after has passed, so every refresh renews.
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":90, "service_name":"Ultra service", "target":"$target", "listen_port":11090,
              "mode":"ultra", "backend_mode":"ultra", "client_mode":"ultra", "transport":"tcp",
              "service_status":"active", "endpoint_mode":"managed", "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
                "policy_generation":"$generation", "issued_at":"${now.minusSeconds(20)}",
                "renew_after":"${now.minusSeconds(10)}", "expires_at":"${now.plusSeconds(180)}",
                "service_state":"active", "transport":"tcp", "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576, "target_digest":"$digest"
              }
            }
            """.trimIndent(),
        )
        val lease = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
              "policy_generation":"$generation", "lease_id":"ul2_test_lease_1234567890",
              "expires_at":"${now.plusSeconds(180)}", "transport":"tcp", "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576, "selection_ref":"selection_ref_1234567890",
              "target_digest":"$digest", "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        fun moveRelay(ip: String) {
            lease.add("endpoint", JsonParser.parseString("""{"ip":"$ip","port":5438}"""))
            lease.add("direct_ip_cidrs", JsonParser.parseString("""["$ip/32"]"""))
        }
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            ultraLeaseResponse = Response.success(
                com.leikwan.bridge.api.UltraLeaseResponse(ok = true, leases = listOf(lease)),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        // Replies of the local runtime, in call order; empty means accept.
        val runtimeReplies = ArrayDeque<Boolean>()
        val injectedRelays = mutableListOf<String>()
        var vpnStarts = 0
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { _, _, runtimeLease ->
                injectedRelays += runtimeLease.directConnectivity.ipCidrs.single()
                runtimeReplies.removeFirstOrNull() ?: true
            },
            vpnServiceStarter = { _ -> vpnStarts += 1 },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        orchestrator.startForForward(90L)
        assertEquals(1, vpnStarts)
        val stopsAfterStart = processRunner.stopAllCalls

        // The runtime takes the new relay in place: nothing restarts.
        moveRelay("198.51.100.7")
        orchestrator.refreshUltraForTest()
        assertEquals("198.51.100.7/32", injectedRelays.last())
        assertEquals(1, processRunner.started.size)
        assertTrue(processRunner.stopped.isEmpty())

        // The runtime refuses the new relay in place: only the runtime restarts, on the same port.
        moveRelay("198.51.100.8")
        runtimeReplies += listOf(false, true)
        orchestrator.refreshUltraForTest()
        assertEquals(listOf("198.51.100.8/32", "198.51.100.8/32"), injectedRelays.takeLast(2))
        assertEquals(2, processRunner.started.size)
        assertEquals(listOf(90L), processRunner.stopped)
        val restarted = processRunner.started.last()
        assertFalse("route-rule runtimes must survive", restarted.cleanupStale)
        assertEquals(11090, restarted.runtimeContext.localListenPort)

        // A failed runtime restart is retried on the next renewal even without a relay change.
        moveRelay("198.51.100.9")
        runtimeReplies += listOf(false, false)
        assertTrue(runCatching { orchestrator.refreshUltraForTest() }.isFailure)
        assertEquals(3, processRunner.started.size)
        moveRelay("198.51.100.8")
        val injectionsBeforeRetry = injectedRelays.size
        orchestrator.refreshUltraForTest()
        assertEquals("pending restart skips the in-place attempt", injectionsBeforeRetry + 1, injectedRelays.size)
        assertEquals(4, processRunner.started.size)

        assertEquals("the VPN service is never restarted", 1, vpnStarts)
        assertEquals(stopsAfterStart, processRunner.stopAllCalls)
        orchestrator.stop()
    }

    @Test
    fun `an early renewal that gets the running grant back does not lease again`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-early-renewal-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-early-renewal-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val target = "origin.example:443"
        val generation = "a".repeat(64)
        val digest = com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)
        // renew_after is inside the 30 s early-renewal margin.
        val access = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
              "policy_generation":"$generation", "issued_at":"${now.minusSeconds(220)}",
              "renew_after":"${now.plusSeconds(20)}", "expires_at":"${now.plusSeconds(300)}",
              "service_state":"active", "transport":"tcp", "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576, "target_digest":"$digest"
            }
            """.trimIndent(),
        ).asJsonObject
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":90, "service_name":"Ultra service", "target":"$target", "listen_port":11090,
              "mode":"ultra", "backend_mode":"ultra", "client_mode":"ultra", "transport":"tcp",
              "service_status":"active", "endpoint_mode":"managed", "endpoints":[]
            }
            """.trimIndent(),
        ).asJsonObject.apply { add("ultra_access", access) }
        val lease = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2", "forward_id":90, "session_ticket":"ticket-90",
              "policy_generation":"$generation", "lease_id":"ul2_test_lease_1234567890",
              "expires_at":"${now.plusSeconds(300)}", "transport":"tcp", "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576, "selection_ref":"selection_ref_1234567890",
              "target_digest":"$digest", "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            ultraLeaseResponse = Response.success(
                com.leikwan.bridge.api.UltraLeaseResponse(ok = true, leases = listOf(lease)),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        var clock = now
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { _, _, _ -> true },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
            ultraFailureClock = { clock },
        )

        orchestrator.startForForward(90L)
        assertEquals(1, panelApi.resolveUltraLeaseCalls)

        // Due by the margin, but the panel still serves the running grant: no new lease.
        orchestrator.refreshUltraForTest(now)
        assertEquals(1, panelApi.resolveUltraLeaseCalls)

        // A credential-suspect failure still re-leases on the running grant.
        repeat(5) {
            processRunner.publishRuntimeLine(
                90L,
                "[ultra-client] 2026/09/24 17:02:22 tcp stream ended forward_id=90 direction=upstream stage=remote_write reason=aborted generation=g",
            )
        }
        orchestrator.refreshUltraForTest(now)
        assertEquals(2, panelApi.resolveUltraLeaseCalls)

        // Past renew_after the panel rotates the grant and the renewal leases it.
        access.addProperty("session_ticket", "ticket-90-next")
        access.addProperty("renew_after", now.plusSeconds(260).toString())
        lease.addProperty("session_ticket", "ticket-90-next")
        clock = now.plusSeconds(21)
        orchestrator.refreshUltraForTest(now.plusSeconds(21))
        assertEquals(3, panelApi.resolveUltraLeaseCalls)
        assertEquals(1, processRunner.started.size)

        orchestrator.stop()
    }

    @Test
    fun `an app group whose line cannot start fails the connect instead of riding the selected node`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-app-group-fail-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-app-group-fail-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        fun ultraForward(forwardId: Long, listenPort: Int, target: String) = JsonParser.parseString(
            """
            {
              "forward_id":$forwardId,
              "service_name":"Ultra $forwardId",
              "target":"$target",
              "listen_port":$listenPort,
              "mode":"ultra",
              "backend_mode":"ultra",
              "client_mode":"ultra",
              "transport":"tcp",
              "service_status":"active",
              "endpoint_mode":"managed",
              "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2",
                "forward_id":$forwardId,
                "session_ticket":"ticket-$forwardId",
                "policy_generation":"${"a".repeat(64)}",
                "issued_at":"${now.minusSeconds(20)}",
                "renew_after":"${now.minusSeconds(10)}",
                "expires_at":"${now.plusSeconds(180)}",
                "service_state":"active",
                "transport":"tcp",
                "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576,
                "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
              }
            }
            """.trimIndent(),
        )
        fun ultraLease(forwardId: Long, target: String, relayIp: String) = Response.success(
            com.leikwan.bridge.api.UltraLeaseResponse(
                ok = true,
                leases = listOf(
                    JsonParser.parseString(
                        """
                        {
                          "protocol":"leikwan-ultra-policy-v2",
                          "forward_id":$forwardId,
                          "session_ticket":"ticket-$forwardId",
                          "policy_generation":"${"a".repeat(64)}",
                          "lease_id":"ul2_test_lease_${forwardId}_1234567890",
                          "expires_at":"${now.plusSeconds(180)}",
                          "transport":"tcp",
                          "rate_limit_mbps":20,
                          "quota_remaining_bytes":1048576,
                          "selection_ref":"selection_ref_1234567890",
                          "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}",
                          "nonce_hash":"${"b".repeat(64)}",
                          "endpoint":{"ip":"$relayIp","port":5438},
                          "credential":{"user":"line-user","password":"line-password"},
                          "direct_ip_cidrs":["$relayIp/32"]
                        }
                        """.trimIndent(),
                    ).asJsonObject,
                ),
            ),
        )
        val hongKongTarget = "hk.example.com:443"
        val tokyoTarget = "tokyo.example.com:443"
        val hongKongLease = ultraLease(90L, hongKongTarget, "203.0.113.90")
        val tokyoLease = ultraLease(91L, tokyoTarget, "203.0.113.91")
        // The selected line leases first, then the group's line; a renewal repeats that order.
        val panelApi = FakePanelApi(
            forwards = listOf(ultraForward(90L, 11090, hongKongTarget), ultraForward(91L, 11091, tokyoTarget)),
            ultraLeaseResponses = listOf(hongKongLease, tokyoLease, hongKongLease, tokyoLease),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val hongKongNode = db.proxyNodeDao().insert(ssNode("hk"))
        val tokyoNode = db.proxyNodeDao().insert(ssNode("tokyo"))
        prefs.selectedProxyId = hongKongNode
        val netflix = "com.netflix.mediaclient"
        prefs.appRouting = com.leikwan.bridge.routing.AppRoutingConfig().withGroup(
            com.leikwan.bridge.routing.AppGroup(
                id = "tokyo",
                name = "Tokyo",
                forwardId = 91L,
                nodeId = tokyoNode,
                packages = listOf(netflix),
            ),
        )
        val processRunner = RecordingProcessRunner(failForProcessKeys = setOf(91L))
        val leasesSent = mutableListOf<Long>()
        var vpnStarts = 0
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { socketPath, policy, runtimeLease ->
                assertTrue(socketPath.contains("forward-${policy.forwardId}-"))
                assertEquals("203.0.113.${policy.forwardId}/32", runtimeLease.directConnectivity.ipCidrs.single())
                leasesSent += policy.forwardId
                true
            },
            vpnServiceStarter = { _ -> vpnStarts += 1 },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        val failure = runCatching { orchestrator.startForForward(90L) }.exceptionOrNull()

        assertTrue(failure?.message.orEmpty().contains("#91"))
        assertEquals("the VPN never starts on the selected node alone", 0, vpnStarts)
        assertTrue("the selected line's runtime is stopped again", 90L in processRunner.stopped)

        orchestrator.stop()
    }

    @Test
    fun `an app group on another line runs its own Ultra runtime and renews with the selected one`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-app-group-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-app-group-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        fun ultraForward(forwardId: Long, listenPort: Int, target: String) = JsonParser.parseString(
            """
            {
              "forward_id":$forwardId,
              "service_name":"Ultra $forwardId",
              "target":"$target",
              "listen_port":$listenPort,
              "mode":"ultra",
              "backend_mode":"ultra",
              "client_mode":"ultra",
              "transport":"tcp",
              "service_status":"active",
              "endpoint_mode":"managed",
              "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2",
                "forward_id":$forwardId,
                "session_ticket":"ticket-$forwardId",
                "policy_generation":"${"a".repeat(64)}",
                "issued_at":"${now.minusSeconds(20)}",
                "renew_after":"${now.minusSeconds(10)}",
                "expires_at":"${now.plusSeconds(180)}",
                "service_state":"active",
                "transport":"tcp",
                "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576,
                "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
              }
            }
            """.trimIndent(),
        )
        fun ultraLease(forwardId: Long, target: String, relayIp: String) = Response.success(
            com.leikwan.bridge.api.UltraLeaseResponse(
                ok = true,
                leases = listOf(
                    JsonParser.parseString(
                        """
                        {
                          "protocol":"leikwan-ultra-policy-v2",
                          "forward_id":$forwardId,
                          "session_ticket":"ticket-$forwardId",
                          "policy_generation":"${"a".repeat(64)}",
                          "lease_id":"ul2_test_lease_${forwardId}_1234567890",
                          "expires_at":"${now.plusSeconds(180)}",
                          "transport":"tcp",
                          "rate_limit_mbps":20,
                          "quota_remaining_bytes":1048576,
                          "selection_ref":"selection_ref_1234567890",
                          "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}",
                          "nonce_hash":"${"b".repeat(64)}",
                          "endpoint":{"ip":"$relayIp","port":5438},
                          "credential":{"user":"line-user","password":"line-password"},
                          "direct_ip_cidrs":["$relayIp/32"]
                        }
                        """.trimIndent(),
                    ).asJsonObject,
                ),
            ),
        )
        val hongKongTarget = "hk.example.com:443"
        val tokyoTarget = "tokyo.example.com:443"
        val hongKongLease = ultraLease(90L, hongKongTarget, "203.0.113.90")
        val tokyoLease = ultraLease(91L, tokyoTarget, "203.0.113.91")
        // The selected line leases first, then the group's line; a renewal repeats that order.
        val panelApi = FakePanelApi(
            forwards = listOf(ultraForward(90L, 11090, hongKongTarget), ultraForward(91L, 11091, tokyoTarget)),
            ultraLeaseResponses = listOf(hongKongLease, tokyoLease, hongKongLease, tokyoLease),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val hongKongNode = db.proxyNodeDao().insert(ssNode("hk"))
        val tokyoNode = db.proxyNodeDao().insert(ssNode("tokyo"))
        prefs.selectedProxyId = hongKongNode
        val netflix = "com.netflix.mediaclient"
        prefs.appRouting = com.leikwan.bridge.routing.AppRoutingConfig().withGroup(
            com.leikwan.bridge.routing.AppGroup(
                id = "tokyo",
                name = "Tokyo",
                forwardId = 91L,
                nodeId = tokyoNode,
                packages = listOf(netflix),
            ),
        )
        val processRunner = RecordingProcessRunner()
        val leasesSent = mutableListOf<Long>()
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { socketPath, policy, runtimeLease ->
                assertTrue(socketPath.contains("forward-${policy.forwardId}-"))
                assertEquals("203.0.113.${policy.forwardId}/32", runtimeLease.directConnectivity.ipCidrs.single())
                leasesSent += policy.forwardId
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        orchestrator.startForForward(90L)

        assertEquals(listOf(90L, 91L), processRunner.started.map { it.forwardId })
        assertFalse("the group's runtime must not reap the selected one", processRunner.started[1].cleanupStale)
        assertEquals(listOf(11090, 11091), processRunner.awaitedPorts)
        assertEquals(listOf(90L, 91L), leasesSent)
        val config = JsonParser.parseString(LeikwanVpnService.configJson).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }.associateBy { it.get("tag").asString }
        assertEquals(11090, outbounds.getValue("proxy-$hongKongNode").get("server_port").asInt)
        assertEquals(11091, outbounds.getValue("proxy-$tokyoNode").get("server_port").asInt)
        assertEquals("proxy-$hongKongNode", outbounds.getValue("proxy").get("default").asString)
        val packageRule = config.getAsJsonObject("route").getAsJsonArray("rules")
            .map { it.asJsonObject }
            .first { it.has("package_name") }
        assertEquals(listOf(netflix), packageRule.getAsJsonArray("package_name").map { it.asString })
        assertEquals("proxy-$tokyoNode", packageRule.get("outbound").asString)

        // Both lines are past renew_after: each renews in place, nothing restarts.
        orchestrator.refreshUltraForTest()
        assertEquals(listOf(90L, 91L, 90L, 91L), leasesSent)
        assertEquals(2, processRunner.started.size)

        orchestrator.stop()
    }

    private class RecordingProcessRunner(
        private val failForProcessKeys: Set<Long> = emptySet(),
    ) : ProcessRunner() {
        data class Started(
            val forwardId: Long,
            val runtimeContext: RuntimeContext,
            val cleanupStale: Boolean,
        )

        val started = mutableListOf<Started>()
        val stopped = mutableListOf<Long>()
        val awaitedPorts = mutableListOf<Int>()
        var stopAllCalled = false
        var stopAllCalls = 0

        override fun start(forwardId: Long, runtimeContext: RuntimeContext, cleanupStale: Boolean) {
            if (forwardId in failForProcessKeys) {
                throw IllegalStateException("planned failure for process $forwardId")
            }
            started += Started(forwardId, runtimeContext, cleanupStale)
        }

        override fun stopForward(forwardId: Long) {
            stopped += forwardId
        }

        override fun stopAll() {
            stopAllCalled = true
            stopAllCalls += 1
        }

        override fun awaitLocalPort(port: Int, timeoutMs: Long) {
            awaitedPorts += port
        }
    }

    private class RecordingBinaryInstaller(
        context: Context,
        private val binaries: RuntimeBinaryPaths,
    ) : RuntimeBinaryInstaller(context) {
        var prepareCalls = 0

        override fun prepareBinaryPaths(): RuntimeBinaryPaths {
            prepareCalls += 1
            return binaries
        }
    }

    private class FakePanelApi(
        private val forwards: List<JsonElement>,
        private val routeDirections: List<RouteDirectionDto> = emptyList(),
        private val clientRules: List<ClientRouteRuleDto> = emptyList(),
        private val bindings: List<DirectionRuntimeBindingDto> = emptyList(),
        private val capabilities: RouteRuleCapabilitiesDto? = null,
        private val ultraLeaseResponse: Response<com.leikwan.bridge.api.UltraLeaseResponse> = Response.success(
            com.leikwan.bridge.api.UltraLeaseResponse(ok = false),
        ),
        private val nativeOutbound: NativeOutboundCapabilityDto? = null,
        private val ultraLeaseResponses: List<Response<com.leikwan.bridge.api.UltraLeaseResponse>> = emptyList(),
    ) : PanelApi {
        var resolveUltraLeaseCalls = 0
            private set

        override suspend fun deviceChallenge(request: DeviceChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollDevice(request: DeviceEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollDeviceIdentity(request: DeviceIdentityEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun deviceRenewalChallenge(request: DeviceChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun renewDevice(request: DeviceRenewRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeChallenge(request: EnrollmentCodeChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeEnroll(request: EnrollmentCodeEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeIdentityEnroll(request: EnrollmentCodeIdentityEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun acknowledgeDevice(auth: String, request: DeviceAckRequest): Response<DeviceAckResponse> =
            throw UnsupportedOperationException()

        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse = HeartbeatResponse(ok = true)
        override suspend fun resolveUltraLease(
            auth: String,
            request: com.leikwan.bridge.api.UltraLeaseRequest,
            leaseKey: String?,
        ): Response<com.leikwan.bridge.api.UltraLeaseResponse> {
            val index = resolveUltraLeaseCalls++
            return ultraLeaseResponses.getOrNull(index)
                ?: ultraLeaseResponses.lastOrNull()
                ?: ultraLeaseResponse
        }
        override suspend fun pullConfigs(auth: String): PullConfigsResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = forwards,
            routeDirections = routeDirections,
            clientRules = clientRules,
            directionRuntimeBindings = bindings,
            routeRuleCapabilities = capabilities,
            nativeOutbound = nativeOutbound,
        )
        override suspend fun createClientRule(auth: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun updateClientRule(auth: String, ruleId: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun deleteClientRule(auth: String, ruleId: String): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun getForwardConfig(auth: String, forwardId: Long): JsonElement = JsonObject()
    }

    private fun ssNode(name: String): ProxyNode = ProxyNode(
        name = name,
        type = "ss",
        originalServer = "$name.example.com",
        originalPort = 443,
        configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        enabled = true,
    )

    private fun sampleBondForward(
        forwardId: Long = 26L,
        listenPort: Int = 11024,
        remoteTarget: String = "138.252.162.120:9000",
        routeRuleHelperOnly: Boolean = false,
    ): ForwardContract = ForwardContract(
        forwardId = forwardId,
        serviceName = "bond-26",
        target = "193.227.109.82:5201",
        listenPort = listenPort,
        mode = ForwardMode.BOND,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "manual",
        sni = "relay.example.com",
        pathPrefix = "/route-key",
        speedLimitMbps = 500,
        endpoints = listOf(
            ForwardEndpoint(
                endpointId = 184,
                node = "gz-1",
                host = "8.163.90.132",
                port = 7001,
                cdnHost = null,
                priority = 1,
                dynamicPriority = null,
                weight = 1,
                healthStatus = "healthy",
                supportsQuicLeg = false,
                ingressProtocol = null,
                ingressAuthToken = null,
            ),
        ),
        routeRuleHelperOnly = routeRuleHelperOnly,
        bonding = BondingContract(
            ingressMode = "wss_path_mux",
            routeKey = "/route-key",
            remoteTarget = remoteTarget,
            sessionToken = "tcp-token",
            udpSessionToken = "udp-token",
            chunkSize = null,
            maxWindowBytes = null,
            retransmitMs = null,
            connectTimeoutMs = null,
            joinTimeoutMs = null,
            adaptiveJoinTimeout = false,
            joinTimeoutFloorMs = null,
            joinTimeoutCeilingMs = null,
            joinTimeoutPerLegMs = null,
            interactiveChunkBytes = null,
            latencyGuardBytes = null,
            interactiveRttSlackMs = null,
            warmupTriggerBytes = null,
            warmupSliceBytes = null,
            warmupLegDelayMs = null,
            bulkPromoteBytes = null,
            legCooldownMs = null,
            quicEnabled = false,
            quicViaSlave = false,
            activeLegLimit = 0,
            quicActiveLegLimit = 0,
            wssStandbyEnabled = true,
            disableLocalRateLimit = true,
            quicServerName = null,
            quicInsecure = true,
            quicPriority = 0,
            wsPriority = 10,
        ),
        mptcp = null,
    )

    private fun ForwardContract.toPanelJson(): JsonElement = JsonParser.parseString(
        """
        {
          "forward_id": $forwardId,
          "service_name": "$serviceName",
          "target": "$target",
          "listen_port": $listenPort,
          "mode": "${mode.wireValue}",
          "backend_mode": "$backendMode",
          "contract_version": $contractVersion,
          "client_mode": "$clientMode",
          "runtime_preference": "$runtimePreference",
          "bond_ingress_mode": "$bondIngressMode",
          "active_family": "$activeFamily",
          "aggregation_enabled": $aggregationEnabled,
          "target_contract": "$targetContract",
          "sidecar_required": $sidecarRequired,
          "transport": "$transport",
          "service_status": "$serviceStatus",
          "endpoint_mode": "$endpointMode",
          "sni": "$sni",
          "path_prefix": "$pathPrefix",
          "speed_limit_mbps": $speedLimitMbps,
          "route_rule_helper_only": $routeRuleHelperOnly,
          "endpoints": [
            {"endpoint_id":184,"node":"gz-1","host":"8.163.90.132","port":7001,"priority":1,"weight":1,"health_status":"healthy","supports_quic_leg":false}
          ],
          "bonding": {
            "ingress_mode": "wss_path_mux",
            "route_key": "/route-key",
            "remote_target": "${bonding?.remoteTarget}",
            "session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "wss_standby_enabled": true,
            "disable_local_rate_limit": true,
            "quic_enabled": false,
            "quic_via_slave": false,
            "active_leg_limit": 0,
            "quic_active_leg_limit": 0,
            "quic_insecure": true,
            "quic_priority": 0,
            "ws_priority": 10
          }
        }
        """.trimIndent()
    )
}
