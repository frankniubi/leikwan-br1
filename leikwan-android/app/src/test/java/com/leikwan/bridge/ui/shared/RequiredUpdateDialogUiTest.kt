package com.leikwan.bridge.ui.shared

import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.leikwan.bridge.ui.theme.LeikwanTheme
import com.leikwan.bridge.update.AppUpdateInfo
import com.leikwan.bridge.update.AppUpdateState
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RequiredUpdateDialogUiTest {
    @get:Rule
    val composeRule = createComposeRule()

    private val info = AppUpdateInfo(
        versionName = "0.2.45",
        versionCode = 47,
        sha256 = "a".repeat(64),
        sizeBytes = 1024,
        downloadUrl = "https://panel.example/artifacts/leikwan.apk",
    )
    private var updates = 0
    private var checks = 0

    private fun render(state: AppUpdateState) {
        composeRule.setContent {
            LeikwanTheme {
                RequiredUpdateDialog(
                    minimumVersion = "0.2.45",
                    state = state,
                    onUpdate = { updates++ },
                    onCheck = { checks++ },
                    installedVersion = "0.2.44",
                )
            }
        }
    }

    private fun clickableCount() = composeRule.onAllNodes(hasClickAction()).fetchSemanticsNodes().size

    @Test
    fun theRequiredDialogOffersOnlyTheUpdateAction() {
        render(AppUpdateState.Available(info))

        composeRule.onNodeWithText("Update required").assertExists()
        composeRule.onNodeWithText("A required update is available. Update to keep using LeiKwan Bridge.").assertExists()
        composeRule.onNodeWithText("Required: 0.2.45 or later. Installed: 0.2.44.").assertExists()
        assertEquals(1, clickableCount())
        listOf("Cancel", "Later", "Close", "Dismiss", "Not now").forEach {
            composeRule.onNodeWithText(it).assertDoesNotExist()
        }

        composeRule.onNodeWithText("Update").assertIsEnabled().performClick()
        assertEquals(1, updates)
        assertEquals(0, checks)
    }

    @Test
    fun downloadingShowsProgressAndNoAction() {
        render(AppUpdateState.Downloading(info, 0.4f))

        composeRule.onNodeWithText("Downloading 40%").assertExists()
        composeRule.onNodeWithText("Update").assertIsNotEnabled()
    }

    @Test
    fun aFailedDownloadRetriesTheDownload() {
        render(AppUpdateState.Failed("update_download_http_503", info))

        composeRule.onNodeWithText("Update failed. Check your connection and try again.").assertExists()
        composeRule.onNodeWithText("Retry").performClick()
        assertEquals(1, updates)
        assertEquals(0, checks)
    }

    @Test
    fun aFailedCheckRetriesTheCheck() {
        render(AppUpdateState.Failed("update_manifest_http_502"))

        composeRule.onNodeWithText("Retry").performClick()
        assertEquals(0, updates)
        assertEquals(1, checks)
    }

    @Test
    fun aDownloadedBuildOffersInstall() {
        render(AppUpdateState.ReadyToInstall(info, File("unused.apk")))

        assertEquals(1, clickableCount())
        composeRule.onNodeWithText("Install").performClick()
        assertEquals(1, updates)
    }
}
