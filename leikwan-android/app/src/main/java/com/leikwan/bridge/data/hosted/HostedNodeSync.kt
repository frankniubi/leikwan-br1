package com.leikwan.bridge.data.hosted

import android.content.Context
import androidx.room.withTransaction
import com.leikwan.bridge.api.HostedNodeApi
import com.leikwan.bridge.api.HostedNodeBatchEntry
import com.leikwan.bridge.api.HostedNodeBatchRequest
import com.leikwan.bridge.api.HostedNodePairingRequest
import com.leikwan.bridge.api.HostedNodeUpdateRequest
import com.leikwan.bridge.api.HostedNodeUploadRequest
import com.leikwan.bridge.api.HostedNodesResponse
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.ProxyNodeDao
import com.leikwan.bridge.proxy.ProxyShareUri
import com.leikwan.bridge.proxy.ProxyUriParser
import retrofit2.Response

/** A hosted-node call the panel refused; [code] is the panel error (e.g. hosted_node_invalid), [field] the bad field. */
class HostedNodeException(val code: String, val field: String? = null) : Exception(code)

data class HostedBulkUploadResult(
    val uploaded: Int,
    val alreadyHosted: Int,
    val unsupported: Int,
    val failed: Int,
)

/**
 * Two-way sync of the client's hosted nodes ("上云") with this device.
 *
 * Model (see docs/plans/2026-10-12-hosted-user-node-pairing.md):
 *  - The panel is the source of truth for hosted nodes, keyed by client id. A hosted node is a Room
 *    row with [ProxyNode.hostedId] set; it is a cache of the panel copy.
 *  - Writes are write-through: upload, edit and delete call the panel first and then apply the
 *    listing it returns. Nothing is queued offline, so the device copy never diverges and the
 *    panel's arrival order is the only ordering (last writer wins).
 *  - Cloud -> local: when the pull's hosted_nodes_revision moves, the listing is fetched and
 *    materialized. Rows are matched by hostedId; a new hosted node that equals an existing local node
 *    ([HostedNodeIdentity]) adopts that row; hosted rows missing from the listing are deleted.
 *  - Local-only nodes are never uploaded or changed by sync; the user uploads them explicitly
 *    (one node or all of them). Device-local fields (enabled, createdAt, forwardId) are kept.
 */
