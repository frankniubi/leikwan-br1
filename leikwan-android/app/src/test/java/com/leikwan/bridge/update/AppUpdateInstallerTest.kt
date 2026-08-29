package com.leikwan.bridge.update

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.test.core.app.ApplicationProvider
import org.junit.After
import org.junit.Assert.assertEquals
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
class AppUpdateInstallerTest {
    private lateinit var context: Context
    private lateinit var apk: File

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        apk = File(context.filesDir, "updates/install-test.apk").apply {
            parentFile?.mkdirs()
            writeBytes(byteArrayOf(1, 2, 3))
        }
    }

    @After
    fun tearDown() {
        apk.parentFile?.deleteRecursively()
    }

    @Test
    fun `creates verified content uri install intent`() {
        val manifest = manifest()
        val installer = AppUpdateInstaller(
            context = context,
            apkInspectorOverride = { AppUpdateApkMetadata("com.leikwan.bridge", 11L, "0.2.9") },
            uriProviderOverride = { _, _ -> Uri.parse("content://com.leikwan.bridge.fileprovider/updates/install-test.apk") },
        )

        val intent = installer.createInstallIntent(apk, manifest).getOrThrow()

        assertEquals(Intent.ACTION_VIEW, intent.action)
        assertEquals("application/vnd.android.package-archive", intent.type)
        assertEquals("content", intent.data?.scheme)
        assertTrue(intent.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION != 0)
    }

    @Test
    fun `refuses invalid package metadata before creating install intent`() {
        val installer = AppUpdateInstaller(
            context = context,
            apkInspectorOverride = { AppUpdateApkMetadata("com.example.other", 11L, "0.2.9") },
        )

        val failure = installer.createInstallIntent(apk, manifest()).exceptionOrNull()

        assertEquals("update_package_mismatch", failure?.message)
    }

    @Test
    fun `refuses a file whose checksum changed after download`() {
        val installer = AppUpdateInstaller(
            context = context,
            apkInspectorOverride = { AppUpdateApkMetadata("com.leikwan.bridge", 11L, "0.2.9") },
            uriProviderOverride = { _, _ -> Uri.parse("content://com.leikwan.bridge.fileprovider/updates/install-test.apk") },
        )

        val failure = installer.createInstallIntent(
            apk,
            manifest().copy(sha256 = "b".repeat(64)),
        ).exceptionOrNull()

        assertEquals("update_file_checksum_mismatch", failure?.message)
    }

    private fun manifest(): AppUpdateManifest = AppUpdateManifest(
        versionName = "0.2.9",
        versionCode = 11L,
        artifactUrl = "/artifacts/leikwan-android-client.apk",
        sha256 = sha256(byteArrayOf(1, 2, 3)),
        sizeBytes = 3L,
    )

    private fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256")
        .digest(bytes)
        .joinToString("") { "%02x".format(it) }
}
