package com.leikwan.bridge.api

import android.content.Context
import com.google.gson.Gson
import com.google.gson.JsonElement
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.runtime.AndroidEffectiveForward
import com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilder
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardContractParser
import com.leikwan.bridge.runtime.ForwardFilter
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class PanelRepository(context: Context) {
    private val prefs = Prefs.get(context)
    private val gson = Gson()

    private var api: PanelApi = buildApi(prefs.panelUrl)
    private var cachedForwardConfigs = mutableMapOf<Long, JsonElement>()
    private var cachedForwardContracts = mutableMapOf<Long, ForwardContract>()
    private var cachedEffectiveForwards = mutableMapOf<Long, AndroidEffectiveForward>()

    private fun buildApi(baseUrl: String): PanelApi {
        val url = baseUrl.trimEnd('/') + "/"
        val client = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .build()
        return Retrofit.Builder()
            .baseUrl(url)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PanelApi::class.java)
    }

    private fun authHeader(): String = "Bearer ${prefs.token}"

    suspend fun login(panelUrl: String, email: String, password: String): LoginResponse {
        prefs.panelUrl = panelUrl
        api = buildApi(panelUrl)
        val resp = api.login(LoginRequest(email, password))
        if (!resp.ok) throw Exception("Login failed")
        prefs.token = resp.token
        prefs.username = resp.username
        prefs.email = email
        prefs.password = password
        prefs.clientId = resp.clientId
        prefs.clientName = resp.clientName
        prefs.clientsJson = gson.toJson(resp.clients)
        prefs.selectedForwardId = 0L
        return resp
    }

    suspend fun switchClient(clientId: Long): LoginResponse {
        val email = prefs.email ?: throw Exception("Not logged in")
        val password = prefs.password ?: throw Exception("Re-login required")
        val resp = api.login(LoginRequest(email, password, clientId))
        if (!resp.ok) throw Exception("Switch client failed")
        prefs.token = resp.token
        prefs.clientId = resp.clientId
        prefs.clientName = resp.clientName
        prefs.selectedForwardId = 0L
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
        return resp
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
        val resp = api.pullConfigs(authHeader())
        if (!resp.ok) throw Exception("Pull configs failed")
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

    fun isLoggedIn(): Boolean = prefs.isLoggedIn()

    fun logout() {
        prefs.logout()
        cachedForwardConfigs.clear()
        cachedForwardContracts.clear()
        cachedEffectiveForwards.clear()
    }
}
