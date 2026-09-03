package com.leikwan.bridge.api

import android.content.Context
import android.os.Build
import com.google.gson.JsonElement
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.runtime.AndroidEffectiveForward
import com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilder
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardContractParser
import com.leikwan.bridge.runtime.ForwardFilter
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.runtime.NATIVE_OUTBOUND_PURPOSE
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_RESTRICTED
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_UNRESTRICTED
import com.leikwan.bridge.runtime.isNativeRoutePolicyValue
import com.leikwan.bridge.runtime.nativeDirectionsMatch
import com.leikwan.bridge.runtime.normalizeNativeRoutePolicy
import com.leikwan.bridge.runtime.UltraLease
import com.leikwan.bridge.runtime.UltraPolicy
import com.leikwan.bridge.net.AppDns
import com.leikwan.bridge.net.MirrorNoSni
import com.leikwan.bridge.security.AndroidAttestationProofFactory
import com.leikwan.bridge.security.AndroidDeviceIdentity
import com.leikwan.bridge.security.AttestationProof
import com.leikwan.bridge.security.DeviceSecurity
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.DeviceAckRequest
import com.leikwan.bridge.session.DeviceChallengeRequest
import com.leikwan.bridge.session.DeviceChallengeResponse
import com.leikwan.bridge.session.DeviceEnrollRequest
import com.leikwan.bridge.session.DeviceEnrollResponse
import com.leikwan.bridge.session.DeviceIdentityEnrollRequest
import com.leikwan.bridge.session.DeviceRenewRequest
import com.leikwan.bridge.session.EnrollmentCodeChallengeRequest
import com.leikwan.bridge.session.EnrollmentCodeEnrollRequest
import com.leikwan.bridge.session.EnrollmentCodeIdentityEnrollRequest
import com.leikwan.bridge.session.EnrollmentResult
import com.leikwan.bridge.session.PanelControlException
import okhttp3.OkHttpClient
import kotlinx.coroutines.CancellationException
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.net.URI
import java.time.Instant
import java.security.SecureRandom
import java.util.Base64
import java.util.concurrent.TimeUnit

