package com.leikwan.bridge.update

import android.app.Application
import android.content.Context
import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AppUpdateViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var apk: File

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        app = ApplicationProvider.getApplicationContext()
        apk = File(app.filesDir, "updates/vm-test.apk").apply {
            parentFile?.mkdirs()
            writeBytes(byteArrayOf(1, 2, 3))
        }
    }

    @After
    fun tearDown() {
        apk.parentFile?.deleteRecursively()
        Dispatchers.resetMain()
    }

    @Test
    fun `manual check exposes available release and download prepares installation`() = runTest(dispatcher) {
        val source = FakeSource(AppUpdateCheckResult.Available(manifest()))
        val installer = FakeInstaller()
        val vm = AppUpdateViewModel(app, source, installer)

        vm.checkForUpdates(autoDownload = false)
        advanceUntilIdle()
        assertTrue(vm.state.value.status is AppUpdateStatus.Available)

        vm.downloadAndPrepare()
        advanceUntilIdle()

        val ready = vm.state.value.status as AppUpdateStatus.ReadyToInstall
        assertEquals(apk, ready.file)
        assertEquals(Intent.ACTION_VIEW, vm.installIntent().getOrThrow().action)
    }

    @Test
    fun `automatic check downloads and prepares the release`() = runTest(dispatcher) {
        val vm = AppUpdateViewModel(
            app,
            FakeSource(AppUpdateCheckResult.Available(manifest())),
            FakeInstaller(),
        )

        vm.checkForUpdates(autoDownload = true)
        advanceUntilIdle()

        assertTrue(vm.state.value.status is AppUpdateStatus.ReadyToInstall)
    }

    @Test
    fun `check failure is surfaced without a prepared file`() = runTest(dispatcher) {
        val vm = AppUpdateViewModel(
            app,
            FakeSource(AppUpdateCheckResult.Failure("offline")),
            FakeInstaller(),
        )

        vm.checkForUpdates(autoDownload = true)
        advanceUntilIdle()

        assertEquals(AppUpdateStatus.Error("offline"), vm.state.value.status)
    }

    private fun manifest(): AppUpdateManifest = AppUpdateManifest(
        versionName = "0.2.9",
        versionCode = 11L,
        artifactUrl = "/artifacts/leikwan-android-client.apk",
        sha256 = "a".repeat(64),
        sizeBytes = 3L,
    )

    private class FakeSource(
        private val checkResult: AppUpdateCheckResult,
    ) : AppUpdateSource {
        override suspend fun checkForUpdate(): AppUpdateCheckResult = checkResult

        override suspend fun download(
            manifest: AppUpdateManifest,
            onProgress: (Long, Long) -> Unit,
        ): AppUpdateDownloadResult {
            onProgress(3L, 3L)
            return AppUpdateDownloadResult.Success(
                File(ApplicationProvider.getApplicationContext<Context>().filesDir, "updates/vm-test.apk"),
            )
        }
    }

    private class FakeInstaller : AppUpdateInstallerPort {
        override fun createInstallIntent(file: File, manifest: AppUpdateManifest): Result<Intent> =
            Result.success(Intent(Intent.ACTION_VIEW))

        override fun canRequestPackageInstalls(): Boolean = true

        override fun unknownSourcesSettingsIntent(): Intent = Intent("settings")
    }
}
