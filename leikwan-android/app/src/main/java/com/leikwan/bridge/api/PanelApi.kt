package com.leikwan.bridge.api

import com.google.gson.JsonElement
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Headers
import retrofit2.http.DELETE
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import com.leikwan.bridge.security.UltraLeaseSeal
import com.leikwan.bridge.session.DeviceAckRequest
import com.leikwan.bridge.session.DeviceAckResponse
import com.leikwan.bridge.session.DeviceChallengeRequest
import com.leikwan.bridge.session.DeviceChallengeResponse
import com.leikwan.bridge.session.DeviceEnrollRequest
import com.leikwan.bridge.session.DeviceEnrollResponse
import com.leikwan.bridge.session.DeviceIdentityEnrollRequest
import com.leikwan.bridge.session.DeviceRenewRequest
import com.leikwan.bridge.session.EnrollmentCodeChallengeRequest
import com.leikwan.bridge.session.EnrollmentCodeEnrollRequest
import com.leikwan.bridge.session.EnrollmentCodeIdentityEnrollRequest

/**
 * Longest Ultra policy/lease validity (seconds) this client accepts while the panel is
 * unreachable. Advertised on the config pull and on every Ultra lease resolve/renew so the
 * panel may issue a grant that survives a control-plane outage (W1, 2026-09-24). A panel that
 * does not know the header keeps issuing <= 300 s grants, which this client still accepts.
 */
const val ULTRA_OFFLINE_VALIDITY_SECONDS: Long = 1800L
const val ULTRA_OFFLINE_VALIDITY_HEADER_NAME: String = "X-Leikwan-Ultra-Offline-Validity"
// Retrofit @Headers needs a compile-time literal; keep it in sync with the two constants above.
const val ULTRA_OFFLINE_VALIDITY_HEADER: String = "X-Leikwan-Ultra-Offline-Validity: 1800"

interface PanelApi {
    @POST("api/client/devices/challenge")
    suspend fun deviceChallenge(@Body request: DeviceChallengeRequest): Response<DeviceChallengeResponse>

    @POST("api/client/devices/enroll")
    suspend fun enrollDevice(@Body request: DeviceEnrollRequest): Response<DeviceEnrollResponse>

    @POST("api/client/devices/identity/enroll")
    suspend fun enrollDeviceIdentity(@Body request: DeviceIdentityEnrollRequest): Response<DeviceEnrollResponse>

    @POST("api/client/devices/renew/challenge")
    suspend fun deviceRenewalChallenge(@Body request: DeviceChallengeRequest): Response<DeviceChallengeResponse>

    @POST("api/client/devices/renew")
    suspend fun renewDevice(@Body request: DeviceRenewRequest): Response<DeviceEnrollResponse>

    @POST("api/client/devices/code/challenge")
    suspend fun enrollmentCodeChallenge(@Body request: EnrollmentCodeChallengeRequest): Response<DeviceChallengeResponse>

    @POST("api/client/devices/code/enroll")
    suspend fun enrollmentCodeEnroll(@Body request: EnrollmentCodeEnrollRequest): Response<DeviceEnrollResponse>

    @POST("api/client/devices/code/identity-enroll")
    suspend fun enrollmentCodeIdentityEnroll(@Body request: EnrollmentCodeIdentityEnrollRequest): Response<DeviceEnrollResponse>

    @POST("api/client/devices/ack")
    suspend fun acknowledgeDevice(
        @Header("Authorization") auth: String,
        @Body request: DeviceAckRequest,
    ): Response<DeviceAckResponse>

    @POST("api/client/heartbeat")
    suspend fun heartbeat(
        @Header("Authorization") auth: String,
        @Body request: HeartbeatRequest = HeartbeatRequest(),
    ): HeartbeatResponse

    @POST("api/client/ultra/lease")
    @Headers(ULTRA_OFFLINE_VALIDITY_HEADER)
    suspend fun resolveUltraLease(
        @Header("Authorization") auth: String,
        @Body request: UltraLeaseRequest,
        // Sealed-lease device key (docs/specs/2026-10-02-ultra-sealed-lease-v1.md); null omits it.
        @Header(UltraLeaseSeal.HEADER) leaseKey: String?,
    ): Response<UltraLeaseResponse>

    @GET("api/client/configs/pull")
    @Headers(ULTRA_OFFLINE_VALIDITY_HEADER)
    suspend fun pullConfigs(@Header("Authorization") auth: String): PullConfigsResponse

    @POST("api/client/client-rules")
    suspend fun createClientRule(
        @Header("Authorization") auth: String,
        @Body request: ClientRuleWriteRequest,
    ): Response<ClientRuleWriteResponse>

    @PUT("api/client/client-rules/{id}")
    suspend fun updateClientRule(
        @Header("Authorization") auth: String,
        @Path("id") ruleId: String,
        @Body request: ClientRuleWriteRequest,
    ): Response<ClientRuleWriteResponse>

    @DELETE("api/client/client-rules/{id}")
    suspend fun deleteClientRule(
        @Header("Authorization") auth: String,
        @Path("id") ruleId: String,
    ): Response<ClientRuleWriteResponse>

    @GET("api/client/config/{forwardId}")
    suspend fun getForwardConfig(
        @Header("Authorization") auth: String,
        @Path("forwardId") forwardId: Long,
    ): JsonElement
}
