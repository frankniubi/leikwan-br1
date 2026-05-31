package com.leikwan.bridge.data.routing

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException

sealed interface RulePackFetchResult {
    data class Success(val snapshot: RulePackSnapshot) : RulePackFetchResult
    data class Error(val message: String) : RulePackFetchResult
}

class RulePackFetcher(
    private val manifestUrl: String = DEFAULT_MANIFEST_URL,
    private val client: OkHttpClient = OkHttpClient(),
    private val manager: RulePackManager,
    private val clock: () -> Long = System::currentTimeMillis,
) {
    suspend fun fetchAndActivate(sourceLabel: String = "github"): RulePackFetchResult =
        withContext(Dispatchers.IO) {
            try {
                val request = Request.Builder().url(manifestUrl).get().build()
                val response = client.newCall(request).execute()
                response.use {
                    if (!it.isSuccessful) {
                        return@withContext RulePackFetchResult.Error("Rule update failed: HTTP ${it.code}")
                    }
                    val body = it.body?.string().orEmpty()
                    val manifest = RulePackManifest.parseOrNull(body)
                        ?: return@withContext RulePackFetchResult.Error("Rule update failed: invalid manifest")
                    val snapshot = manager.activate(
                        manifest.toSnapshot(
                            sourceLabel = sourceLabel,
                            fetchedAtMs = clock(),
                            validatedAtMs = clock(),
                        )
                    )
                    RulePackFetchResult.Success(snapshot)
                }
            } catch (e: IOException) {
                RulePackFetchResult.Error(e.message ?: "Rule update failed")
            } catch (e: IllegalArgumentException) {
                RulePackFetchResult.Error(e.message ?: "Rule update failed")
            }
        }

    suspend fun ensureSeed(): RulePackSnapshot {
        manager.current()?.let { return it }
        return manager.activate(RulePackManifest.seed().toSnapshot(sourceLabel = "seed", fetchedAtMs = clock()))
    }

    companion object {
        const val DEFAULT_MANIFEST_URL =
            "https://raw.githubusercontent.com/leikwan/rule-packs/main/android-routing-manifest.json"
    }
}
