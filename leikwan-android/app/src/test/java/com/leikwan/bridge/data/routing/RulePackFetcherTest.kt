package com.leikwan.bridge.data.routing

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RulePackFetcherTest {
    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs
    private lateinit var manager: RulePackManager
    private lateinit var server: MockWebServer

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        val shared = ApplicationProvider.getApplicationContext<Context>()
            .getSharedPreferences("rule-pack-fetcher-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
        manager = RulePackManager(db, prefs) { 1717027300000 }
        server = MockWebServer()
        server.start()
    }

    @After
    fun tearDown() {
        server.shutdown()
        db.close()
    }

    @Test
    fun `successful fetch parses validates and activates manifest`() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(200).setBody(manifestJson(version = "2026.05.31")))
        val fetcher = RulePackFetcher(
            manifestUrl = server.url("/manifest.json").toString(),
            client = OkHttpClient(),
            manager = manager,
            clock = { 1717027300000 },
        )

        val result = fetcher.fetchAndActivate()

        assertTrue(result is RulePackFetchResult.Success)
        assertEquals("2026.05.31", manager.current()!!.categoryPackVersion)
        assertEquals("2026.05.31", manager.current()!!.countryPackVersion)
        assertEquals("github", manager.current()!!.sourceLabel)
    }

    @Test
    fun `failed fetch leaves active pack unchanged`() = runBlocking {
        manager.activate(RulePackManifest.seed().toSnapshot("seed", 1L))
        server.enqueue(MockResponse().setResponseCode(503).setBody("nope"))
        val fetcher = RulePackFetcher(
            manifestUrl = server.url("/manifest.json").toString(),
            client = OkHttpClient(),
            manager = manager,
            clock = { 1717027300000 },
        )

        val result = fetcher.fetchAndActivate()

        assertTrue(result is RulePackFetchResult.Error)
        assertEquals("seed-2026.05.31", manager.current()!!.categoryPackVersion)
        assertEquals("seed", manager.current()!!.sourceLabel)
    }

    @Test
    fun `ensureSeed activates baked seed when offline and no active pack exists`() = runBlocking {
        val fetcher = RulePackFetcher(
            manifestUrl = server.url("/manifest.json").toString(),
            client = OkHttpClient(),
            manager = manager,
            clock = { 1717027300000 },
        )

        val seeded = fetcher.ensureSeed()

        assertEquals("seed-2026.05.31", seeded.categoryPackVersion)
        assertEquals("seed", manager.current()!!.sourceLabel)
    }

    private fun manifestJson(version: String): String = """
        {
          "version": "$version",
          "geosite": [
            {
              "key": "google",
              "labelEn": "Google",
              "labelZh": "谷歌服务",
              "defaultDirect": false,
              "tags": ["geosite-google"]
            }
          ],
          "geoip": {
            "version": "$version",
            "cnTag": "geoip-cn",
            "refs": [
              {"tag": "geoip-cn", "url": "https://example.invalid/geoip-cn.srs"},
              {"tag": "geosite-cn", "url": "https://example.invalid/geosite-cn.srs"}
            ]
          }
        }
    """.trimIndent()
}
