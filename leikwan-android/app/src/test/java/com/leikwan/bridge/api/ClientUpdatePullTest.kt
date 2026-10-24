package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.update.ClientUpdatePolicy
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Every configs/pull parse applies `client_update`; nothing else re-checks mid-session. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ClientUpdatePullTest {
    private lateinit var server: MockWebServer
    private lateinit var repository: PanelRepository
    private var requiredCallbacks = 0

    @Before
    fun setUp() {
        ClientUpdatePolicy.resetForTest()
        server = MockWebServer().apply { start() }
        val context = ApplicationProvider.getApplicationContext<Context>()
        val prefs = Prefs(
            context.getSharedPreferences("client-update-pull-test", Context.MODE_PRIVATE).also {
                it.edit().clear().commit()
            },
        ).apply {
            panelUrl = server.url("/").toString()
            clientId = 17L
            agentToken = "agent-token"
            enrolledDeviceId = "android-client-17-test"
            deviceSessionToken = "device-session"
            deviceSessionExpiresAtMs = Long.MAX_VALUE
        }
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
            deviceIdProvider = { "android-client-17-test" },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
            onClientUpdateRequired = { requiredCallbacks++ },
        )
    }

    @After
    fun tearDown() {
        server.shutdown()
        ClientUpdatePolicy.resetForTest()
    }

    @Test
    fun `a pull below the minimum requires an update and asks for the manifest`() = runBlocking {
        enqueuePull(""","client_update":{"minimum_version":"999.0.0"}""")

        repository.pullCatalog()

        assertEquals("999.0.0", ClientUpdatePolicy.requiredMinimumVersion.value)
        assertEquals(1, requiredCallbacks)
    }

    @Test
    fun `a later pull without client_update keeps the gate`() = runBlocking {
        enqueuePull(""","client_update":{"minimum_version":"999.0.0"}""")
        repository.pullCatalog()
        enqueuePull("")
        repository.pullCatalog()

        assertEquals("999.0.0", ClientUpdatePolicy.requiredMinimumVersion.value)
        assertEquals(2, requiredCallbacks)
    }

    @Test
    fun `a met or absent minimum leaves the app ungated`() = runBlocking {
        enqueuePull(""","client_update":{"minimum_version":"0.0.1"}""")
        repository.pullCatalog()
        enqueuePull("")
        repository.pullCatalog()

        assertNull(ClientUpdatePolicy.requiredMinimumVersion.value)
        assertEquals(0, requiredCallbacks)
    }

    private fun enqueuePull(extraFields: String) {
        server.enqueue(json("""{"ok":true}"""))
        server.enqueue(json("""{"ok":true,"client":{"id":17},"forwards":[]$extraFields}"""))
    }

    private fun json(body: String) = MockResponse()
        .setResponseCode(200)
        .setHeader("Content-Type", "application/json")
        .setBody(body)
}