class HostedNodeSync(
    private val api: () -> HostedNodeApi,
    private val authHeader: () -> String,
    private val clientIdProvider: () -> Long,
    private val dao: ProxyNodeDao,
    private val store: HostedNodePairingStore,
    private val transaction: suspend (suspend () -> Unit) -> Unit = { block -> block() },
) {
    /**
     * Called after every config pull with client.hosted_nodes_revision. Null means the panel
     * predates hosted nodes: nothing happens. Returns true when a sync ran.
     */
    suspend fun syncIfRevisionChanged(revision: String?): Boolean {
        if (revision == null) return false
        val clientId = clientIdProvider()
        if (store.clientId == clientId && store.revision == revision) return false
        if (revision.isEmpty() && dao.getHosted().isEmpty()) {
            // Nothing hosted on either side: record it without a fetch.
            store.save(clientId, revision, emptyMap())
            return false
        }
        refresh()
        return true
    }

    /** Fetches the listing and materializes it. */
    suspend fun refresh() {
        apply(call { api().list(authHeader()) })
    }

    /** Uploads one local node; it becomes hosted (same row) and optionally pairs with [pairForwardId]. */
    suspend fun upload(node: ProxyNode, pairForwardId: Long? = null): ProxyNode {
        node.hostedId?.let { return node }
        val uri = shareUriOrThrow(node)
        val response = call {
            api().create(authHeader(), HostedNodeUploadRequest(uri = uri, name = node.name, pairForwardId = pairForwardId))
        }
        val hostedId = response.nodeId ?: throw HostedNodeException("hosted_node_invalid")
        linkLocalRow(node, hostedId)
        apply(response)
        return dao.getById(node.id) ?: node.copy(hostedId = hostedId)
    }

    /** "Sync all local nodes to cloud": every local Shadowsocks / SOCKS5 / VLESS node, in one batch. */
    suspend fun uploadAllLocal(): HostedBulkUploadResult {
        val local = dao.getAllList().filter { it.hostedId == null }
        val uploadable = local.filter { HostedNodeIdentity.isUploadable(it) && ProxyShareUri.fromNode(it) != null }
        val unsupported = local.size - uploadable.size
        if (uploadable.isEmpty()) return HostedBulkUploadResult(0, 0, unsupported, 0)
        var uploaded = 0
        var already = 0
        var failed = 0
        uploadable.chunked(MAX_BATCH).forEach { chunk ->
            val entries = chunk.map { node ->
                HostedNodeBatchEntry(ref = node.id.toString(), uri = ProxyShareUri.fromNode(node).orEmpty(), name = node.name)
            }
            val response = call { api().batchCreate(authHeader(), HostedNodeBatchRequest(entries)) }
            val byRef = chunk.associateBy { it.id.toString() }
            response.results.orEmpty().forEach { result ->
                val node = byRef[result.ref] ?: return@forEach
                val hostedId = result.nodeId
                if (result.ok && hostedId != null) {
                    linkLocalRow(node, hostedId)
                    if (result.created == true) uploaded++ else already++
                } else {
                    failed++
                }
            }
            apply(response)
        }
        return HostedBulkUploadResult(uploaded, already, unsupported, failed)
    }

    /** Pushes an edited hosted node to the panel; the local row changes only once the panel accepted it. */
    suspend fun update(edited: ProxyNode) {
        val hostedId = edited.hostedId ?: throw HostedNodeException("hosted_node_not_found")
        val uri = shareUriOrThrow(edited)
        apply(call { api().update(authHeader(), hostedId, HostedNodeUpdateRequest(uri = uri, name = edited.name)) })
    }

    /** Deletes a hosted node for every device of the client. */
    suspend fun delete(node: ProxyNode) {
        val hostedId = node.hostedId ?: throw HostedNodeException("hosted_node_not_found")
        apply(call { api().delete(authHeader(), hostedId) })
    }

    /**
     * Records the user's pick for a forward. A hosted node is paired on the panel for every device;
     * a local-only node stays a pick on this device (the panel cannot reference it). Returns true
     * when the panel was updated.
     */
    suspend fun pushPairing(forwardId: Long, localNodeId: Long): Boolean {
        if (forwardId <= 0L) return false
        val node = dao.getById(localNodeId) ?: return false
        val hostedId = node.hostedId ?: return false
        if (store.pairings.value[forwardId] == localNodeId) return false
        apply(call { api().pair(authHeader(), forwardId, HostedNodePairingRequest(nodeId = hostedId)) })
        return true
    }

    /** Materializes a listing from the panel into Room and the pairing store. */
    suspend fun apply(listing: HostedNodesResponse) {
        val serverToLocal = mutableMapOf<Long, Long>()
        transaction {
            val all = dao.getAllList()
            val hostedGroups = all.filter { it.hostedId != null }.groupBy { it.hostedId!! }
            // One row per hosted id: an older row wins, later copies are duplicates.
            hostedGroups.values.forEach { rows -> rows.sortedBy { it.id }.drop(1).forEach { dao.delete(it) } }
            val hostedRows = hostedGroups.mapValues { (_, rows) -> rows.minBy { it.id } }
            val adoptable = all.filter { it.hostedId == null }.toMutableList()
            val seen = mutableSetOf<Long>()
            for (dto in listing.nodes.orEmpty()) {
                val parsed = dto.uri?.let(ProxyUriParser::parse)
                    ?.takeIf { HostedNodeIdentity.isUploadable(it) }
                    ?: continue
                val incoming = parsed.copy(name = dto.name?.takeIf { it.isNotBlank() } ?: parsed.name)
                seen += dto.id
                val existing = hostedRows[dto.id]
                val localId = if (existing != null) {
                    val updated = existing.withConnectionOf(incoming)
                    if (updated != existing) dao.update(updated)
                    existing.id
                } else {
                    val key = HostedNodeIdentity.key(incoming)
                    val match = adoptable.firstOrNull { key != null && HostedNodeIdentity.key(it) == key }
                    if (match != null) {
                        adoptable.remove(match)
                        dao.update(match.withConnectionOf(incoming).copy(hostedId = dto.id, subscriptionId = null))
                        match.id
                    } else {
                        dao.insert(incoming.copy(hostedId = dto.id))
                    }
                }
                serverToLocal[dto.id] = localId
            }
            hostedRows.values.filter { it.hostedId !in seen }.forEach { dao.delete(it) }
        }
        val pairings = listing.pairings.orEmpty()
            .mapNotNull { pairing -> serverToLocal[pairing.nodeId]?.let { pairing.forwardId to it } }
            .toMap()
        store.save(clientIdProvider(), listing.revision.orEmpty(), pairings)
    }

    private suspend fun linkLocalRow(node: ProxyNode, hostedId: Long) {
        val current = dao.getById(node.id) ?: return
        if (current.hostedId == hostedId) return
        if (dao.getHosted().any { it.hostedId == hostedId && it.id != current.id }) {
            // The panel already hosts this node and this device has it: the local copy is a duplicate.
            dao.delete(current)
            return
        }
        // Detach from its subscription so a subscription refresh cannot delete the hosted row.
        dao.update(current.copy(hostedId = hostedId, subscriptionId = null))
    }

    private fun shareUriOrThrow(node: ProxyNode): String {
        if (!HostedNodeIdentity.isUploadable(node)) throw HostedNodeException("hosted_node_invalid", "protocol")
        return ProxyShareUri.fromNode(node) ?: throw HostedNodeException("hosted_node_invalid", "uri")
    }

    private suspend fun call(block: suspend () -> Response<HostedNodesResponse>): HostedNodesResponse {
        val response = block()
        val body = response.body() ?: parseError(response)
        if (!response.isSuccessful || !body.ok) {
            throw HostedNodeException(body.error ?: "hosted_nodes_http_${response.code()}", body.field)
        }
        return body
    }

    private fun parseError(response: Response<HostedNodesResponse>): HostedNodesResponse {
        val raw = runCatching { response.errorBody()?.string() }.getOrNull().orEmpty()
        return runCatching { com.google.gson.Gson().fromJson(raw, HostedNodesResponse::class.java) }.getOrNull()
            ?: HostedNodesResponse(ok = false, error = "hosted_nodes_http_${response.code()}")
    }

    private fun ProxyNode.withConnectionOf(source: ProxyNode): ProxyNode = copy(
        name = source.name,
        type = source.type,
        originalServer = source.originalServer,
        originalPort = source.originalPort,
        configJson = source.configJson,
        subscriptionId = null,
    )

    companion object {
        /** Panel limit per batch (HostedNodeService::MAX_NODES_PER_CLIENT). */
        const val MAX_BATCH = 32

        fun from(
            context: Context,
            api: () -> HostedNodeApi,
            authHeader: () -> String,
            clientIdProvider: () -> Long,
        ): HostedNodeSync {
            val db = AppDatabase.get(context)
            return HostedNodeSync(
                api = api,
                authHeader = authHeader,
                clientIdProvider = clientIdProvider,
                dao = db.proxyNodeDao(),
                store = HostedNodePairingStore.from(context),
                transaction = { block -> db.withTransaction { block() } },
            )
        }
    }
}
