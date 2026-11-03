package com.leikwan.bridge.proxy

import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.net.AppDns
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.net.Proxy
import java.util.concurrent.TimeUnit

object SubscriptionFetcher {
    // Direct, not via an HTTP proxy another app advertised: that proxy is usually a 127.0.0.1
    // port of a VPN app, which breaks the fetch once the app stops and hands the user's node
    // list to it. Sockets are deliberately not bound to the physical network (unlike panel
    // traffic): the subscription carries no location decision, and a user who needs their
    // own VPN to reach the provider keeps normal device routing.
    internal val client = OkHttpClient.Builder()
        .proxy(Proxy.NO_PROXY)
        // Resolve subscription hostnames over DoH to avoid GFW UDP/53 poisoning.
        .dns(AppDns.shared())
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
