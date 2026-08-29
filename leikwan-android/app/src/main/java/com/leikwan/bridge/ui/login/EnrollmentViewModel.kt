package com.leikwan.bridge.ui.login

import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.session.PanelControlException
import com.leikwan.bridge.session.SessionGate
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.security.ProviderException

data class EnrollmentUiState(
    val isLoading: Boolean = false,
    val error: String? = null,
    val isEnrolled: Boolean = false,
)

internal fun enrollmentErrorMessage(
    exception: Throwable,
    generic: String,
    hardwareAttestationMessage: String,
): String {
    val causes = generateSequence(exception) { it.cause }.take(4).toList()
    if (causes.any { cause ->
            cause is ProviderException ||
                cause.message?.contains("ATTESTATION_KEYS_NOT_PROVISIONED", ignoreCase = true) == true
        }) {
        return hardwareAttestationMessage
    }
    return causes.asSequence()
        .mapNotNull { it.message?.trim()?.takeIf(String::isNotBlank) }
        .firstOrNull()
        ?: generic
}

class EnrollmentViewModel(app: Application) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val repo = PanelRepository(app)
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(EnrollmentUiState(isEnrolled = prefs.isLoggedIn()))
    val state = _state.asStateFlow()

    fun enrollWithToken(clientIdText: String, agentToken: String) {
        val clientId = clientIdText.trim().toLongOrNull()
        if (clientId == null || clientId <= 0L || agentToken.isBlank()) {
            _state.value = _state.value.copy(error = s(R.string.login_error_credentials_required))
            return
        }
        launchEnrollment { repo.enroll(clientId, agentToken.trim()) }
    }

    fun enrollWithCode(clientIdText: String, enrollmentCode: String) {
        val clientId = clientIdText.trim().toLongOrNull()
        if (clientId == null || clientId <= 0L || enrollmentCode.isBlank()) {
            _state.value = _state.value.copy(error = s(R.string.enrollment_error_code_required))
            return
        }
        launchEnrollment { repo.enrollWithCode(clientId, enrollmentCode.trim()) }
    }

    fun clearError() {
        _state.value = _state.value.copy(error = null)
    }

    private fun launchEnrollment(action: suspend () -> Unit) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                action()
                SessionGate.clear()
                _state.value = _state.value.copy(isLoading = false, isEnrolled = true)
            } catch (terminal: PanelControlException) {
                SessionGate.markTerminal(terminal)
                _state.value = _state.value.copy(isLoading = false, error = terminal.code)
            } catch (exception: Exception) {
                val message = enrollmentErrorMessage(
                    exception,
                    generic = s(R.string.login_error_generic),
                    hardwareAttestationMessage = s(R.string.login_error_hardware_attestation),
                )
                _state.value = _state.value.copy(isLoading = false, error = message)
            }
        }
    }

    private fun s(@StringRes id: Int, vararg args: Any): String = appContext.getString(id, *args)
}
