package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.api.ClientRuleWriteRequest
import com.leikwan.bridge.api.ClientRuleWriteResponse
import com.leikwan.bridge.api.HeartbeatRequest
import com.leikwan.bridge.api.HeartbeatResponse
import com.leikwan.bridge.api.PanelApi
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.api.PullConfigsResponse
import com.leikwan.bridge.api.UltraLeaseRequest
import com.leikwan.bridge.api.UltraLeaseResponse
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.runtime.UltraPolicy
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
import kotlinx.coroutines.runBlocking
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response
import java.io.File
import java.io.IOException
import java.time.Instant

/**
 * W1 offline survival: a control-plane outage must never stop or restart a running Ultra
 * runtime, and a runtime that reports it lost its policy gets a fresh lease, not a restart.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class UltraOfflineSurvivalTest {

    private lateinit var db: AppDatabase
    private val target = "origin.example:443"
    private val generation = "a".repeat(64)

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
    fun `panel outage during renewal keeps the runtime until the panel returns`() = runBlocking {
        val now = Instant.now()
        // renew_after already passed: every control tick attempts a renewal.
        val harness = harness(renewAfter = now.minusSeconds(10), expiresAt = now.plusSeconds(1800))
        harness.orchestrator.startForForward(90L)
        assertEquals(1, harness.processRunner.started.size)
        assertEquals(1, harness.leaseSends)
        val stopAllBefore = harness.processRunner.stopAllCalls

        harness.api.pullFailure = IOException("panel unreachable")
        repeat(3) {
            try {
                harness.orchestrator.refreshUltraForTest()
                fail("a failed renewal must surface to the control loop for backoff")
            } catch (expected: IOException) {
                // retained
            }
        }
        harness.api.pullFailure = null
        harness.api.leaseFailure = Response.error(
            503,
            """{"ok":false,"error":"service_unavailable"}""".toResponseBody(),
        )
        try {
            harness.orchestrator.refreshUltraForTest()
            fail("a 5xx lease response must surface to the control loop for backoff")
        } catch (_: IllegalStateException) {
        }

        assertEquals("no restart during the outage", 1, harness.processRunner.started.size)
        assertTrue("no stop during the outage", harness.processRunner.stopped.isEmpty())
        assertEquals(stopAllBefore, harness.processRunner.stopAllCalls)
        assertEquals(1, harness.leaseSends)

        // The panel is back: the same runtime receives the renewed lease in place.
        harness.api.leaseFailure = null
        harness.orchestrator.refreshUltraForTest()
        assertEquals(2, harness.leaseSends)
        assertEquals(1, harness.processRunner.started.size)
        assertEquals(stopAllBefore, harness.processRunner.stopAllCalls)
        harness.orchestrator.stop()
    }

    @Test
    fun `renewal waits for renew_after even with a long grant`() = runBlocking {
        val now = Instant.now()
        val harness = harness(renewAfter = now.plusSeconds(240), expiresAt = now.plusSeconds(1800))
        harness.orchestrator.startForForward(90L)
        val pullsAfterStart = harness.api.pullCalls

        harness.orchestrator.refreshUltraForTest(now)
        assertEquals("not due yet", pullsAfterStart, harness.api.pullCalls)

        harness.orchestrator.refreshUltraForTest(now.plusSeconds(215))
        assertEquals("due 30 s before renew_after", pullsAfterStart + 1, harness.api.pullCalls)
        assertEquals(2, harness.leaseSends)
        harness.orchestrator.stop()
    }

    @Test
    fun `runtime that restarted into awaiting policy is re-leased not restarted`() = runBlocking {
        val now = Instant.now()
        val harness = harness(renewAfter = now.plusSeconds(240), expiresAt = now.plusSeconds(1800))
        harness.orchestrator.startForForward(90L)
        val statusFile = statusFile(harness.context)

        // A status left over from before our lease injection does not count.
        statusFile.writeText(
            """{"state":"degraded","policy_state":"awaiting","error_code":"awaiting_policy","updated_at":"${now.minusSeconds(60)}"}""",
        )
        harness.orchestrator.refreshUltraForTest(now)
        assertEquals(1, harness.leaseSends)

        // The guard restarted the process; the new instance waits for a policy.
        statusFile.writeText(
            """{"state":"degraded","policy_state":"awaiting","error_code":"awaiting_policy","updated_at":"${now.plusSeconds(5)}"}""",
        )
        harness.orchestrator.refreshUltraForTest(now.plusSeconds(6))
        assertEquals("fresh lease injected before renew_after", 2, harness.leaseSends)
        assertEquals("never restarted for an idle runtime", 1, harness.processRunner.started.size)
        assertTrue(harness.processRunner.stopped.isEmpty())

        // An active runtime before renew_after is left alone.
        statusFile.writeText("""{"state":"ready","policy_state":"active","updated_at":"${now.plusSeconds(10)}"}""")
        harness.orchestrator.refreshUltraForTest(now.plusSeconds(11))
        assertEquals(2, harness.leaseSends)
        harness.orchestrator.stop()
    }

    private fun statusFile(context: Context): File =
        File(File(context.filesDir, "ultra"), "forward-90-${generation.take(12)}.status.json")

    private class Harness(
        val context: Context,
        val orchestrator: TunnelOrchestrator,
        val api: FakePanelApi,
        val processRunner: RecordingProcessRunner,
        private val sends: IntArray,
    ) {
        val leaseSends: Int get() = sends[0]
    }

    private fun harness(renewAfter: Instant, expiresAt: Instant): Harness {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("ultra-offline-survival-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-offline"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 3_600_000L

        val issuedAt = minOf(renewAfter, Instant.now()).minusSeconds(20)
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
                "issued_at":"$issuedAt",
                "renew_after":"$renewAfter",
                "expires_at":"$expiresAt",
                "service_state":"active",
                "transport":"tcp",
                "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576,
                "target_digest":"${UltraPolicy.targetDigest(target)}"
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
              "expires_at":"$expiresAt",
              "transport":"tcp",
              "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576,
              "selection_ref":"selection_ref_1234567890",
              "target_digest":"${UltraPolicy.targetDigest(target)}",
              "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        val api = FakePanelApi(forward, lease)
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = api,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        val sends = IntArray(1)
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
                object : RuntimeBinaryInstaller(context) {
                    override fun prepareBinaryPaths(): RuntimeBinaryPaths = RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    )
                }
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { _, _, _ ->
                sends[0] += 1
                true
            },
            ultraLeaseBatchSender = { _, _, _ ->
                sends[0] += 1
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )
        return Harness(context, orchestrator, api, processRunner, sends)
    }

    private class RecordingProcessRunner : ProcessRunner() {
        val started = mutableListOf<RuntimeContext>()
        val stopped = mutableListOf<Long>()
        var stopAllCalls = 0

        override fun start(forwardId: Long, runtimeContext: RuntimeContext, cleanupStale: Boolean) {
            started += runtimeContext
        }

        override fun stopForward(forwardId: Long) {
            stopped += forwardId
        }

        override fun stopAll() {
            stopAllCalls += 1
        }

        override fun awaitLocalPort(port: Int, timeoutMs: Long) = Unit
    }

    private class FakePanelApi(
        private val forward: JsonElement,
        private val lease: JsonObject,
    ) : PanelApi {
        @Volatile var pullFailure: Exception? = null
        @Volatile var leaseFailure: Response<UltraLeaseResponse>? = null
        var pullCalls = 0
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

        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse {
            pullFailure?.let { throw it }
            return HeartbeatResponse(ok = true)
        }

        override suspend fun resolveUltraLease(
            auth: String,
            request: UltraLeaseRequest,
            leaseKey: String?,
        ): Response<UltraLeaseResponse> =
            leaseFailure ?: Response.success(UltraLeaseResponse(ok = true, leases = listOf(lease.deepCopy())))

        override suspend fun pullConfigs(auth: String): PullConfigsResponse {
            pullCalls += 1
            pullFailure?.let { throw it }
            return PullConfigsResponse(ok = true, client = JsonObject(), forwards = listOf(forward))
        }

        override suspend fun createClientRule(auth: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> =
            throw UnsupportedOperationException()
        override suspend fun updateClientRule(auth: String, ruleId: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> =
            throw UnsupportedOperationException()
        override suspend fun deleteClientRule(auth: String, ruleId: String): Response<ClientRuleWriteResponse> =
            throw UnsupportedOperationException()
        override suspend fun getForwardConfig(auth: String, forwardId: Long): JsonElement = JsonObject()
    }
}
