package com.leikwan.bridge.data.hosted

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.HostedNodeApi
import com.leikwan.bridge.api.HostedNodeBatchRequest
import com.leikwan.bridge.api.HostedNodeBatchResult
import com.leikwan.bridge.api.HostedNodeDto
import com.leikwan.bridge.api.HostedNodePairingDto
import com.leikwan.bridge.api.HostedNodePairingRequest
import com.leikwan.bridge.api.HostedNodeUpdateRequest
import com.leikwan.bridge.api.HostedNodeUploadRequest
import com.leikwan.bridge.api.HostedNodesResponse
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.proxy.ProxyUriParser
import kotlinx.coroutines.runBlocking
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class HostedNodeSyncTest {
    private lateinit var db: AppDatabase
    private lateinit var store: HostedNodePairingStore
    private lateinit var api: FakeHostedNodeApi
    private lateinit var sync: HostedNodeSync
    private var clientId = 7L

    private val socksUri = "socks5://alice:wonder@198.51.100.7:1080#Home"
    private val ssUri = "ss://YWVzLTI1Ni1nY206cHc@ss.example.com:8388#SS"

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java).allowMainThreadQueries().build()
        val prefs = context.getSharedPreferences("hosted-node-sync-test", Context.MODE_PRIVATE)
        prefs.edit().clear().commit()
        store = HostedNodePairingStore(prefs)
        api = FakeHostedNodeApi()
        sync = HostedNodeSync(
            api = { api },
            authHeader = { "Bearer agent-token" },
            clientIdProvider = { clientId },
            dao = db.proxyNodeDao(),
            store = store,
        )
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun `cloud nodes materialize as hosted rows with resolved pairings`() = runBlocking {
        sync.apply(listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), listOf(pairing(101, 11), pairing(102, 99))))

        val rows = db.proxyNodeDao().getAllList()
        val socks = rows.single { it.hostedId == 11L }
        assertEquals("socks5", socks.type)
        assertEquals("198.51.100.7", socks.originalServer)
        assertEquals(1080, socks.originalPort)
        assertEquals("Home", socks.name)
        assertEquals("ss", rows.single { it.hostedId == 12L }.type)
        assertEquals(mapOf(101L to socks.id), store.pairings.value)
        assertEquals("rev1", store.revision)
        assertEquals(7L, store.clientId)
    }

    @Test
    fun `cloud edits update the same row and cloud deletes remove it while local nodes stay`() = runBlocking {
        val localId = db.proxyNodeDao().insert(ProxyUriParser.parse("socks5://local.example.com:1080#Mine")!!)
        sync.apply(listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), emptyList()))
        val hosted = db.proxyNodeDao().getHosted().single { it.hostedId == 11L }
        db.proxyNodeDao().update(hosted.copy(enabled = false))

        sync.apply(listing("rev2", listOf(dto(11, "socks5://alice:changed@198.51.100.8:1081#Home", "Renamed")), emptyList()))

        val rows = db.proxyNodeDao().getAllList()
        val updated = rows.single { it.hostedId == 11L }
        assertEquals("the row id is stable", hosted.id, updated.id)
        assertEquals("Renamed", updated.name)
        assertEquals("198.51.100.8", updated.originalServer)
        assertTrue(updated.configJson.contains("changed"))
        assertFalse("device-local enabled flag is kept", updated.enabled)
        assertNull("node deleted in the cloud is removed", rows.firstOrNull { it.hostedId == 12L })
        assertEquals("local-only node untouched", "local.example.com", rows.single { it.id == localId }.originalServer)
        assertNull(rows.single { it.id == localId }.hostedId)
    }

    @Test
    fun `a cloud node equal to a local node adopts the local row instead of duplicating`() = runBlocking {
        val local = ProxyUriParser.parse("socks5://alice:wonder@198.51.100.7:1080#My%20local%20name")!!.copy(subscriptionId = 5L)
        val localId = db.proxyNodeDao().insert(local)

        sync.apply(listing("rev1", listOf(dto(11, socksUri, "Home")), listOf(pairing(101, 11))))

        val rows = db.proxyNodeDao().getAllList()
        assertEquals(1, rows.size)
        assertEquals(localId, rows.single().id)
        assertEquals(11L, rows.single().hostedId)
        assertNull("adopted rows leave their subscription", rows.single().subscriptionId)
        assertEquals(mapOf(101L to localId), store.pairings.value)
    }

    @Test
    fun `revision gate fetches only when the panel revision or client changes`() = runBlocking {
        api.listResponse = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())

        assertFalse("older panels send no revision", sync.syncIfRevisionChanged(null))
        assertFalse("no hosted nodes anywhere: no fetch", sync.syncIfRevisionChanged(""))
        assertEquals(0, api.listCalls)

        assertTrue(sync.syncIfRevisionChanged("rev1"))
        assertEquals(1, api.listCalls)
        assertFalse(sync.syncIfRevisionChanged("rev1"))
        assertEquals(1, api.listCalls)

        api.listResponse = listing("", emptyList(), emptyList())
        assertTrue("all cloud nodes deleted: fetch to remove them", sync.syncIfRevisionChanged(""))
        assertTrue(db.proxyNodeDao().getHosted().isEmpty())

        api.listResponse = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.syncIfRevisionChanged("rev1")
        clientId = 8L
        api.listResponse = listing("", emptyList(), emptyList())
        assertTrue("another client signed in: its listing replaces the old one", sync.syncIfRevisionChanged(""))
        assertTrue(db.proxyNodeDao().getHosted().isEmpty())
    }

    @Test
    fun `upload links the local row and a repeat upload of a hosted node removes the copy`() = runBlocking {
        val localId = db.proxyNodeDao().insert(ProxyUriParser.parse(socksUri)!!.copy(subscriptionId = 3L))
        api.createResponse = { request ->
            assertTrue(request.uri.startsWith("socks5://alice:wonder@198.51.100.7:1080"))
            listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList()).copy(nodeId = 11, created = true)
        }

        val uploaded = sync.upload(db.proxyNodeDao().getById(localId)!!)

        assertEquals(localId, uploaded.id)
        assertEquals(11L, uploaded.hostedId)
        assertNull("uploaded subscription node is detached", uploaded.subscriptionId)
        assertEquals(1, db.proxyNodeDao().getAllList().size)

        val copyId = db.proxyNodeDao().insert(ProxyUriParser.parse("socks5://alice:wonder@198.51.100.7:1080#Copy")!!)
        api.createResponse = { listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList()).copy(nodeId = 11, created = false) }
        sync.upload(db.proxyNodeDao().getById(copyId)!!)
        val rows = db.proxyNodeDao().getAllList()
        assertEquals("never two rows for one hosted node", 1, rows.size)
        assertEquals(localId, rows.single().id)
    }

    @Test
    fun `sync all uploads every supported local node in one batch`() = runBlocking {
        val socksId = db.proxyNodeDao().insert(ProxyUriParser.parse(socksUri)!!)
        val ssId = db.proxyNodeDao().insert(ProxyUriParser.parse(ssUri)!!)
        db.proxyNodeDao().insert(ProxyNode(name = "wg", type = "wireguard", originalServer = "wg.example.com", originalPort = 51820, configJson = "{}"))
        db.proxyNodeDao().insert(ProxyNode(name = "vm", type = "vmess", originalServer = "vm.example.com", originalPort = 443, configJson = "{}"))
        api.batchResponse = { request ->
            assertEquals(2, request.nodes.size)
            listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), emptyList()).copy(
                results = listOf(
                    HostedNodeBatchResult(ref = socksId.toString(), ok = true, nodeId = 11, created = true),
                    HostedNodeBatchResult(ref = ssId.toString(), ok = true, nodeId = 12, created = false),
                ),
            )
        }

        val result = sync.uploadAllLocal()

        assertEquals(HostedBulkUploadResult(uploaded = 1, alreadyHosted = 1, unsupported = 2, failed = 0), result)
        assertEquals(11L, db.proxyNodeDao().getById(socksId)!!.hostedId)
        assertEquals(12L, db.proxyNodeDao().getById(ssId)!!.hostedId)
        assertEquals(4, db.proxyNodeDao().getAllList().size)
    }

    @Test
    fun `edits and deletes are write-through and a refusal leaves the device copy unchanged`() = runBlocking {
        sync.apply(listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList()))
        val hosted = db.proxyNodeDao().getHosted().single()

        api.updateResponse = { _, _ -> Response.error(422, """{"ok":false,"error":"hosted_node_invalid","field":"server"}""".toResponseBody("application/json".toMediaType())) }
        try {
            sync.update(hosted.copy(originalServer = "127.0.0.1"))
            fail("expected a refusal")
        } catch (e: HostedNodeException) {
            assertEquals("hosted_node_invalid", e.code)
            assertEquals("server", e.field)
        }
        assertEquals("198.51.100.7", db.proxyNodeDao().getById(hosted.id)!!.originalServer)

        api.updateResponse = { id, request ->
            assertEquals(11L, id)
            Response.success(listing("rev2", listOf(dto(11, request.uri, request.name ?: "")), emptyList()))
        }
        sync.update(hosted.copy(name = "Edited", originalServer = "203.0.113.4"))
        assertEquals("203.0.113.4", db.proxyNodeDao().getById(hosted.id)!!.originalServer)
        assertEquals("Edited", db.proxyNodeDao().getById(hosted.id)!!.name)

        api.deleteResponse = { listing("", emptyList(), emptyList()) }
        sync.delete(db.proxyNodeDao().getById(hosted.id)!!)
        assertTrue(db.proxyNodeDao().getAllList().isEmpty())
    }

    @Test
    fun `picking a hosted node pairs it on the panel and a local pick stays on the device`() = runBlocking {
        val localId = db.proxyNodeDao().insert(ProxyUriParser.parse("socks5://local.example.com:1080")!!)
        sync.apply(listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList()))
        val hostedId = db.proxyNodeDao().getHosted().single().id
        api.pairResponse = { forwardId, request ->
            assertEquals(101L, forwardId)
            assertEquals(11L, request.nodeId)
            listing("rev2", listOf(dto(11, socksUri, "Home")), listOf(pairing(101, 11)))
        }

        assertTrue(sync.pushPairing(101, hostedId))
        assertEquals(mapOf(101L to hostedId), store.pairings.value)
        assertFalse("already paired: no call", sync.pushPairing(101, hostedId))
        assertFalse("local-only node: no panel call", sync.pushPairing(102, localId))
        assertEquals(1, api.pairCalls)
    }

    private fun dto(id: Long, uri: String, name: String) = HostedNodeDto(id = id, name = name, uri = uri, version = 1)

    private fun pairing(forwardId: Long, nodeId: Long) = HostedNodePairingDto(forwardId = forwardId, nodeId = nodeId, source = "explicit")

    private fun listing(revision: String, nodes: List<HostedNodeDto>, pairings: List<HostedNodePairingDto>) =
        HostedNodesResponse(ok = true, revision = revision, nodes = nodes, pairings = pairings)

    private class FakeHostedNodeApi : HostedNodeApi {
        var listResponse = HostedNodesResponse(ok = true, revision = "", nodes = emptyList(), pairings = emptyList())
        var listCalls = 0
        var pairCalls = 0
        var createResponse: (HostedNodeUploadRequest) -> HostedNodesResponse = { error("create not expected") }
        var batchResponse: (HostedNodeBatchRequest) -> HostedNodesResponse = { error("batch not expected") }
        var updateResponse: (Long, HostedNodeUpdateRequest) -> Response<HostedNodesResponse> = { _, _ -> error("update not expected") }
        var deleteResponse: (Long) -> HostedNodesResponse = { error("delete not expected") }
        var pairResponse: (Long, HostedNodePairingRequest) -> HostedNodesResponse = { _, _ -> error("pair not expected") }

        override suspend fun list(auth: String): Response<HostedNodesResponse> {
            assertEquals("Bearer agent-token", auth)
            listCalls++
            return Response.success(listResponse)
        }

        override suspend fun create(auth: String, request: HostedNodeUploadRequest) = Response.success(createResponse(request))

        override suspend fun batchCreate(auth: String, request: HostedNodeBatchRequest) = Response.success(batchResponse(request))

        override suspend fun update(auth: String, nodeId: Long, request: HostedNodeUpdateRequest) = updateResponse(nodeId, request)

        override suspend fun delete(auth: String, nodeId: Long) = Response.success(deleteResponse(nodeId))

        override suspend fun pair(auth: String, forwardId: Long, request: HostedNodePairingRequest): Response<HostedNodesResponse> {
            pairCalls++
            return Response.success(pairResponse(forwardId, request))
        }
    }
}
