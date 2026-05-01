package com.leikwan.bridge.api

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

data class LoginRequest(
    val email: String,
    val password: String,
    @SerializedName("client_id") val clientId: Long? = null,
)

data class LoginResponse(
    val ok: Boolean,
    val token: String,
    @SerializedName("user_id") val userId: Long,
    val username: String,
    @SerializedName("client_id") val clientId: Long,
    @SerializedName("client_name") val clientName: String,
    val clients: List<ClientInfo>,
)

data class ClientInfo(val id: Long, val name: String)

data class PullConfigsResponse(
    val ok: Boolean,
    val client: JsonElement,
    val forwards: List<JsonElement>,
)

data class HeartbeatResponse(val ok: Boolean)

data class ForwardInfo(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("service_name") val serviceName: String = "",
    val target: String = "",
    @SerializedName("listen_port") val listenPort: Int = 0,
    val mode: String = "",
    val transport: String = "",
    @SerializedName("service_status") val serviceStatus: String = "",
    @SerializedName("endpoint_mode") val endpointMode: String = "",
    val endpoints: List<JsonElement> = emptyList(),
    @SerializedName("speed_limit_mbps") val speedLimitMbps: Int? = null,
)
