package com.leikwan.bridge.api

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LoginResponseTest {
    @Test
    fun `maps current panel session token and optional selected client fields`() {
        val response = Gson().fromJson(
            """
            {
              "ok": true,
              "session_token": "abc123",
              "user_id": 7,
              "username": "tester",
              "email": "tester@example.com",
              "clients": [
                {"id": 2, "name": "Client Two"},
                {"id": 1, "name": "Client One"}
              ]
            }
            """.trimIndent(),
            LoginResponse::class.java,
        )

        assertEquals("abc123", response.token)
        assertNull(response.clientId)
        assertNull(response.clientName)
        assertEquals(2, response.clients.size)
    }
}
