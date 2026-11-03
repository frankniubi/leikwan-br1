package com.leikwan.bridge.update

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AppUpdateManifestTest {
    private val sha = "7e153621b784d87c9a5a619a5cb7ca8f48f3ba70fa7d344d8e9229cf454eb369"

    private fun manifest(
        artifactUrl: String = "/artifacts/leikwan-android-client.apk",
        sha256: String = sha,
        versionCode: Int = 44,
        size: Long = 74_469_663L,
    ) = """
        {
          "android_client_apk": {
            "artifact_url": "$artifactUrl",
            "path": "artifacts/android/leikwan-android-client.apk",
            "sha256": "$sha256",
            "size_bytes": $size,
            "version": "0.2.42",
            "version_code": $versionCode
          },
          "client_agent": {"version": "2026.10.12.1"}
        }
    """.trimIndent()

    @Test
    fun `the published Android entry resolves on the signed-in panel origin`() {
        val info = AppUpdateManifest.parse(manifest(), "https://api-leikwanbridge.nbwly.com")!!

        assertEquals("0.2.42", info.versionName)
        assertEquals(44, info.versionCode)
        assertEquals(sha, info.sha256)
        assertEquals(74_469_663L, info.sizeBytes)
        assertEquals("https://api-leikwanbridge.nbwly.com/artifacts/leikwan-android-client.apk", info.downloadUrl)
    }

    @Test
    fun `the manifest can never send the download to another host`() {
        val panel = "https://v3.leikwanhost.com"
        assertNull(AppUpdateManifest.parse(manifest(artifactUrl = "https://evil.example/a.apk"), panel))
        assertNull(AppUpdateManifest.parse(manifest(artifactUrl = "//evil.example/a.apk"), panel))
        assertNull(AppUpdateManifest.parse(manifest(artifactUrl = "artifacts/a.apk"), panel))
        assertNull(AppUpdateManifest.parse(manifest(), "http://v3.leikwanhost.com"))
    }

    @Test
    fun `an entry without a usable checksum or size is ignored`() {
        val panel = "https://v3.leikwanhost.com"
        assertNull(AppUpdateManifest.parse(manifest(sha256 = "abc"), panel))
        assertNull(AppUpdateManifest.parse(manifest(size = 0L), panel))
        assertNull(AppUpdateManifest.parse(manifest(versionCode = 0), panel))
        assertNull(AppUpdateManifest.parse("""{"client_agent":{}}""", panel))
        assertNull(AppUpdateManifest.parse("not json", panel))
    }

    @Test
    fun `only a higher version code is an update`() {
        val info = AppUpdateManifest.parse(manifest(versionCode = 44), "https://v3.leikwanhost.com")!!
        assertTrue(AppUpdateManifest.isNewer(info, installedVersionCode = 43))
        assertFalse(AppUpdateManifest.isNewer(info, installedVersionCode = 44))
        assertFalse(AppUpdateManifest.isNewer(info, installedVersionCode = 45))
    }
}
