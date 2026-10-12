package com.leikwan.bridge.ui

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.net.VpnService
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.collectAsState
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.security.DeviceSecurity
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.SessionGate
import com.leikwan.bridge.ui.login.EnrollmentScreen
import com.leikwan.bridge.ui.nav.MainScreen
import com.leikwan.bridge.ui.security.BlockedDeviceScreen
import com.leikwan.bridge.ui.theme.LeikwanTheme
import com.leikwan.bridge.ui.theme.ThemeController
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import java.security.MessageDigest
import java.net.URI

private data class EnrollmentLink(
    val clientId: String,
    val code: String,
)

class MainActivity : ComponentActivity() {

    private var vpnPermissionCallback: (() -> Unit)? = null

    private val vpnPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode == RESULT_OK) {
            vpnPermissionCallback?.invoke()
        }
        vpnPermissionCallback = null
    }

    private val _pendingImport = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val pendingImport = _pendingImport.asSharedFlow()

    private val _clipboardImport = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val clipboardImport = _clipboardImport.asSharedFlow()

    private var lastClipHash: String? = null
    private var pendingEnrollmentLink by mutableStateOf<EnrollmentLink?>(null)

    fun requestVpnPermission(onGranted: () -> Unit) {
        val intent = VpnService.prepare(this)
        if (intent != null) {
            vpnPermissionCallback = onGranted
            vpnPermissionLauncher.launch(intent)
        } else {
            onGranted()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Transparent bars whose icons follow the light/dark theme.
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(Color.TRANSPARENT, Color.TRANSPARENT),
        )
        handleIncomingIntent(intent)
        ThemeController.load(Prefs.get(this))
        setContent {
            LeikwanTheme {
                val security = androidx.compose.runtime.remember {
                    DeviceSecurity.evaluate(applicationContext)
                }
                val terminalControl by SessionGate.state.collectAsState()
                if (security != DeviceSecurityResult.Allowed
                    || terminalControl?.code in setOf("device_banned", "device_untrusted")) {
                    BlockedDeviceScreen()
                } else {
                    var isLoggedIn by remember { mutableStateOf(Prefs.get(this).isLoggedIn()) }
                    val enrollmentLink = pendingEnrollmentLink
                    if (isLoggedIn && terminalControl == null) {
                        MainScreen(onLogout = {
                            SessionGate.clear()
                            isLoggedIn = false
                        })
                    } else {
                        EnrollmentScreen(
                            onEnrollmentSuccess = {
                                SessionGate.clear()
                                isLoggedIn = true
                            },
                            prefilledClientId = enrollmentLink?.clientId.orEmpty(),
                            prefilledEnrollmentCode = enrollmentLink?.code.orEmpty(),
                            onPrefillConsumed = { pendingEnrollmentLink = null },
                        )
                    }
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIncomingIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        checkClipboard()
    }

    private fun handleIncomingIntent(intent: Intent?) {
        parseEnrollmentLink(intent)?.let { link ->
            pendingEnrollmentLink = link
            return
        }
        if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            intent.getStringExtra(Intent.EXTRA_TEXT)?.let { text ->
                _pendingImport.tryEmit(text)
            }
        }
    }

    private fun parseEnrollmentLink(intent: Intent?): EnrollmentLink? {
        if (intent?.action != Intent.ACTION_VIEW) return null
        val uri = intent.data ?: return null
        val panelHost = runCatching { URI(BuildConfig.PANEL_URL).host }.getOrNull() ?: return null
        if (!uri.scheme.equals("https", ignoreCase = true)
            || !uri.host.equals(panelHost, ignoreCase = true)
            || uri.path != "/android/enroll") {
            return null
        }
        val clientId = uri.getQueryParameter("client_id")?.trim()?.takeIf { it.matches(Regex("^[1-9][0-9]{0,17}$")) }
            ?: return null
        val code = uri.getQueryParameter("code")?.trim()?.takeIf { it.matches(Regex("^[A-Za-z0-9_-]{16,256}$")) }
            ?: return null
        return EnrollmentLink(clientId, code)
    }

    private fun checkClipboard() {
        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return
        val clip = clipboard.primaryClip ?: return
        if (clip.itemCount == 0) return
        val text = clip.getItemAt(0).text?.toString() ?: return
        if (!ImportRouter.looksImportable(text)) return

        val hash = sha256(text)
        if (hash == lastClipHash) return
        lastClipHash = hash

        _clipboardImport.tryEmit(text)
    }

    private fun sha256(input: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(input.toByteArray())
        return bytes.joinToString("") { "%02x".format(it.toInt() and 0xff) }
    }
}