class PanelRepository(
    private val context: Context,
    prefsOverride: Prefs? = null,
    apiOverride: PanelApi? = null,
    private val routeRuleStore: ClientRouteRuleStore = ClientRouteRuleStore.from(context),
    private val httpClientOverride: OkHttpClient? = null,
    private val deviceIdProvider: (Long) -> String = AndroidDeviceIdentity()::deviceId,
    private val identityPublicKeyProvider: () -> ByteArray = { AndroidDeviceIdentity().publicKeySpki() },
    private val attestationProofProvider: (String, Long, String) -> AttestationProof =
        AndroidAttestationProofFactory()::create,
    private val deviceNameProvider: () -> String = {
        listOf(Build.MANUFACTURER, Build.MODEL).filter { !it.isNullOrBlank() }.joinToString(" ")
    },
    private val deviceSecurityCheck: () -> DeviceSecurityResult = {
        DeviceSecurity.evaluate(context.applicationContext)
    },
    private val nowMs: () -> Long = System::currentTimeMillis,
) {
    private val prefs = prefsOverride ?: Prefs.get(context)

    private val api: PanelApi = apiOverride ?: buildApi(prefs.panelUrl)
    private var cachedForwardConfigs = mutableMapOf<Long, JsonElement>()
    private var cachedForwardContracts = mutableMapOf<Long, ForwardContract>()
    private var cachedEffectiveForwards = mutableMapOf<Long, AndroidEffectiveForward>()
    @Volatile private var cachedNativeOutbound: NativeOutboundCapability? = null

    data class TerminalDeviceAcknowledgement internal constructor(
        val deviceId: String,
        val authorization: String,
    )

    private fun buildApi(baseUrl: String): PanelApi {
        val url = baseUrl.trimEnd('/') + "/"
        val host = runCatching { URI(url).host }.getOrNull()
        val clientBuilder = httpClientOverride?.newBuilder() ?: OkHttpClient.Builder()
            // Panel control-plane calls intentionally do not fall back to system DNS.
            .dns(AppDns.strict())
            .callTimeout(20, TimeUnit.SECONDS)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)

        if (httpClientOverride == null && MirrorNoSni.shouldRewrite(host)) {
            MirrorNoSni.applyTo(clientBuilder)
        }

        clientBuilder
            .addInterceptor(
                PanelDeviceInterceptor(
                    prefs = prefs,
                    deviceNameProvider = deviceNameProvider,
                    clientVersion = BuildConfig.VERSION_NAME,
                    nowMs = nowMs,
                ),
            )
            .addInterceptor(PanelControlInterceptor())

        val client = clientBuilder.build()
        return Retrofit.Builder()
            .baseUrl(url)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PanelApi::class.java)
    }

    private fun authHeader(): String {
        val token = prefs.agentToken ?: throw IllegalStateException("Not enrolled")
        return "Bearer $token"
    }

    suspend fun enroll(clientId: Long, agentToken: String): EnrollmentResult {
        require(clientId > 0L) { "client_id_invalid" }
        require(agentToken.isNotBlank()) { "agent_token_required" }
        ensureDeviceAllowed()

        prefs.clearEnrollment(clearIdentity = false)
        prefs.clientId = clientId
        prefs.agentToken = agentToken.trim()
        prefs.enrolledDeviceId = deviceIdProvider(clientId)
        return try {
            performIdentityEnrollment(clientId, enrollmentCode = null, suppliedAgentToken = agentToken.trim())
        } catch (throwable: Throwable) {
            prefs.clearEnrollment(clearIdentity = false)
            throw throwable
        }
    }

    suspend fun enrollWithCode(clientId: Long, enrollmentCode: String): EnrollmentResult {
        require(clientId > 0L) { "client_id_invalid" }
        require(enrollmentCode.isNotBlank()) { "enrollment_code_required" }
        ensureDeviceAllowed()

        prefs.clearEnrollment(clearIdentity = false)
        prefs.clientId = clientId
        prefs.enrolledDeviceId = deviceIdProvider(clientId)
        return try {
            performIdentityEnrollment(clientId, enrollmentCode = enrollmentCode.trim(), suppliedAgentToken = null)
        } catch (throwable: Throwable) {
            prefs.clearEnrollment(clearIdentity = false)
            throw throwable
        }
    }

    suspend fun requireActiveSession(forceRefresh: Boolean = false): EnrollmentResult {
        ensureDeviceAllowed()
        val clientId = prefs.clientId.takeIf { it > 0L } ?: throw IllegalStateException("Not enrolled")
        val agentToken = prefs.agentToken?.takeIf { it.isNotBlank() } ?: throw IllegalStateException("Not enrolled")
        val expectedDeviceId = deviceIdProvider(clientId)
        if (prefs.enrolledDeviceId != expectedDeviceId) {
            prefs.clearEnrollment(clearIdentity = false)
            throw IllegalStateException("device_identity_changed")
        }
        if (!forceRefresh && prefs.deviceSessionToken?.isNotBlank() == true
            && prefs.deviceSessionExpiresAtMs > nowMs() + SESSION_REFRESH_MARGIN_MS
        ) {
            return EnrollmentResult(clientId, expectedDeviceId, prefs.deviceSessionExpiresAtMs)
        }

        prefs.clearDeviceSession()
        return performIdentityEnrollment(clientId, enrollmentCode = null, suppliedAgentToken = agentToken)
    }

    suspend fun acknowledgeDevice(commandVersion: Long): Boolean {
        val acknowledgement = captureTerminalAcknowledgement() ?: return false
        return acknowledgeTerminal(acknowledgement, commandVersion)
    }

    fun captureTerminalAcknowledgement(): TerminalDeviceAcknowledgement? {
        val deviceId = prefs.enrolledDeviceId?.takeIf { it.isNotBlank() } ?: return null
        val token = prefs.agentToken?.takeIf { it.isNotBlank() } ?: return null
        return TerminalDeviceAcknowledgement(deviceId = deviceId, authorization = "Bearer $token")
    }

    suspend fun acknowledgeTerminal(
        acknowledgement: TerminalDeviceAcknowledgement,
        commandVersion: Long,
    ): Boolean {
        val response = api.acknowledgeDevice(
            acknowledgement.authorization,
            DeviceAckRequest(acknowledgement.deviceId, commandVersion.coerceAtLeast(0L)),
        )
        return response.isSuccessful && response.body()?.ok == true
    }

    private suspend fun performEnrollment(
        clientId: Long,
        agentToken: String?,
        enrollmentCode: String?,
    ): EnrollmentResult {
        val deviceId = prefs.enrolledDeviceId ?: throw IllegalStateException("device_identity_missing")
        if (agentToken != null) prefs.agentToken = agentToken

        val challenge = if (enrollmentCode == null) {
            requireSuccessful(
                api.deviceChallenge(DeviceChallengeRequest(clientId, deviceId)),
                "device_challenge_failed",
            )
        } else {
            requireSuccessful(
                api.enrollmentCodeChallenge(EnrollmentCodeChallengeRequest(enrollmentCode, deviceId)),
                "device_challenge_failed",
            )
        }
        validateChallenge(challenge, clientId)
        val proof = attestationProofProvider(challenge.nonce, clientId, deviceId)
        val response = if (enrollmentCode == null) {
            requireSuccessful(
                api.enrollDevice(
                    DeviceEnrollRequest(clientId, deviceId, challenge.nonce, proof, Build.VERSION.RELEASE.orEmpty()),
                ),
                "device_enrollment_failed",
            )
        } else {
            requireSuccessful(
                api.enrollmentCodeEnroll(
                    EnrollmentCodeEnrollRequest(
                        enrollmentCode,
                        deviceId,
                        challenge.nonce,
                        proof,
                        Build.VERSION.RELEASE.orEmpty(),
                    ),
                ),
                "device_enrollment_failed",
            )
        }
        return persistEnrollment(response, clientId, deviceId, agentToken)
    }

    /** Default Android enrollment uses the persistent Keystore identity only. */
    private suspend fun performIdentityEnrollment(
        clientId: Long,
        enrollmentCode: String?,
        suppliedAgentToken: String?,
    ): EnrollmentResult {
        val deviceId = prefs.enrolledDeviceId ?: throw IllegalStateException("device_identity_missing")
        val challenge = if (enrollmentCode == null) {
            requireSuccessful(
                api.deviceChallenge(DeviceChallengeRequest(clientId, deviceId)),
                "device_challenge_failed",
            )
        } else {
            requireSuccessful(
                api.enrollmentCodeChallenge(EnrollmentCodeChallengeRequest(enrollmentCode, deviceId)),
                "device_challenge_failed",
            )
        }
        validateChallenge(challenge, clientId)

        val identitySpki = identityPublicKeyProvider()
        try {
            if (identitySpki.isEmpty()) throw IllegalStateException("device_identity_missing")
            val encodedIdentity = Base64.getEncoder().encodeToString(identitySpki)
            val response = if (enrollmentCode == null) {
                requireSuccessful(
                    api.enrollDeviceIdentity(
                        DeviceIdentityEnrollRequest(clientId, deviceId, challenge.nonce, encodedIdentity),
                    ),
                    "device_enrollment_failed",
                )
            } else {
                requireSuccessful(
                    api.enrollmentCodeIdentityEnroll(
                        EnrollmentCodeIdentityEnrollRequest(
                            enrollmentCode,
                            deviceId,
                            challenge.nonce,
                            encodedIdentity,
                        ),
                    ),
                    "device_enrollment_failed",
                )
            }
            return persistEnrollment(response, clientId, deviceId, suppliedAgentToken)
        } finally {
            identitySpki.fill(0)
        }
    }

    private fun validateChallenge(challenge: DeviceChallengeResponse, clientId: Long) {
        if (!challenge.ok || challenge.nonce.isBlank()) throw IllegalStateException("device_challenge_invalid")
        if (challenge.clientId != null && challenge.clientId != clientId) {
            throw IllegalStateException("client_id_mismatch")
        }
        if (parseExpiry(challenge.expiresAt) <= nowMs()) {
            throw IllegalStateException("device_challenge_expired")
        }
    }

    private fun persistEnrollment(
        response: DeviceEnrollResponse,
        clientId: Long,
        deviceId: String,
        suppliedAgentToken: String?,
    ): EnrollmentResult {
        if (!response.ok || response.clientId != clientId || response.deviceSession.isBlank()) {
            throw IllegalStateException("device_enrollment_invalid")
        }
        val expiresAtMs = parseExpiry(response.expiresAt)
        if (expiresAtMs <= nowMs()) throw IllegalStateException("device_session_expired")
        val persistedAgentToken = suppliedAgentToken ?: response.agentToken?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("agent_token_missing")

        prefs.clientId = clientId
        prefs.agentToken = persistedAgentToken
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = response.deviceSession
        prefs.deviceSessionExpiresAtMs = expiresAtMs
        routeRuleStore.clear()
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
        cachedNativeOutbound = null
        return EnrollmentResult(clientId, deviceId, expiresAtMs)
    }

    private fun parseExpiry(value: String): Long = runCatching { Instant.parse(value).toEpochMilli() }
        .getOrElse { throw IllegalStateException("device_expiry_invalid") }

    private fun <T> requireSuccessful(response: retrofit2.Response<T>, fallback: String): T {
        if (!response.isSuccessful) {
            throw IllegalStateException(
                PanelErrorParser.messageFromHttp(
                    response.code(),
                    response.message(),
                    response.errorBody()?.string(),
                ),
            )
        }
        return response.body() ?: throw IllegalStateException(fallback)
    }

    private fun ensureDeviceAllowed() {
        when (deviceSecurityCheck()) {
            DeviceSecurityResult.Allowed -> Unit
            DeviceSecurityResult.Rooted -> throw SecurityException("rooted_device_blocked")
            DeviceSecurityResult.Emulator -> throw SecurityException("emulator_blocked")
            DeviceSecurityResult.UnsupportedAbi -> throw SecurityException("unsupported_abi")
            DeviceSecurityResult.Debuggable -> throw SecurityException("debuggable_build_blocked")
        }
    }

    suspend fun pullConfigs(): List<ForwardInfo> {
        return pullCatalog().visibleForwards.map { item ->
            ForwardInfo(
                forwardId = item.forwardId,
                serviceName = item.serviceName,
                target = item.target,
                listenPort = item.listenPort,
                mode = item.mode.wireValue,
                transport = item.transport,
                serviceStatus = item.serviceStatus,
                endpointMode = item.endpointMode,
                speedLimitMbps = item.speedLimitMbps,
            )
        }
    }

    suspend fun pullCatalog(selectedForwardId: Long = prefs.selectedForwardId): ForwardCatalog {
        requireActiveSession()
        try {
            api.heartbeat(authHeader(), HeartbeatRequest())
        } catch (e: CancellationException) {
            throw e
        } catch (e: PanelControlException) {
            throw e
        } catch (_: Exception) {
            // Capability advertisement is best-effort; older or temporarily unavailable
            // panels must not block the authoritative pull.
        }
        val resp = api.pullConfigs(authHeader())
        if (!resp.ok) throw Exception("Pull configs failed")
        routeRuleStore.replaceFromPull(
            routeDirections = resp.routeDirections.orEmpty(),
            entitlements = resp.routeRuleEntitlements.orEmpty(),
            rules = resp.clientRules.orEmpty(),
            bindings = resp.directionRuntimeBindings.orEmpty(),
            capabilities = resp.routeRuleCapabilities,
        )
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
        cachedNativeOutbound = NativeOutboundCapability.from(resp.nativeOutbound)
        val contracts = mutableListOf<ForwardContract>()
        for (raw in resp.forwards) {
            try {
                val contract = ForwardContractParser.parse(raw) ?: continue
                val effective = AndroidEffectiveForwardBuilder.build(contract) ?: continue
                contracts += contract
                cachedForwardConfigs[contract.forwardId] = raw
                cachedForwardContracts[contract.forwardId] = contract
                cachedEffectiveForwards[effective.forwardId] = effective
            } catch (_: Exception) {
                // skip unparseable forwards
            }
        }
        return ForwardFilter.buildCatalog(
            contracts = contracts,
            selectedForwardId = selectedForwardId.takeIf { it > 0L },
        ).copy(nativeOutbound = cachedNativeOutbound)
    }

    fun getCachedConfig(forwardId: Long): JsonElement? = cachedForwardConfigs[forwardId]

    fun getCachedContract(forwardId: Long): ForwardContract? = cachedForwardContracts[forwardId]

    fun getCachedEffectiveForward(forwardId: Long): AndroidEffectiveForward? = cachedEffectiveForwards[forwardId]

    fun getCachedNativeOutbound(): NativeOutboundCapability? = cachedNativeOutbound

    suspend fun heartbeat(): Boolean {
        requireActiveSession()
        return try {
            api.heartbeat(authHeader()).ok
        } catch (e: CancellationException) {
            throw e
        } catch (e: PanelControlException) {
            throw e
        } catch (_: Exception) {
            false
        }
    }

    /** Resolves one ephemeral Ultra lease for the currently trusted device session. */
    suspend fun resolveUltraLease(policy: UltraPolicy): UltraLease {
        val session = requireActiveSession()
        val nonce = ByteArray(16).also(SecureRandom()::nextBytes)
            .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
        val response = try {
            api.resolveUltraLease(
                authHeader(),
                UltraLeaseRequest(
                    deviceId = session.deviceId,
                    forwards = listOf(
                        UltraLeaseForwardRequest(
                            forwardId = policy.forwardId,
                            sessionTicket = policy.sessionTicket,
                            policyGeneration = policy.policyGeneration,
                            nonce = nonce,
                            targetDigest = policy.targetDigest,
                            transport = policy.transport,
                        )
                    ),
                ),
            )
        } catch (e: CancellationException) {
            throw e
        } catch (e: PanelControlException) {
            throw e
        }
        val body = if (response.isSuccessful) response.body() else null
        if (body?.ok != true) {
            throw IllegalStateException(
                body?.error?.takeIf { it.isNotBlank() }
                    ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string()),
            )
        }
        val rawLease = body.leases.singleOrNull()
            ?: throw IllegalStateException("ultra_lease_invalid")
        return UltraLease.from(rawLease, policy, Instant.ofEpochMilli(nowMs()))
            ?: throw IllegalStateException("ultra_lease_invalid")
    }

    /** Resolves one short-lived, forward-bound native SOCKS5 lease without exposing its provider. */
    suspend fun resolveNativeOutboundLease(
        forward: ForwardContract,
        routeScope: String,
        routePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    ): NativeOutboundLease = resolveNativeOutboundLeases(
        forward,
        cachedNativeOutbound,
        routeScope,
        maxCandidates = 1,
        routePolicy = routePolicy,
    ).single()

    suspend fun resolveNativeOutboundLease(
        forward: ForwardContract,
        capability: NativeOutboundCapability?,
        routeScope: String,
        routePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    ): NativeOutboundLease = resolveNativeOutboundLeases(
        forward,
        capability,
        routeScope,
        maxCandidates = 1,
        routePolicy = routePolicy,
    ).single()

    /**
     * Resolves a bounded set of native candidates. The local Ultra adapter probes this batch and
     * atomically keeps the best usable path, so a single stale provider line does not make startup
     * fail when another approved line is available.
     */
    suspend fun resolveNativeOutboundLeases(
        forward: ForwardContract,
        routeScope: String,
        maxCandidates: Int = 4,
        routePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    ): List<NativeOutboundLease> = resolveNativeOutboundLeases(
        forward,
        cachedNativeOutbound,
        routeScope,
        maxCandidates,
        routePolicy,
    )

    suspend fun resolveNativeOutboundLeases(
        forward: ForwardContract,
        capability: NativeOutboundCapability?,
        routeScope: String,
        maxCandidates: Int = 4,
        routePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    ): List<NativeOutboundLease> {
        if (maxCandidates !in 1..4) {
            throw IllegalArgumentException("native_candidate_limit_invalid")
        }
        val normalizedScope = NativeOutboundCapability.normalizeScope(routeScope)
            ?: throw IllegalStateException("native_route_scope_invalid")
        val normalizedPolicy = normalizeNativeRoutePolicy(routePolicy)
        if (!isNativeRoutePolicyValue(routePolicy)) {
            throw IllegalStateException("native_route_policy_invalid")
        }
        if (capability == null || !capability.isUsable()) {
            throw IllegalStateException("native_outbound_not_allowed")
        }
        val carrier = capability.carrier(forward.forwardId)
            ?: throw IllegalStateException("native_outbound_not_allowed")
        if (!nativeDirectionsMatch(forward.direction, carrier.direction)) {
            throw IllegalStateException("native_forward_direction_mismatch")
        }
        if (normalizedScope !in carrier.routeScopes
            || carrier.transport != "tcp+udp"
            || capability.transport != "tcp+udp") {
            throw IllegalStateException("native_route_scope_invalid")
        }
        if (normalizedPolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED && !carrier.unrestrictedAllowed) {
            throw IllegalStateException("native_route_policy_not_allowed")
        }
        val now = Instant.ofEpochMilli(nowMs())
        val policy = UltraPolicy.from(forward, now)
            ?: throw IllegalStateException("native_policy_invalid")
        if (policy.transport != "tcp+udp") {
            throw IllegalStateException("native_transport_invalid")
        }

        val session = requireActiveSession()
        val nonce = ByteArray(16).also(SecureRandom()::nextBytes)
            .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
        val expectedDigest = if (normalizedPolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED) {
            NativeOutboundLease.scopeDigestV2(
                forward.forwardId,
                carrier.direction,
                carrier.transport,
                normalizedScope,
                normalizedPolicy,
            )
        } else {
            NativeOutboundLease.scopeDigest(
                forward.forwardId,
                carrier.direction,
                carrier.transport,
                normalizedScope,
            )
        }
        val response = try {
            api.resolveUltraLease(
                authHeader(),
                UltraLeaseRequest(
                    deviceId = session.deviceId,
                    forwards = listOf(
                        UltraLeaseForwardRequest(
                            forwardId = forward.forwardId,
                            sessionTicket = policy.sessionTicket,
                            policyGeneration = policy.policyGeneration,
                            nonce = nonce,
                            targetDigest = expectedDigest,
                            transport = "tcp+udp",
                            purpose = NATIVE_OUTBOUND_PURPOSE,
                            routeScope = normalizedScope,
                            routePolicy = normalizedPolicy,
                            selectionMode = if (maxCandidates > 1) "auto" else "single",
                            maxCandidates = maxCandidates,
                        ),
                    ),
                ),
            )
        } catch (e: CancellationException) {
            throw e
        } catch (e: PanelControlException) {
            throw e
        }
        val body = if (response.isSuccessful) response.body() else null
        if (body?.ok != true) {
            throw IllegalStateException(
                body?.error?.takeIf { it.isNotBlank() }
                    ?: PanelErrorParser.messageFromHttp(
                        response.code(),
                        response.message(),
                        response.errorBody()?.string(),
                    ),
            )
        }
        if (body.leases.isEmpty() || body.leases.size > maxCandidates) {
            throw IllegalStateException("native_lease_invalid")
        }
        val leases = mutableListOf<NativeOutboundLease>()
        try {
            body.leases.forEach { rawLease ->
                leases += NativeOutboundLease.from(
                    rawLease,
                    policy,
                    carrier,
                    normalizedScope,
                    now,
                    normalizedPolicy,
                )
                    ?: throw IllegalStateException("native_lease_invalid")
            }
            return leases
        } catch (error: Exception) {
            leases.forEach(NativeOutboundLease::wipe)
            throw error
        }
    }

    suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule {
        requireActiveSession()
        val local = routeRuleStore.markPendingCreate(draft)
        return try {
            val response = api.createClientRule(authHeader(), draft.toRequest())
            val body = response.body()
            if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
                val code = body?.error?.takeIf { it.isNotBlank() }
                    ?: body?.message?.takeIf { it.isNotBlank() }
                    ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
                routeRuleStore.markPendingError(local.id, code)
                throw Exception(code)
            }
            routeRuleStore.applyServerRule(body.clientRule, local.id)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            routeRuleStore.markPendingError(local.id, e.message ?: "client_rule_write_failed")
            throw e
        }
    }

    suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule {
        requireActiveSession()
        val local = routeRuleStore.markPendingUpdate(ruleId, draft)
        return try {
            val response = api.updateClientRule(authHeader(), ruleId, draft.toRequest())
            val body = response.body()
            if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
                val code = body?.error?.takeIf { it.isNotBlank() }
                    ?: body?.message?.takeIf { it.isNotBlank() }
                    ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
                routeRuleStore.markPendingError(ruleId, code)
                throw Exception(code)
            }
            routeRuleStore.applyServerRule(body.clientRule, local.id)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            routeRuleStore.markPendingError(ruleId, e.message ?: "client_rule_write_failed")
            throw e
        }
    }

    suspend fun deleteClientRule(ruleId: String): ClientRouteRule {
        requireActiveSession()
        val local = routeRuleStore.markPendingTombstone(ruleId)
        return try {
            val response = api.deleteClientRule(authHeader(), ruleId)
            val body = response.body()
            if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
                val code = body?.error?.takeIf { it.isNotBlank() }
                    ?: body?.message?.takeIf { it.isNotBlank() }
                    ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
                routeRuleStore.markPendingError(ruleId, code)
                throw Exception(code)
            }
            routeRuleStore.applyServerDelete(body.clientRule, local.id)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            routeRuleStore.markPendingError(ruleId, e.message ?: "client_rule_write_failed")
            throw e
        }
    }

    private fun ClientRuleWriteDraft.toRequest(): ClientRuleWriteRequest = ClientRuleWriteRequest(
        serviceScopeId = serviceScopeId?.trim(),
        destinationIp = destinationIp?.trim(),
        destinationPort = destinationPort,
        directionId = directionId?.trim(),
        enabled = enabled,
        syncVersion = syncVersion,
    )

    fun isLoggedIn(): Boolean = prefs.isLoggedIn()

    fun logout() {
        prefs.logout()
        routeRuleStore.clear()
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
        cachedNativeOutbound = null
    }

    private companion object {
        const val SESSION_REFRESH_MARGIN_MS = 30_000L
    }
}
