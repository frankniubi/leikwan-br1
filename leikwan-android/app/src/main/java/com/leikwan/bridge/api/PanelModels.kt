package com.leikwan.bridge.api

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

data class LoginRequest(
    val email: String,
    val password: String,
    @SerializedName("client_id") val clientId: Long? = null,
)

data class ClientSelectionRequest(
    @SerializedName("session_token") val sessionToken: String,
    @SerializedName("client_id") val clientId: Long,
)

data class LoginResponse(
    val ok: Boolean,
    val error: String? = null,
    @SerializedName(value = "session_token", alternate = ["token"])
    val token: String? = null,
    @SerializedName("user_id") val userId: Long? = null,
    val username: String? = null,
    @SerializedName("client_id") val clientId: Long? = null,
    @SerializedName("client_name") val clientName: String? = null,
    val clients: List<ClientInfo> = emptyList(),
)

data class ClientInfo(val id: Long, val name: String)

data class ClientSelectionResponse(
    val ok: Boolean,
    val error: String? = null,
    @SerializedName("client_id") val clientId: Long,
    @SerializedName("client_name") val clientName: String,
    @SerializedName("agent_token") val agentToken: String,
)

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
