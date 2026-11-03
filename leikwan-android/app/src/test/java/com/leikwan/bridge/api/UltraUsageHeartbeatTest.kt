package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonParser
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.runtime.UltraUsageLedger
import com.leikwan.bridge.security.DeviceSecurityResult
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File
import java.time.Instant

/** Android reports per-forward Ultra usage on the heartbeat, like the desktop client. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class UltraUsageHeartbeatTest {
    @get:Rule val temp = TemporaryFolder()

    private lateinit var server: MockWebServer
    private lateinit var repository: PanelRepository
    private lateinit var statusDir: File
    private val now = Instant.parse("2030-01-01T00:00:00Z")
    private val generation = "c".repeat(64)

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
        val context = ApplicationProvider.getApplicationContext<Context>()
        val prefs = Prefs(
            context.getSharedPreferences("ultra-usage-heartbeat-test", Context.MODE_PRIVATE).also {
                it.edit().clear().commit()
            },
        ).apply {
            panelUrl = server.url("/").toString()
            clientId = 3L
            agentToken = "agent-token"
            enrolledDeviceId = "android-client-3-test"
            deviceSessionToken = "device-session"
            deviceSessionExpiresAtMs = now.plusSeconds(3_600).toEpochMilli()
        }
        val filesDir = temp.newFolder("files")
        statusDir = File(filesDir, "ultra").apply { mkdirs() }
        repository = PanelRepository(
            context = context,
            prefsOverride = prefs,
            routeRuleStore = ClientRouteRuleStore.from(context),
            httpClientOverride = OkHttpClient.Builder().addInterceptor { chain ->
                val request = chain.request()
                val localUrl = request.url.newBuilder()
                    .scheme("http")
                    .host(server.hostName)
                    .port(server.port)
                    .build()
                chain.proceed(request.newBuilder().url(localUrl).build())
            }.build(),
            deviceIdProvider = { "android-client-3-test" },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
            nowMs = { now.toEpochMilli() },
            ultraLeaseKeyProvider = { null },
            ultraUsageLedgerProvider = { UltraUsageLedger.forFilesDir(filesDir) },
        )
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    private fun writeStatus(bytesIn: Long, bytesOut: Long) {
        File(statusDir, "forward-170-cccccccccccc.status.json").writeText(
            """{"forward_id":170,"policy_generation":"$generation","bytes_in":$bytesIn,"bytes_out":$bytesOut}""",
        )
    }

    private fun usageOf(body: String) = JsonParser.parseString(body).asJsonObject.getAsJsonArray("ultra_usage")

    @Test
    fun `heartbeat carries usage rows and retries them until acknowledged`() = runBlocking {
        writeStatus(4_096, 1_024)
        server.enqueue(MockResponse().setBody("""{"ok":true,"ultra_usage_acknowledged":false}"""))
        server.enqueue(MockResponse().setBody("""{"ok":true,"ultra_usage_acknowledged":true}"""))
        server.enqueue(MockResponse().setBody("""{"ok":true,"ultra_usage_acknowledged":true}"""))

        assertTrue(repository.heartbeat())
        val first = server.takeRequest()
        assertEquals("/api/client/heartbeat", first.path)
        assertEquals("android-client-3-test", first.getHeader("X-Device-Id"))
        val rows = usageOf(first.body.readUtf8())
        assertEquals(1, rows.size())
        val row = rows[0].asJsonObject
        assertEquals(170L, row.get("forward_id").asLong)
        assertEquals(generation, row.get("policy_generation").asString)
        assertEquals(1L, row.get("sequence").asLong)
        assertEquals(4_096L, row.get("bytes_in").asLong)
        assertEquals(1_024L, row.get("bytes_out").asLong)

        // Not acknowledged: the same row (same sequence) goes again, even though counters moved.
        writeStatus(8_192, 1_024)
        assertTrue(repository.heartbeat())
        assertEquals(rows, usageOf(server.takeRequest().body.readUtf8()))

        // Acknowledged: the next heartbeat reports only the new delta with the next sequence.
        assertTrue(repository.heartbeat())
        val third = usageOf(server.takeRequest().body.readUtf8())[0].asJsonObject
        assertEquals(2L, third.get("sequence").asLong)
        assertEquals(4_096L, third.get("bytes_in").asLong)
        assertEquals(0L, third.get("bytes_out").asLong)
    }

    @Test
    fun `no usage means no ultra_usage field and a failed heartbeat keeps the batch`() = runBlocking {
        server.enqueue(MockResponse().setBody("""{"ok":true}"""))
        assertTrue(repository.heartbeat())
        val empty = JsonParser.parseString(server.takeRequest().body.readUtf8()).asJsonObject
        assertFalse(empty.has("ultra_usage"))
        assertFalse(empty.has("usage_bytes_in"))

        writeStatus(100, 0)
        server.enqueue(MockResponse().setResponseCode(500).setBody("""{"ok":false}"""))
        server.enqueue(MockResponse().setBody("""{"ok":true}"""))
        server.enqueue(MockResponse().setBody("""{"ok":true}"""))
        assertFalse(repository.heartbeat())
        val failed = usageOf(server.takeRequest().body.readUtf8())
        // An older panel without ultra_usage_acknowledged acknowledges through ok.
        assertTrue(repository.heartbeat())
        assertEquals(failed, usageOf(server.takeRequest().body.readUtf8()))
        assertTrue(repository.heartbeat())
        assertFalse(JsonParser.parseString(server.takeRequest().body.readUtf8()).asJsonObject.has("ultra_usage"))
    }
}
