package com.leikwan.bridge.ui.login

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R

private enum class EnrollmentMethod { TOKEN, CODE }

@Composable
fun EnrollmentScreen(
    onEnrollmentSuccess: () -> Unit,
    prefilledClientId: String = "",
    prefilledEnrollmentCode: String = "",
    onPrefillConsumed: () -> Unit = {},
    viewModel: EnrollmentViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    var method by remember { mutableStateOf(EnrollmentMethod.TOKEN) }
    var clientId by remember { mutableStateOf(prefilledClientId.filter(Char::isDigit)) }
    var agentToken by remember { mutableStateOf("") }
    var enrollmentCode by remember { mutableStateOf(prefilledEnrollmentCode) }

    LaunchedEffect(prefilledEnrollmentCode, prefilledClientId) {
        if (prefilledEnrollmentCode.isNotBlank()) {
            method = EnrollmentMethod.CODE
            enrollmentCode = prefilledEnrollmentCode
            clientId = prefilledClientId.filter(Char::isDigit)
            onPrefillConsumed()
        }
    }
    LaunchedEffect(state.isEnrolled) {
        if (state.isEnrolled) onEnrollmentSuccess()
    }

    state.error?.let { error ->
        AlertDialog(
            onDismissRequest = viewModel::clearError,
            title = { Text(stringResource(R.string.login_sign_in_failed)) },
            text = { Text(error) },
            confirmButton = {
                TextButton(onClick = viewModel::clearError) { Text(stringResource(R.string.common_ok)) }
            },
        )
    }

    Box(
        modifier = Modifier.fillMaxSize().padding(horizontal = 24.dp),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().widthIn(max = 420.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Image(
                painter = painterResource(R.drawable.ic_launcher_foreground),
                contentDescription = stringResource(R.string.app_name),
                modifier = Modifier.size(76.dp),
            )
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.app_name), style = MaterialTheme.typography.headlineMedium)
            Text(
                stringResource(R.string.enrollment_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(24.dp))

            TabRow(selectedTabIndex = method.ordinal, modifier = Modifier.fillMaxWidth()) {
                Tab(
                    selected = method == EnrollmentMethod.TOKEN,
                    onClick = { method = EnrollmentMethod.TOKEN },
                    text = { Text(stringResource(R.string.enrollment_method_token)) },
                )
                Tab(
                    selected = method == EnrollmentMethod.CODE,
                    onClick = { method = EnrollmentMethod.CODE },
                    text = { Text(stringResource(R.string.enrollment_method_code)) },
                )
            }
            Spacer(Modifier.height(16.dp))
            OutlinedTextField(
                value = clientId,
                onValueChange = { clientId = it.filter(Char::isDigit) },
                label = { Text(stringResource(R.string.login_client_id)) },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            )
            Spacer(Modifier.height(12.dp))
            if (method == EnrollmentMethod.TOKEN) {
                OutlinedTextField(
                    value = agentToken,
                    onValueChange = { agentToken = it },
                    label = { Text(stringResource(R.string.login_agent_token)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                )
            } else {
                OutlinedTextField(
                    value = enrollmentCode,
                    onValueChange = { enrollmentCode = it },
                    label = { Text(stringResource(R.string.enrollment_code)) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                )
            }
            Spacer(Modifier.height(18.dp))
            Button(
                onClick = {
                    if (method == EnrollmentMethod.TOKEN) {
                        viewModel.enrollWithToken(clientId, agentToken)
                    } else {
                        viewModel.enrollWithCode(clientId, enrollmentCode)
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                enabled = !state.isLoading && clientId.isNotBlank()
                    && if (method == EnrollmentMethod.TOKEN) agentToken.isNotBlank() else enrollmentCode.isNotBlank(),
            ) {
                if (state.isLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                    Spacer(Modifier.width(8.dp))
                }
                Text(if (state.isLoading) stringResource(R.string.login_signing_in) else stringResource(R.string.login_sign_in))
            }
        }
    }
}
