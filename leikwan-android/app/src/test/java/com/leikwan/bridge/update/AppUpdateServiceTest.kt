package com.leikwan.bridge.update

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okio.Buffer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File
import java.security.MessageDigest

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AppUpdateServiceTest {
    private lateinit var server: MockWebServer
    private lateinit var context: Context

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        server = MockWebServer().apply { start() }
        File(context.filesDir, "updates").deleteRecursively()
    }

    @After
    fun tearDown() {
        File(context.filesDir, "updates").deleteRecursively()
        server.shutdown()
    }

    @Test
    fun `checks the public manifest and reports a newer Android release`() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(200).setBody(manifestJson(versionCode = 11)))
        val service = service(currentVersionCode = 10L)

        val result = service.checkForUpdate()

        assertTrue(result is AppUpdateCheckResult.Available)
        assertEquals(11L, (result as AppUpdateCheckResult.Available).manifest.versionCode)
        assertEquals("/artifacts/versions.json", server.takeRequest().path)
    }

    @Test
    fun `reports up to date when remote version is not newer`() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(200).setBody(manifestJson(versionCode = 10)))

        val result = service(currentVersionCode = 10L).checkForUpdate()

        assertTrue(result is AppUpdateCheckResult.UpToDate)
    }

    @Test
    fun `downloads atomically and verifies size and sha256`() = runBlocking {
        val bytes = "verified apk bytes".toByteArray()
        val manifest = manifest(versionCode = 11, bytes = bytes)
        server.enqueue(MockResponse().setResponseCode(200).setBody(Buffer().write(bytes)))

        val result = service(currentVersionCode = 10L).download(manifest)

        assertTrue(result is AppUpdateDownloadResult.Success)
        val file = (result as AppUpdateDownloadResult.Success).file
        assertEquals(bytes.toList(), file.readBytes().toList())
        assertFalse(File(requireNotNull(file.parentFile), ".update-11.apk.part").exists())
    }

    @Test
    fun `deletes partial file when download hash or size is invalid`() = runBlocking {
        val bytes = "tampered apk bytes".toByteArray()
        val manifest = manifest(versionCode = 11, bytes = bytes).copy(
            sha256 = "b".repeat(64),
        )
        server.enqueue(MockResponse().setResponseCode(200).setBody(Buffer().write(bytes)))

        val result = service(currentVersionCode = 10L).download(manifest)

        assertTrue(result is AppUpdateDownloadResult.Failure)
        val updatesDir = File(context.filesDir, "updates")
        assertTrue(!updatesDir.exists() || updatesDir.listFiles().orEmpty().isEmpty())
    }

    @Test
    fun `rejects package identity and version mismatches before install`() {
        val manifest = manifest(versionCode = 11, bytes = byteArrayOf(1, 2, 3))

        assertEquals(
            null,
            AppUpdateArtifactValidator.error(
                manifest = manifest,
                packageName = "com.leikwan.bridge",
                versionCode = 11L,
                versionName = "0.2.9",
            ),
        )
        assertEquals(
            "update_package_mismatch",
            AppUpdateArtifactValidator.error(manifest, "com.example.other", 11L, "0.2.9"),
        )
        assertEquals(
            "update_version_code_mismatch",
            AppUpdateArtifactValidator.error(manifest, "com.leikwan.bridge", 12L, "0.2.9"),
        )
        assertEquals(
            "update_version_name_mismatch",
            AppUpdateArtifactValidator.error(manifest, "com.leikwan.bridge", 11L, "0.3.0"),
        )
    }

    private fun service(currentVersionCode: Long): AppUpdateService = AppUpdateService(
        context = context,
        panelUrl = server.url("/").toString(),
        client = OkHttpClient(),
        currentVersionCode = currentVersionCode,
        allowInsecureHttp = true,
    )

    private fun manifest(versionCode: Long, bytes: ByteArray): AppUpdateManifest = AppUpdateManifest(
        versionName = "0.2.9",
        versionCode = versionCode,
        artifactUrl = "/artifacts/leikwan-android-client.apk",
        sha256 = sha256(bytes),
        sizeBytes = bytes.size.toLong(),
    )

    private fun manifestJson(versionCode: Long): String {
        val bytes = "remote apk".toByteArray()
        return """
            {
              "android_client_apk": {
                "version": "0.2.9",
                "version_code": $versionCode,
                "artifact_url": "/artifacts/leikwan-android-client.apk",
                "sha256": "${sha256(bytes)}",
                "size_bytes": ${bytes.size}
              }
            }
        """.trimIndent()
    }

    private fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256")
        .digest(bytes)
        .joinToString("") { "%02x".format(it) }
}
