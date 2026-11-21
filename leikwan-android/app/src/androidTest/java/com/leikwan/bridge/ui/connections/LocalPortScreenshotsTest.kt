package com.leikwan.bridge.ui.connections

import android.graphics.Bitmap
import android.os.ParcelFileDescriptor
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.leikwan.bridge.R
import com.leikwan.bridge.net.PortNetworkChoice
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.service.AppConnectionMode
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.theme.LeikwanTheme
import java.io.File
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/** Actual stopped UI with documentation-only sample forwards; never starts a service or logs in. */
@RunWith(AndroidJUnit4::class)
class LocalPortScreenshotsTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun captureStoppedPortSelection() {
        val forwards = listOf(
            ForwardListItem(
                forwardId = 159L, serviceName = "示例 · 香港 → 东京", target = "203.0.113.10:443",
                listenPort = 30159, mode = ForwardMode.ULTRA, transport = "tcp+udp",
                serviceStatus = "active", endpointMode = "ultra", speedLimitMbps = null, backendMode = "ultra",
            ),
            ForwardListItem(
                forwardId = 160L, serviceName = "示例 · 上海 → 香港", target = "198.51.100.20:8443",
                listenPort = 30160, mode = ForwardMode.ULTRA, transport = "tcp",
                serviceStatus = "active", endpointMode = "ultra", speedLimitMbps = null, backendMode = "ultra",
            ),
        )
        composeRule.runOnUiThread {
            composeRule.activity.setContent {
                var state by remember {
                    mutableStateOf(ConnectionsUiState(forwards = forwards, portForwardIds = forwards.map { it.forwardId }.toSet()))
                }
                LeikwanTheme(darkTheme = false) {
                    Surface(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
                        ConnectionsScreenContent(
                            state = state,
                            onMode = { state = state.copy(connectionMode = it) },
                            onPortNetwork = { state = state.copy(portNetwork = it) },
                        )
                    }
                }
            }
        }
        val ports = composeRule.activity.getString(R.string.ports_only)
        val advanced = composeRule.activity.getString(R.string.ports_advanced)
        val wifi = composeRule.activity.getString(R.string.ports_wifi)
        val cellular = composeRule.activity.getString(R.string.ports_cellular)
        val firstPort = composeRule.activity.getString(R.string.ports_local_port, 30159)
        val secondPort = composeRule.activity.getString(R.string.ports_local_port, 30160)
        composeRule.onNodeWithText(ports).performClick().assertIsSelected()
        composeRule.onNodeWithText(firstPort).assertIsDisplayed()
        composeRule.onNodeWithText(secondPort).assertIsDisplayed()
        capture("01-wifi-selection.png")
        capture("02-wifi-forwards.png")
        composeRule.onNodeWithText(advanced).performScrollTo().performClick()
        composeRule.onNodeWithText(wifi).performScrollTo().performClick().assertIsSelected()
        capture("03-cellular-selection.png")
        composeRule.onNodeWithText(cellular).performScrollTo().performClick().assertIsSelected()
        capture("04-cellular-forwards.png")
    }

    private fun capture(name: String) {
        composeRule.waitForIdle()
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val automation = instrumentation.uiAutomation
        ParcelFileDescriptor.AutoCloseInputStream(
            automation.executeShellCommand("am force-stop com.google.android.apps.nexuslauncher"),
        ).use { it.readBytes() }
        automation.waitForIdle(500, 5_000)
        composeRule.waitForIdle()
        val root = automation.rootInActiveWindow
        if (root != null) {
            val overlays = root.findAccessibilityNodeInfosByText("isn't responding") +
                root.findAccessibilityNodeInfosByText("isn’t responding")
            assertTrue("System ANR overlay still obscures $name", overlays.isEmpty())
        }
        val directory = File(instrumentation.targetContext.filesDir, "local-port-screenshots")
        directory.mkdirs()
        val bitmap = checkNotNull(automation.takeScreenshot()) { "Could not capture the emulator window" }
        try {
            File(directory, name).outputStream().use { output ->
                assertTrue("Could not save $name", bitmap.compress(Bitmap.CompressFormat.PNG, 100, output))
            }
        } finally { bitmap.recycle() }
    }
}
