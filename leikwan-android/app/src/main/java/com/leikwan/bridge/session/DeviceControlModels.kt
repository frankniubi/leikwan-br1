package com.leikwan.bridge.session

import com.google.gson.annotations.SerializedName
import java.io.IOException

data class DeviceCommand(
    val version: Long = 0L,
    val action: String = "",
    val reason: String = "",
)

data class DeviceControlEnvelope(
    val ok: Boolean = false,
    val error: String = "",
    val command: DeviceCommand? = null,
)

class PanelControlException(
    val code: String,
    val command: DeviceCommand? = null,
) : IOException(code)

data class EnrollmentResult(
    val clientId: Long,
    val deviceId: String,
    val deviceSessionExpiresAtMs: Long,
)

data class DeviceChallengeRequest(
    @SerializedName("client_id") val clientId: Long,
    @SerializedName("device_id") val deviceId: String,
)

data class EnrollmentCodeChallengeRequest(
    @SerializedName("enrollment_code") val enrollmentCode: String,
    @SerializedName("device_id") val deviceId: String,
)

data class DeviceChallengeResponse(
    val ok: Boolean = false,
    val nonce: String = "",
    @SerializedName("client_id") val clientId: Long? = null,
    @SerializedName("expires_at") val expiresAt: String = "",
)

data class DeviceEnrollRequest(
    @SerializedName("client_id") val clientId: Long,
    @SerializedName("device_id") val deviceId: String,
    val nonce: String,
    @SerializedName("attestation_proof") val attestationProof: com.leikwan.bridge.security.AttestationProof,
    @SerializedName("os_release") val osRelease: String,
)

data class EnrollmentCodeEnrollRequest(
    @SerializedName("enrollment_code") val enrollmentCode: String,
    @SerializedName("device_id") val deviceId: String,
    val nonce: String,
    @SerializedName("attestation_proof") val attestationProof: com.leikwan.bridge.security.AttestationProof,
    @SerializedName("os_release") val osRelease: String,
)

data class DeviceEnrollResponse(
    val ok: Boolean = false,
    @SerializedName("client_id") val clientId: Long = 0L,
    @SerializedName("agent_token") val agentToken: String? = null,
    @SerializedName("device_session") val deviceSession: String = "",
    @SerializedName("expires_at") val expiresAt: String = "",
    @SerializedName("command_version") val commandVersion: Long = 0L,
)

data class DeviceAckRequest(
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("command_version") val commandVersion: Long,
)

data class DeviceAckResponse(val ok: Boolean = false)
