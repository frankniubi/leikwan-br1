package com.leikwan.bridge.service

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Test
import java.util.ArrayDeque

class VpnStartupMonitorTest {

    @Test
    fun `monitor returns running when service starts`() = runTest {
        val states = ArrayDeque(
            listOf(
                VpnStartupSnapshot(VpnStartupState.STARTING, null),
                VpnStartupSnapshot(VpnStartupState.RUNNING, null),
            )
        )

        val result = VpnStartupMonitor.await(
            timeoutMs = 1000,
            pollMs = 1,
            readState = {
                if (states.isEmpty()) VpnStartupSnapshot(VpnStartupState.RUNNING, null)
                else states.removeFirst()
            },
            sleeper = {},
        )

        assertEquals(VpnStartupAwaitResult.Running, result)
    }

    @Test
    fun `monitor returns service error when startup fails`() = runTest {
        val states = ArrayDeque(
            listOf(
                VpnStartupSnapshot(VpnStartupState.STARTING, null),
                VpnStartupSnapshot(VpnStartupState.FAILED, "decode config failed"),
            )
        )

        val result = VpnStartupMonitor.await(
            timeoutMs = 1000,
            pollMs = 1,
            readState = {
                if (states.isEmpty()) VpnStartupSnapshot(VpnStartupState.FAILED, "decode config failed")
                else states.removeFirst()
            },
            sleeper = {},
        )

        assertEquals(VpnStartupAwaitResult.Failed("decode config failed"), result)
    }
}
