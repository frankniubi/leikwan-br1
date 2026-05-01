package com.leikwan.bridge.proxy

import com.leikwan.bridge.data.ProxyNode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

object SubscriptionFetcher {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .followRedirects(true)
        .build()

    suspend fun fetch(url: String): List<ProxyNode> = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", "LeiKwanBridge/0.1.0")
            .build()
        val response = client.newCall(request).execute()
        val body = response.body?.string() ?: throw Exception("Empty response from subscription")
        if (!response.isSuccessful) throw Exception("Subscription fetch failed: ${response.code}")
        ProxyUriParser.parseMultiple(body)
    }
}
