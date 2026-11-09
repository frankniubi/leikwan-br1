package com.leikwan.bridge.net

import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.*
import org.junit.Test

class PanelEndpointFailoverInterceptorTest {
    @Test fun getGatewayFailureSwitchesButBusinessAndPostResponsesDoNotReplay() {
        val first = MockWebServer()
        val second = MockWebServer()
        first.start(); second.start()
        try {
            val client = OkHttpClient.Builder().addInterceptor(
                PanelEndpointFailoverInterceptor(listOf(first.url("/"), second.url("/")))
            ).build()
            first.enqueue(MockResponse().setResponseCode(503))
            second.enqueue(MockResponse().setBody("standby"))
            client.newCall(Request.Builder().url(first.url("/api/client/configs/pull")).build()).execute().use {
                assertEquals("standby", it.body!!.string())
            }
            // Sticky standby still receives a definitive refusal only once.
            second.enqueue(MockResponse().setResponseCode(409))
            client.newCall(Request.Builder().url(first.url("/api/client/ultra/lease")).post("{}".toRequestBody()).build()).execute().use {
                assertEquals(409, it.code)
            }
            second.enqueue(MockResponse().setResponseCode(503))
            client.newCall(Request.Builder().url(first.url("/api/client/ultra/lease")).post("{}".toRequestBody()).build()).execute().use {
                assertEquals(503, it.code)
            }
            assertEquals(1, first.requestCount)
            assertEquals(3, second.requestCount)
        } finally { first.shutdown(); second.shutdown() }
    }
    @Test fun arbitraryOriginNeverReceivesTheAccountOnAFallback() {
        val server = MockWebServer()
        server.start()
        try {
            server.enqueue(MockResponse().setResponseCode(503))
            val client = OkHttpClient.Builder().addInterceptor(PanelEndpointFailoverInterceptor()).build()
            client.newCall(Request.Builder().url(server.url("/")).build()).execute().close()
            assertEquals(1, server.requestCount)
        } finally { server.shutdown() }
    }
}
