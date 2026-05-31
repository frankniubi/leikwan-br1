package com.leikwan.bridge.api

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Test

class ClientSelectionResponseTest {
    @Test
    fun `maps client selection agent token`() {
        val response = Gson().fromJson(
            """
            {
              "ok": true,
              "client_id": 2,
              "client_name": "Client Two",
              "agent_token": "agent-token"
            }
            """.trimIndent(),
            ClientSelectionResponse::class.java,
        )

        assertEquals("agent-token", response.agentToken)
        assertEquals(2L, response.clientId)
        assertEquals("Client Two", response.clientName)
    }
}
