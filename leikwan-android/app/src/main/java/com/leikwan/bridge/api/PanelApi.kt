package com.leikwan.bridge.api

import com.google.gson.JsonElement
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.Response

interface PanelApi {
    @POST("api/client/login")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    @POST("api/client/select")
    suspend fun selectClient(@Body request: ClientSelectionRequest): Response<ClientSelectionResponse>

    @POST("api/client/heartbeat")
    suspend fun heartbeat(@Header("Authorization") auth: String): HeartbeatResponse

    @GET("api/client/configs/pull")
    suspend fun pullConfigs(@Header("Authorization") auth: String): PullConfigsResponse

    @GET("api/client/config/{forwardId}")
    suspend fun getForwardConfig(
        @Header("Authorization") auth: String,
        @Path("forwardId") forwardId: Long,
    ): JsonElement
}
