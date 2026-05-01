package com.leikwan.bridge.ui.login

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.api.ClientInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class LoginUiState(
    val isLoading: Boolean = false,
    val error: String? = null,
    val isLoggedIn: Boolean = false,
    val showClientPicker: Boolean = false,
    val clients: List<ClientInfo> = emptyList(),
    val pendingPanelUrl: String = "",
    val pendingEmail: String = "",
    val pendingPassword: String = "",
)

class LoginViewModel(app: Application) : AndroidViewModel(app) {
    private val repo = PanelRepository(app)
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(LoginUiState(isLoggedIn = prefs.isLoggedIn()))
    val state = _state.asStateFlow()

    fun login(panelUrl: String, email: String, password: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                val resp = repo.login(panelUrl, email, password)
                if (resp.clients.size > 1) {
                    _state.value = _state.value.copy(
                        isLoading = false,
                        showClientPicker = true,
                        clients = resp.clients,
                        pendingPanelUrl = panelUrl,
                        pendingEmail = email,
                        pendingPassword = password,
                    )
                } else {
                    _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
                }
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message ?: "Login failed")
            }
        }
    }

    fun selectClient(clientId: Long) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null, showClientPicker = false)
            try {
                repo.switchClient(clientId)
                _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message ?: "Switch failed")
            }
        }
    }

    fun dismissClientPicker() {
        // Use the already-selected first client
        _state.value = _state.value.copy(showClientPicker = false, isLoggedIn = true)
    }
}
