package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class RuntimeAbiTest {

    @Test
    fun `x86_64 is mapped to the emulator runtime directory`() {
        val abi = RuntimeAbi.fromSupportedAbis(listOf("x86_64", "x86"))

        assertEquals("x86_64", abi?.directoryName)
        assertEquals("amd64", abi?.goArch)
        assertEquals(62, abi?.elfMachine)
    }

    @Test
    fun `unsupported ABI has no runtime mapping`() {
        assertNull(RuntimeAbi.fromSupportedAbis(listOf("armeabi-v7a", "x86")))
    }
}
