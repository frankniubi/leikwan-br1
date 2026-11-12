package com.leikwan.bridge.data.routing

import com.leikwan.bridge.routing.SurgeListConverter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.net.Proxy
import java.util.concurrent.TimeUnit

/**
 * Downloads a Surge/Clash rule list by URL. Same network policy as [RulePackFetcher] and
 * subscriptions: ignore foreign HTTP proxies, keep normal device routing (while connected,
 * the fetch follows the tunnel's own rules). The body is capped while reading.
 */
class RuleListFetcher(
    private val client: OkHttpClient = OkHttpClient.Builder()
        .proxy(Proxy.NO_PROXY)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build(),
) {
    suspend fun fetch(url: String): String = withContext(Dispatchers.IO) {
        val trimmed = url.trim()
        if (!trimmed.startsWith("https://") && !trimmed.startsWith("http://")) {
            throw IOException("规则链接必须以 http:// 或 https:// 开头")
        }
        val request = Request.Builder().url(trimmed).get().build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("下载规则失败：HTTP ${response.code}")
            val body = response.body ?: throw IOException("下载规则失败：空响应")
            val declared = body.contentLength()
            if (declared > SurgeListConverter.MAX_BYTES) throw IOException("规则列表超过 4 MB")
            val source = body.source()
            // Request one byte past the cap: more than MAX_BYTES buffered means too large.
            source.request(SurgeListConverter.MAX_BYTES.toLong() + 1)
            if (source.buffer.size > SurgeListConverter.MAX_BYTES) throw IOException("规则列表超过 4 MB")
            source.buffer.readUtf8()
        }
    }
}
