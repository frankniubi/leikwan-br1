package com.leikwan.bridge.ui.login

import android.app.Application
import android.util.Log
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.ClientInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.net.URI

data class LoginUiState(
    val isLoading: Boolean = false,
    val error: String? = null,
    val isLoggedIn: Boolean = false,
    val showClientPicker: Boolean = false,
    val clients: List<ClientInfo> = emptyList(),
    val pendingPanelUrl: String = "",
    val pendingEmail: String = "",
    val pendingPassword: String = "",
    val pendingSessionToken: String = "",
    val pendingUsername: String = "",
)

class LoginViewModel(app: Application) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val repo = PanelRepository(app)
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(LoginUiState(isLoggedIn = prefs.isLoggedIn()))
    val state = _state.asStateFlow()

    init {
        Log.i(TAG, "LoginViewModel init id=${System.identityHashCode(this)} loggedIn=${prefs.isLoggedIn()}")
    }

    fun login(panelUrl: String, email: String, password: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            Log.i(TAG, "login start id=${System.identityHashCode(this@LoginViewModel)} host=${safeHost(panelUrl)} emailPresent=${email.isNotBlank()} passwordPresent=${password.isNotBlank()}")
            try {
                val resp = repo.login(panelUrl, email, password)
                Log.i(TAG, "login returned ok clients=${resp.clients.size} clientId=${resp.clientId}")
                if (resp.clients.size > 1 && resp.clientId == null) {
                    _state.value = _state.value.copy(
                        isLoading = false,
                        showClientPicker = true,
                        clients = resp.clients,
                        pendingPanelUrl = panelUrl,
                        pendingEmail = email,
                        pendingPassword = password,
                        pendingSessionToken = resp.token.orEmpty(),
                        pendingUsername = resp.username.orEmpty(),
                    )
                } else {
                    _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
                }
            } catch (e: Exception) {
                val message = e.message?.takeIf { it.isNotBlank() } ?: s(R.string.login_error_generic)
                Log.w(TAG, "login failed id=${System.identityHashCode(this@LoginViewModel)} message=$message", e)
                _state.value = _state.value.copy(isLoading = false, error = message)
            }
        }
    }

    fun selectClient(clientId: Long) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null, showClientPicker = false)
            try {
                val current = _state.value
                repo.selectClient(
                    panelUrl = current.pendingPanelUrl,
                    email = current.pendingEmail,
                    password = current.pendingPassword,
                    sessionToken = current.pendingSessionToken,
                    clientId = clientId,
                    clients = current.clients,
                    username = current.pendingUsername,
                )
                _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
            } catch (e: Exception) {
                val message = e.message?.takeIf { it.isNotBlank() } ?: s(R.string.login_error_switch_failed)
                Log.w(TAG, "switch client failed id=${System.identityHashCode(this@LoginViewModel)} message=$message", e)
                _state.value = _state.value.copy(isLoading = false, error = message)
            }
        }
    }

    fun clearError() {
        _state.value = _state.value.copy(error = null)
    }

    fun dismissClientPicker() {
        _state.value = _state.value.copy(showClientPicker = false, error = s(R.string.login_error_select_client))
    }

    override fun onCleared() {
        Log.i(TAG, "LoginViewModel cleared id=${System.identityHashCode(this)}")
        super.onCleared()
    }

    private fun safeHost(panelUrl: String): String =
        runCatching { URI(panelUrl).host ?: "<no-host>" }.getOrDefault("<invalid-url>")

    private fun s(@StringRes id: Int, vararg args: Any): String =
        appContext.getString(id, *args)

    private companion object {
        const val TAG = "LKLogin"
    }
}
