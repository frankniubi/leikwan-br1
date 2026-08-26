package com.leikwan.bridge.ui.login

import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class LoginUiState(
    val isLoading: Boolean = false,
    val error: String? = null,
    val isLoggedIn: Boolean = false,
)

class LoginViewModel(app: Application) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val repo = PanelRepository(app)
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(LoginUiState(isLoggedIn = prefs.isLoggedIn()))
    val state = _state.asStateFlow()

    fun enroll(clientIdText: String, agentToken: String) {
        val clientId = clientIdText.trim().toLongOrNull()
        if (clientId == null || clientId <= 0L || agentToken.isBlank()) {
            _state.value = _state.value.copy(error = s(R.string.login_error_credentials_required))
            return
        }

        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                repo.enroll(clientId, agentToken.trim())
                _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
            } catch (exception: Exception) {
                val message = exception.message?.takeIf { it.isNotBlank() } ?: s(R.string.login_error_generic)
                _state.value = _state.value.copy(isLoading = false, error = message)
            }
        }
    }

    fun clearError() {
        _state.value = _state.value.copy(error = null)
    }

    private fun s(@StringRes id: Int, vararg args: Any): String =
        appContext.getString(id, *args)
}
