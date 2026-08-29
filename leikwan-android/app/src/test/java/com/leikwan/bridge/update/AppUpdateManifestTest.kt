package com.leikwan.bridge.update

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AppUpdateManifestTest {
    @Test
    fun `parses android artifact metadata from versions manifest`() {
        val manifest = AppUpdateManifest.parse(
            """
            {
              "android_client_apk": {
                "version": "0.2.9",
                "version_code": 11,
                "artifact_url": "/artifacts/leikwan-android-client.apk",
                "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                "size_bytes": 12345
              }
            }
            """.trimIndent(),
        )

        requireNotNull(manifest)
        assertEquals("0.2.9", manifest.versionName)
        assertEquals(11L, manifest.versionCode)
        assertEquals("/artifacts/leikwan-android-client.apk", manifest.artifactUrl)
        assertEquals(12345L, manifest.sizeBytes)
    }

    @Test
    fun `rejects incomplete or unsafe update metadata`() {
        assertNull(
            AppUpdateManifest.parse(
                """{"android_client_apk":{"version":"0.2.9","version_code":11,"artifact_url":"/artifacts/app.apk"}}""",
            ),
        )
        assertNull(
            AppUpdateManifest.parse(
                """{"android_client_apk":{"version":"0.2.9","version_code":11,"artifact_url":"https://evil.example/app.apk","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size_bytes":10}}""",
            ),
        )
    }

    @Test
    fun `only newer version code is an update`() {
        assertTrue(AppUpdateVersionPolicy.isUpdateAvailable(10L, 11L))
        assertFalse(AppUpdateVersionPolicy.isUpdateAvailable(10L, 10L))
        assertFalse(AppUpdateVersionPolicy.isUpdateAvailable(10L, 9L))
    }
}
