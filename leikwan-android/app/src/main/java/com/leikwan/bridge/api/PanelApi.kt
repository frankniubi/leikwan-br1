package com.leikwan.bridge.api

import com.google.gson.JsonElement
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

interface PanelApi {
    @POST("api/client/login")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    @POST("api/client/select")
    suspend fun selectClient(@Body request: ClientSelectionRequest): Response<ClientSelectionResponse>

    @POST("api/client/heartbeat")
    suspend fun heartbeat(
        @Header("Authorization") auth: String,
        @Body request: HeartbeatRequest = HeartbeatRequest(),
    ): HeartbeatResponse

    @GET("api/client/configs/pull")
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
