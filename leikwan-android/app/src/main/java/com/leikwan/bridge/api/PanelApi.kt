package com.leikwan.bridge.api

import com.google.gson.JsonElement
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path

interface PanelApi {
    @POST("api/client/login")
    suspend fun login(@Body request: LoginRequest): LoginResponse

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
