package com.leikwan.bridge.api

import android.content.Context
import android.util.Log
import com.google.gson.Gson
import com.google.gson.JsonElement
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
import com.leikwan.bridge.net.AppDns
import com.leikwan.bridge.net.MirrorNoSni
import okhttp3.OkHttpClient
import kotlinx.coroutines.CancellationException
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.net.URI
import java.util.concurrent.TimeUnit

class PanelRepository(
    context: Context,
    prefsOverride: Prefs? = null,
    apiOverride: PanelApi? = null,
    private val routeRuleStore: ClientRouteRuleStore = ClientRouteRuleStore.from(context),
) {
    private val prefs = prefsOverride ?: Prefs.get(context)
    private val gson = Gson()

    private val hasApiOverride = apiOverride != null
    private var api: PanelApi = apiOverride ?: buildApi(prefs.panelUrl)
    private var cachedForwardConfigs = mutableMapOf<Long, JsonElement>()
    private var cachedForwardContracts = mutableMapOf<Long, ForwardContract>()
    private var cachedEffectiveForwards = mutableMapOf<Long, AndroidEffectiveForward>()

    private fun buildApi(baseUrl: String): PanelApi {
        val url = baseUrl.trimEnd('/') + "/"
        val host = runCatching { URI(url).host }.getOrNull()
        val clientBuilder = OkHttpClient.Builder()
            // Resolve the panel hostname over DoH so GFW UDP/53 poisoning can't redirect
            // login to a bogus IP. Panel control-plane calls intentionally do not fall
            // back to system DNS because poisoned networks return bogus A/AAAA records.
            .dns(AppDns.strict())
            .callTimeout(20, TimeUnit.SECONDS)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)

        if (MirrorNoSni.shouldRewrite(host)) {
            MirrorNoSni.applyTo(clientBuilder)
        }

        val client = clientBuilder.build()
        return Retrofit.Builder()
            .baseUrl(url)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PanelApi::class.java)
    }

    private fun authHeader(): String = "Bearer ${prefs.token}"

    private fun rebuildApiUnlessOverridden(baseUrl: String) {
        if (!hasApiOverride) {
            api = buildApi(baseUrl)
        }
    }

    suspend fun login(panelUrl: String, email: String, password: String): LoginResponse {
        prefs.panelUrl = panelUrl
        rebuildApiUnlessOverridden(panelUrl)
        val resp = executeLogin(LoginRequest(email, password))
        if (needsClientSelection(resp)) {
            return resp
        }
        val sessionToken = resp.token?.takeIf { it.isNotBlank() }
            ?: throw Exception("Login response missing session token")
        val selectedClientId = resp.clientId ?: resp.clients.singleOrNull()?.id
            ?: throw Exception("Please select a client")
        val selection = executeSelectClient(ClientSelectionRequest(sessionToken, selectedClientId))
        persistSelectedClient(selection, email, password, resp.clients, resp.username)
        return resp
    }

    suspend fun selectClient(
        panelUrl: String,
        email: String,
        password: String,
        sessionToken: String,
        clientId: Long,
        clients: List<ClientInfo>,
        username: String? = null,
    ): ClientSelectionResponse {
        prefs.panelUrl = panelUrl
        rebuildApiUnlessOverridden(panelUrl)
        val token = sessionToken.takeIf { it.isNotBlank() }
            ?: executeLogin(LoginRequest(email, password)).token?.takeIf { it.isNotBlank() }
            ?: throw Exception("Login response missing session token")
        val selection = executeSelectClient(ClientSelectionRequest(token, clientId))
        persistSelectedClient(selection, email, password, clients, username)
        return selection
    }

    suspend fun switchClient(clientId: Long): LoginResponse {
        val email = prefs.email ?: throw Exception("Not logged in")
        val password = prefs.password ?: throw Exception("Re-login required")
        val resp = executeLogin(LoginRequest(email, password), fallback = "Switch client failed")
        val sessionToken = resp.token?.takeIf { it.isNotBlank() }
            ?: throw Exception("Login response missing session token")
        val selection = executeSelectClient(ClientSelectionRequest(sessionToken, clientId), fallback = "Switch client failed")
        persistSelectedClient(selection, email, password, resp.clients, resp.username)
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
        return resp
    }

    private fun needsClientSelection(resp: LoginResponse): Boolean =
        resp.clients.size > 1 && resp.clientId == null

    private fun persistSelectedClient(
        selection: ClientSelectionResponse,
        email: String,
        password: String,
        clients: List<ClientInfo>,
        username: String?,
    ) {
        val agentToken = selection.agentToken.takeIf { it.isNotBlank() }
            ?: throw Exception("Client selection missing agent token")
        prefs.token = agentToken
        prefs.username = username
        prefs.email = email
        prefs.password = password
        prefs.clientId = selection.clientId
        prefs.clientName = selection.clientName
        prefs.clientsJson = gson.toJson(clients)
        prefs.selectedForwardId = 0L
        routeRuleStore.clear()
    }

    private suspend fun executeLogin(request: LoginRequest, fallback: String = "Login failed"): LoginResponse {
        val response = api.login(request)
        if (!response.isSuccessful) {
            val message = PanelErrorParser.messageFromHttp(
                response.code(),
                response.message(),
                response.errorBody()?.string(),
            )
            Log.w(TAG, "panel login http failure code=${response.code()} message=$message")
            throw Exception(message)
        }
        val body = response.body() ?: throw Exception("Empty login response")
        if (!body.ok) {
            val message = body.error?.takeIf { it.isNotBlank() } ?: fallback
            Log.w(TAG, "panel login api failure message=$message")
            throw Exception(message)
        }
        Log.i(TAG, "panel login ok clients=${body.clients.size} clientId=${body.clientId}")
        return body
    }

    private suspend fun executeSelectClient(
        request: ClientSelectionRequest,
        fallback: String = "Client selection failed",
    ): ClientSelectionResponse {
        val response = api.selectClient(request)
        if (!response.isSuccessful) {
            val message = PanelErrorParser.messageFromHttp(
                response.code(),
                response.message(),
                response.errorBody()?.string(),
            )
            Log.w(TAG, "client select http failure code=${response.code()} message=$message")
            throw Exception(message)
        }
        val body = response.body() ?: throw Exception("Empty client selection response")
        if (!body.ok) {
            val message = body.error?.takeIf { it.isNotBlank() } ?: fallback
            Log.w(TAG, "client select api failure message=$message")
            throw Exception(message)
        }
        Log.i(TAG, "client select ok clientId=${body.clientId}")
        return body
    }

    suspend fun pullConfigs(): List<ForwardInfo> {
        return pullCatalog().visibleForwards.map { item ->
            ForwardInfo(
                forwardId = item.forwardId,
                serviceName = item.serviceName,
                target = item.target,
                listenPort = item.listenPort,
                mode = "leigod",
                transport = item.transport,
                serviceStatus = item.serviceStatus,
                endpointMode = item.endpointMode,
                speedLimitMbps = item.speedLimitMbps,
            )
        }
    }

    suspend fun pullCatalog(selectedForwardId: Long = prefs.selectedForwardId): ForwardCatalog {
        try {
            api.heartbeat(authHeader(), HeartbeatRequest())
        } catch (e: CancellationException) {
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
        val contracts = mutableListOf<ForwardContract>()
        for (raw in resp.forwards) {
            try {
                val contract = ForwardContractParser.parse(raw) ?: continue
                contracts += contract
                cachedForwardConfigs[contract.forwardId] = raw
                cachedForwardContracts[contract.forwardId] = contract
                AndroidEffectiveForwardBuilder.build(contract)?.let { effective ->
                    cachedEffectiveForwards[effective.forwardId] = effective
                }
            } catch (_: Exception) {
                // skip unparseable forwards
            }
        }
        return ForwardFilter.buildCatalog(
            contracts = contracts,
            selectedForwardId = selectedForwardId.takeIf { it > 0L },
        )
    }

    fun getCachedConfig(forwardId: Long): JsonElement? = cachedForwardConfigs[forwardId]

    fun getCachedContract(forwardId: Long): ForwardContract? = cachedForwardContracts[forwardId]

    fun getCachedEffectiveForward(forwardId: Long): AndroidEffectiveForward? = cachedEffectiveForwards[forwardId]

    suspend fun heartbeat(): Boolean {
        return try {
            api.heartbeat(authHeader()).ok
        } catch (_: Exception) {
            false
        }
    }

    suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule {
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
    }

    private companion object {
        const val TAG = "LKPanelRepo"
    }
}
