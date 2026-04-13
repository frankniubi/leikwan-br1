package com.leikwan.bridge.ui

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.ui.login.LoginScreen
import com.leikwan.bridge.ui.nav.MainScreen
import com.leikwan.bridge.ui.theme.LeikwanTheme
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import java.security.MessageDigest

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
        enableEdgeToEdge()
        handleIncomingIntent(intent)
        setContent {
            LeikwanTheme {
                var isLoggedIn by mutableStateOf(Prefs.get(this).isLoggedIn())
                if (isLoggedIn) {
                    MainScreen(onLogout = { isLoggedIn = false })
                } else {
                    LoginScreen(onLoginSuccess = { isLoggedIn = true })
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleIncomingIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        checkClipboard()
    }

    private fun handleIncomingIntent(intent: Intent?) {
        if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            intent.getStringExtra(Intent.EXTRA_TEXT)?.let { text ->
                _pendingImport.tryEmit(text)
            }
        }
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
        return bytes.joinToString("") { "%02x".format(it) }
    }
}
