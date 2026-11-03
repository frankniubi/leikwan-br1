package com.leikwan.bridge.update

import com.google.gson.Gson
import com.google.gson.JsonParser
import com.leikwan.bridge.api.PullConfigsResponse
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class ClientUpdatePolicyTest {
    @Before
    fun setUp() = ClientUpdatePolicy.resetForTest()

    @After
    fun tearDown() = ClientUpdatePolicy.resetForTest()

    @Test
    fun `a lower installed version requires the update`() {
        assertTrue(ClientVersion.isUpdateRequired("0.2.44", "0.2.45"))
        assertTrue(ClientVersion.isUpdateRequired("0.2.9", "0.2.10"))
        assertTrue(ClientVersion.isUpdateRequired("0.9.99", "1.0"))
        assertTrue(ClientVersion.isUpdateRequired("0.2", "0.2.1"))
    }

    @Test
    fun `an equal installed version is not required to update`() {
        assertFalse(ClientVersion.isUpdateRequired("0.2.45", "0.2.45"))
        assertFalse(ClientVersion.isUpdateRequired("0.2.45", "0.2.45.0"))
        assertFalse(ClientVersion.isUpdateRequired("0.2", "0.2.0"))
        assertFalse(ClientVersion.isUpdateRequired("0.2.45-debug", "0.2.45"))
    }

    @Test
    fun `a newer installed version is not required to update`() {
        assertFalse(ClientVersion.isUpdateRequired("0.2.46", "0.2.45"))
        assertFalse(ClientVersion.isUpdateRequired("0.2.10", "0.2.9"))
        assertFalse(ClientVersion.isUpdateRequired("1.0", "0.9.99"))
    }

    @Test
    fun `unparseable or missing versions fail open`() {
        assertFalse(ClientVersion.isUpdateRequired("0.2.44", ""))
        assertFalse(ClientVersion.isUpdateRequired("0.2.44", null))
        assertFalse(ClientVersion.isUpdateRequired("0.2.44", "latest"))
        assertFalse(ClientVersion.isUpdateRequired("0.2.44", "v0.2.45"))
        assertFalse(ClientVersion.isUpdateRequired("0.2.44", "99999999999.0"))
        assertFalse(ClientVersion.isUpdateRequired("dev", "0.2.45"))
        assertNull(ClientVersion.compare("0.2.44", ".1"))
    }

    @Test
    fun `suffixes after the numeric part are ignored`() {
        assertEquals(listOf(0, 2, 45), ClientVersion.parse("0.2.45-beta.3"))
        assertEquals(listOf(1, 0), ClientVersion.parse(" 1.0+build7 "))
        assertTrue(ClientVersion.isUpdateRequired("0.2.44", "0.2.45-rc1"))
    }

    @Test
    fun `client_update is parsed from a configs pull response`() {
        val pull = Gson().fromJson(
            """
            {
              "ok": true,
              "client": {"id": 7},
              "forwards": [],
              "client_update": {"minimum_version": "0.2.45"}
            }
            """.trimIndent(),
            PullConfigsResponse::class.java,
        )

        assertEquals("0.2.45", ClientUpdateDirective.minimumVersion(pull.clientUpdate))
    }

    @Test
    fun `a pull without a usable client_update carries no minimum`() {
        val gson = Gson()
        val absent = gson.fromJson("""{"ok":true,"client":{},"forwards":[]}""", PullConfigsResponse::class.java)
        assertNull(absent.clientUpdate)
        assertNull(ClientUpdateDirective.minimumVersion(absent.clientUpdate))
        listOf(
            """{"ok":true,"client":{},"forwards":[],"client_update":null}""",
            """{"ok":true,"client":{},"forwards":[],"client_update":{}}""",
            """{"ok":true,"client":{},"forwards":[],"client_update":{"minimum_version":""}}""",
            """{"ok":true,"client":{},"forwards":[],"client_update":{"minimum_version":"soon"}}""",
            """{"ok":true,"client":{},"forwards":[],"client_update":{"minimum_version":{"v":1}}}""",
            """{"ok":true,"client":{},"forwards":[],"client_update":"0.2.45"}""",
        ).forEach { json ->
            val pull = gson.fromJson(json, PullConfigsResponse::class.java)
            assertNull(json, ClientUpdateDirective.minimumVersion(pull.clientUpdate))
        }
    }

    @Test
    fun `the policy publishes the required minimum`() {
        assertTrue(ClientUpdatePolicy.applyFromPull(update("0.2.45"), currentVersion = "0.2.44"))
        assertEquals("0.2.45", ClientUpdatePolicy.requiredMinimumVersion.value)
    }

    @Test
    fun `a met minimum publishes nothing`() {
        assertFalse(ClientUpdatePolicy.applyFromPull(update("0.2.45"), currentVersion = "0.2.45"))
        assertNull(ClientUpdatePolicy.requiredMinimumVersion.value)
        assertFalse(ClientUpdatePolicy.applyFromPull(null, currentVersion = "0.2.45"))
        assertNull(ClientUpdatePolicy.requiredMinimumVersion.value)
    }

    @Test
    fun `a later pull without client_update does not clear the policy`() {
        assertTrue(ClientUpdatePolicy.applyFromPull(update("0.2.45"), currentVersion = "0.2.44"))

        assertTrue(ClientUpdatePolicy.applyFromPull(null, currentVersion = "0.2.44"))
        assertEquals("0.2.45", ClientUpdatePolicy.requiredMinimumVersion.value)

        assertTrue(ClientUpdatePolicy.applyFromPull(JsonParser.parseString("{}"), currentVersion = "0.2.44"))
        assertTrue(ClientUpdatePolicy.applyFromPull(update("garbage"), currentVersion = "0.2.44"))
        assertEquals("0.2.45", ClientUpdatePolicy.requiredMinimumVersion.value)
    }

    @Test
    fun `a newer parseable minimum replaces the remembered one`() {
        ClientUpdatePolicy.applyFromPull(update("0.2.45"), currentVersion = "0.2.44")
        ClientUpdatePolicy.applyFromPull(update("0.2.46"), currentVersion = "0.2.44")
        assertEquals("0.2.46", ClientUpdatePolicy.requiredMinimumVersion.value)

        // The panel lowered its minimum below this build: the gate lifts.
        assertFalse(ClientUpdatePolicy.applyFromPull(update("0.2.40"), currentVersion = "0.2.44"))
        assertNull(ClientUpdatePolicy.requiredMinimumVersion.value)
    }

    private fun update(minimum: String) =
        JsonParser.parseString("""{"minimum_version":"$minimum"}""")
}
