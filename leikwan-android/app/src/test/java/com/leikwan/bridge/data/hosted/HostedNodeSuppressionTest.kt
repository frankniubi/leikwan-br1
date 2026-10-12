package com.leikwan.bridge.data.hosted

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.HostedNodeApi
import com.leikwan.bridge.api.HostedNodeBatchRequest
import com.leikwan.bridge.api.HostedNodeDto
import com.leikwan.bridge.api.HostedNodePairingDto
import com.leikwan.bridge.api.HostedNodePairingRequest
import com.leikwan.bridge.api.HostedNodeUpdateRequest
import com.leikwan.bridge.api.HostedNodeUploadRequest
import com.leikwan.bridge.api.HostedNodesResponse
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.proxy.ProxyUriParser
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

/** "Remove from this device only" for cloud nodes: a per-device suppression list keyed by server node id. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class HostedNodeSuppressionTest {
    private lateinit var context: Context
    private lateinit var db: AppDatabase
    private lateinit var store: HostedNodePairingStore
    private lateinit var api: FakeApi
    private lateinit var sync: HostedNodeSync
    private var clientId = 7L

    private val socksUri = "socks5://alice:wonder@198.51.100.7:1080#Home"
    private val ssUri = "ss://YWVzLTI1Ni1nY206cHc@ss.example.com:8388#SS"

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java).allowMainThreadQueries().build()
        val prefs = context.getSharedPreferences("hosted-node-suppression-test", Context.MODE_PRIVATE)
        prefs.edit().clear().commit()
        store = HostedNodePairingStore(prefs)
        api = FakeApi()
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

    private suspend fun hostedRow(hostedId: Long) = db.proxyNodeDao().getHosted().firstOrNull { it.hostedId == hostedId }

    @Test
    fun `removed node stays gone across syncs while the other device's copy is untouched`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), listOf(pairing(101, 11)))
        sync.refresh()
        val home = hostedRow(11)!!
        assertEquals(mapOf(101L to home.id), store.pairings.value)

        sync.removeFromThisDevice(home)

        assertNull(hostedRow(11))
        assertEquals(mapOf(11L to "Home"), store.suppressed.value)
        assertTrue("the pairing to the removed row is dropped", store.pairings.value.isEmpty())
        assertEquals("no panel write for a device-only removal", 0, api.writes)

        // Cloud -> local runs again, also after the node changed on the panel: it does not come back.
        api.listing = listing("rev2", listOf(dto(11, "socks5://alice:changed@198.51.100.7:1080#Home", "Home 2"), dto(12, ssUri, "SS")), listOf(pairing(101, 11)))
        assertTrue(sync.syncIfRevisionChanged("rev2"))
        assertNull(hostedRow(11))
        assertTrue(store.pairings.value.isEmpty())
        assertEquals("the restore list follows the cloud name", mapOf(11L to "Home 2"), store.suppressed.value)
        assertEquals("other cloud nodes still sync", "SS", hostedRow(12)!!.name)
    }

    @Test
    fun `a hidden node never adopts an equal local node`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)
        val localId = db.proxyNodeDao().insert(ProxyUriParser.parse("socks5://alice:wonder@198.51.100.7:1080#Mine")!!)

        sync.refresh()

        val local = db.proxyNodeDao().getById(localId)!!
        assertNull("the local copy stays local", local.hostedId)
        assertEquals(1, db.proxyNodeDao().getAllList().size)
    }

    @Test
    fun `restore brings the node back, and survives being offline`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), listOf(pairing(101, 11)))
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)
        sync.removeFromThisDevice(hostedRow(12)!!)

        sync.restoreHidden(listOf(11L))
        assertEquals("Home", hostedRow(11)!!.name)
        assertEquals(mapOf(101L to hostedRow(11)!!.id), store.pairings.value)
        assertEquals(mapOf(12L to "SS"), store.suppressed.value)

        api.offline = true
        try {
            sync.restoreHidden()
            fail("expected the fetch to fail")
        } catch (_: java.io.IOException) {
        }
        assertTrue("the suppression entry is gone even though the fetch failed", store.suppressed.value.isEmpty())
        api.offline = false
        assertTrue("the next pull refetches although the panel revision did not move", sync.syncIfRevisionChanged("rev1"))
        assertEquals("SS", hostedRow(12)!!.name)
    }

    @Test
    fun `delete everywhere from any side clears the suppression entry`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home"), dto(12, ssUri, "SS")), emptyList())
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)
        sync.removeFromThisDevice(hostedRow(12)!!)

        // Deleted on the console or by another device: the panel no longer lists it.
        api.listing = listing("rev2", listOf(dto(12, ssUri, "SS")), emptyList())
        sync.syncIfRevisionChanged("rev2")
        assertEquals(mapOf(12L to "SS"), store.suppressed.value)

        // Re-uploaded later (the panel issues a new id): it shows up again.
        api.listing = listing("rev3", listOf(dto(12, ssUri, "SS"), dto(13, socksUri, "Home")), emptyList())
        sync.syncIfRevisionChanged("rev3")
        assertEquals("Home", hostedRow(13)!!.name)

        // Deleted from this device after a restore: delete() clears the entry as well.
        sync.restoreHidden(listOf(12L))
        api.listing = listing("rev4", listOf(dto(13, socksUri, "Home")), emptyList())
        sync.delete(hostedRow(12)!!)
        assertTrue(store.suppressed.value.isEmpty())
        assertNull(hostedRow(12))
    }

    @Test
    fun `uploading the node again from this device lifts the suppression`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)
        val localId = db.proxyNodeDao().insert(ProxyUriParser.parse(socksUri)!!)
        api.createResult = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList()).copy(nodeId = 11, created = false)

        sync.upload(db.proxyNodeDao().getById(localId)!!)

        assertTrue(store.suppressed.value.isEmpty())
        assertEquals(localId, hostedRow(11)!!.id)
    }

    @Test
    fun `the suppression list belongs to one client`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)

        clientId = 8L
        api.listing = listing("other", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.syncIfRevisionChanged("other")
        assertEquals("another client's node 11 is not hidden by client 7's list", "Home", hostedRow(11)!!.name)
        assertTrue(store.suppressed.value.isEmpty())
    }

    @Test
    fun `the suppression list persists across store instances`() = runBlocking {
        api.listing = listing("rev1", listOf(dto(11, socksUri, "Home")), emptyList())
        sync.refresh()
        sync.removeFromThisDevice(hostedRow(11)!!)
        val reopened = HostedNodePairingStore(context.getSharedPreferences("hosted-node-suppression-test", Context.MODE_PRIVATE))
        assertEquals(mapOf(11L to "Home"), reopened.suppressed.value)
    }

    private fun dto(id: Long, uri: String, name: String) = HostedNodeDto(id = id, name = name, uri = uri, version = 1)

    private fun pairing(forwardId: Long, nodeId: Long) = HostedNodePairingDto(forwardId = forwardId, nodeId = nodeId, source = "explicit")

    private fun listing(revision: String, nodes: List<HostedNodeDto>, pairings: List<HostedNodePairingDto>) =
        HostedNodesResponse(ok = true, revision = revision, nodes = nodes, pairings = pairings)

    private class FakeApi : HostedNodeApi {
        var listing = HostedNodesResponse(ok = true, revision = "", nodes = emptyList(), pairings = emptyList())
        var createResult: HostedNodesResponse? = null
        var offline = false
        var writes = 0

        override suspend fun list(auth: String): Response<HostedNodesResponse> {
            if (offline) throw java.io.IOException("offline")
            return Response.success(listing)
        }

        override suspend fun create(auth: String, request: HostedNodeUploadRequest): Response<HostedNodesResponse> {
            writes++
            return Response.success(createResult ?: error("create not expected"))
        }

        override suspend fun batchCreate(auth: String, request: HostedNodeBatchRequest): Response<HostedNodesResponse> = error("unused")

        override suspend fun update(auth: String, nodeId: Long, request: HostedNodeUpdateRequest): Response<HostedNodesResponse> = error("unused")

        override suspend fun delete(auth: String, nodeId: Long): Response<HostedNodesResponse> {
            writes++
            return Response.success(listing)
        }

        override suspend fun pair(auth: String, forwardId: Long, request: HostedNodePairingRequest): Response<HostedNodesResponse> = error("unused")
    }
}
