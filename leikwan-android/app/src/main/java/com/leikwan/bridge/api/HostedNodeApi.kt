package com.leikwan.bridge.api

import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

/**
 * Hosted user nodes ("上云"): the user's own Shadowsocks / SOCKS5 / VLESS nodes stored by the panel
 * for this client, plus which service forward each one rides on. Only a trusted device of the
 * owning client may call these; every response carries the full listing so one call is enough to
 * resync.
 *
 * Kept apart from [PanelApi] so adding endpoints here never touches the PanelApi fakes in tests.
 */
interface HostedNodeApi {
    @GET("api/client/hosted-nodes")
    suspend fun list(@Header("Authorization") auth: String): Response<HostedNodesResponse>

    @POST("api/client/hosted-nodes")
    suspend fun create(
        @Header("Authorization") auth: String,
        @Body request: HostedNodeUploadRequest,
    ): Response<HostedNodesResponse>

    @POST("api/client/hosted-nodes/batch")
    suspend fun batchCreate(
        @Header("Authorization") auth: String,
        @Body request: HostedNodeBatchRequest,
    ): Response<HostedNodesResponse>

    @PUT("api/client/hosted-nodes/{id}")
    suspend fun update(
        @Header("Authorization") auth: String,
        @Path("id") nodeId: Long,
        @Body request: HostedNodeUpdateRequest,
    ): Response<HostedNodesResponse>

    @DELETE("api/client/hosted-nodes/{id}")
    suspend fun delete(
        @Header("Authorization") auth: String,
        @Path("id") nodeId: Long,
    ): Response<HostedNodesResponse>

    @PUT("api/client/hosted-node-pairings/{forwardId}")
    suspend fun pair(
        @Header("Authorization") auth: String,
        @Path("forwardId") forwardId: Long,
        @Body request: HostedNodePairingRequest,
    ): Response<HostedNodesResponse>
}

data class HostedNodesResponse(
    val ok: Boolean = false,
    val error: String? = null,
    val field: String? = null,
    /** Empty when the client hosts no nodes; matches client.hosted_nodes_revision in the pull. */
    val revision: String? = null,
    val nodes: List<HostedNodeDto>? = null,
    val pairings: List<HostedNodePairingDto>? = null,
    @SerializedName("node_id") val nodeId: Long? = null,
    val created: Boolean? = null,
    /** Per-entry outcome of a batch upload. */
    val results: List<HostedNodeBatchResult>? = null,
)

data class HostedNodeBatchResult(
    val ref: String? = null,
    val ok: Boolean = false,
    @SerializedName("node_id") val nodeId: Long? = null,
    val created: Boolean? = null,
    val error: String? = null,
    val field: String? = null,
)

data class HostedNodeBatchEntry(
    /** Echoed back in the result; the local Room id. */
    val ref: String,
    val uri: String,
    val name: String? = null,
)

data class HostedNodeBatchRequest(val nodes: List<HostedNodeBatchEntry>)

data class HostedNodeUpdateRequest(
    val uri: String,
    val name: String? = null,
)

data class HostedNodeDto(
    val id: Long,
    val name: String? = null,
    val protocol: String? = null,
    val server: String? = null,
    val port: Int? = null,
    /** Canonical share link; imported with ProxyUriParser exactly like a pasted link. */
    val uri: String? = null,
    val version: Long? = null,
    val source: String? = null,
)

data class HostedNodePairingDto(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("node_id") val nodeId: Long,
    /** "explicit" (chosen in the console or on a device) or "target_match" (forward target equals the node). */
    val source: String? = null,
)

data class HostedNodeUploadRequest(
    val uri: String,
    val name: String? = null,
    @SerializedName("pair_forward_id") val pairForwardId: Long? = null,
)

data class HostedNodePairingRequest(
    /** null clears the explicit pairing (Gson omits it; the panel treats a missing node_id as clear). */
    @SerializedName("node_id") val nodeId: Long?,
)
