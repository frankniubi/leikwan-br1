package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Test

class AndroidRuntimeJanitorTest {

    @Test
    fun `parser extracts stale runtime pids and skips current process`() {
        val psOutput = """
            u0_a595      15141     1    0 0 S libleikwan_ingress.so
            u0_a595      15157     1    0 0 S libleikwan_bond.so
            u0_a595      28559  1741    0 0 S com.leikwan.bridge
            u0_a595      31162 28559    0 0 S libleikwan_ingress.so
        """.trimIndent()

        val parsed = AndroidRuntimeJanitor.parseRuntimePids(
            psOutput = psOutput,
            currentPid = 31162,
        )

        assertEquals(listOf(15141, 15157), parsed)
    }
}
