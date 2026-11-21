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
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onFirst
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

/**
 * Real emulator window captures of the official dashboard, not previews or generated images.
 * MainActivity hosts the production composables with stopped, explicitly sample-only state.
 * No ViewModel, login, lease, service start, or network-selection side effects are invoked.
 */
@RunWith(AndroidJUnit4::class)
class LocalPortScreenshotsTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun captureStoppedPortSelection() {
        val forwards = listOf(
            ForwardListItem(
                forwardId = 159L,
                serviceName = "示例 · 香港 → 东京 · Ultra",
                target = "203.0.113.10:443",
                listenPort = 30159,
                mode = ForwardMode.ULTRA,
                transport = "tcp+udp",
                serviceStatus = "active",
                endpointMode = "relay",
                speedLimitMbps = null,
            ),
            ForwardListItem(
                forwardId = 160L,
                serviceName = "示例 · 上海 → 香港 · Ultra",
                target = "198.51.100.20:8443",
                listenPort = 30160,
                mode = ForwardMode.ULTRA,
                transport = "tcp",
                serviceStatus = "active",
                endpointMode = "relay",
                speedLimitMbps = null,
            ),
        )
        // Replace only the content of the real activity. Authentication and runtime stay untouched.
        composeRule.runOnUiThread {
            composeRule.activity.setContent {
                var state by remember { mutableStateOf(ConnectionsUiState(forwards = forwards)) }
                LeikwanTheme(darkTheme = false) {
                    Surface(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
                        ConnectionsScreenContent(
                            state = state,
                            onMode = { state = state.copy(connectionMode = it) },
                            onPortNetwork = { state = state.copy(portNetwork = it) },
                            onPortForward = { id ->
                                state = state.copy(portForwardIds = if (id in state.portForwardIds) {
                                    state.portForwardIds - id
                                } else {
                                    state.portForwardIds + id
                                })
                            },
                        )
                    }
                }
            }
        }

        val ports = composeRule.activity.getString(R.string.ports_title)
        val mode = composeRule.activity.getString(R.string.ports_mode)
        val wifi = composeRule.activity.getString(R.string.ports_wifi)
        val cellular = composeRule.activity.getString(R.string.ports_cellular)
        val stopped = composeRule.activity.getString(R.string.connections_status_disconnected)
        val endpoint = composeRule.activity.getString(
            R.string.ports_forward_endpoint, forwards[0].listenPort, forwards[0].target, forwards[0].transport,
        )

        // Drive the same mode, physical-network chips and forward checkbox used by the app.
        composeRule.onNodeWithText(ports).performClick().assertIsSelected()
        composeRule.onNodeWithText(wifi).performScrollTo().performClick().assertIsSelected()
        composeRule.onAllNodes(isToggleable()).onFirst().performScrollTo().performClick().assertIsOn()
        composeRule.onNodeWithText(mode).performScrollTo()
        composeRule.onNodeWithText(ports).assertIsSelected()
        capture("01-wifi-selection.png")

        composeRule.onNodeWithText(stopped).performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText(endpoint).assertIsDisplayed()
        capture("02-wifi-forwards.png")

        composeRule.onNodeWithText(cellular).performScrollTo().performClick().assertIsSelected()
        composeRule.onNodeWithText(mode).performScrollTo()
        composeRule.onNodeWithText(ports).assertIsSelected()
        capture("03-cellular-selection.png")

        composeRule.onNodeWithText(stopped).performScrollTo().assertIsDisplayed()
        composeRule.onNodeWithText(endpoint).assertIsDisplayed()
        capture("04-cellular-forwards.png")
    }

    private fun capture(name: String) {
        composeRule.waitForIdle()
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val automation = instrumentation.uiAutomation
        // CI's Pixel Launcher can ANR independently of the app and obscure the whole window.
        // Drain and close the command descriptor so force-stop finishes before capture.
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
        val bitmap = checkNotNull(automation.takeScreenshot()) {
            "Could not capture the emulator window"
        }
        try {
            File(directory, name).outputStream().use { output ->
                assertTrue("Could not save $name", bitmap.compress(Bitmap.CompressFormat.PNG, 100, output))
            }
        } finally {
            bitmap.recycle()
        }
    }
}
