package com.leikwan.bridge.security

import org.junit.Assert.assertEquals
import org.junit.Test

class DeviceSecurityTest {
    private val physicalArm64 = DeviceSignals(
        supportedAbis = listOf("arm64-v8a", "armeabi-v7a"),
        fingerprint = "google/oriole/oriole:14/AP2A.240805.005/1234567:user/release-keys",
        model = "Pixel 6",
        manufacturer = "Google",
        brand = "google",
        device = "oriole",
        product = "oriole",
        hardware = "oriole",
        buildTags = "release-keys",
        kernelQemu = false,
        roDebuggable = false,
        appDebuggable = false,
        installedRootPackages = emptySet(),
        executableSuPaths = emptySet(),
    )

    @Test
    fun `physical arm64 release device is allowed`() {
        assertEquals(DeviceSecurityResult.Allowed, DeviceSecurity.evaluate(physicalArm64))
    }

    @Test
    fun `su binary is rooted`() {
        assertEquals(
            DeviceSecurityResult.Rooted,
            DeviceSecurity.evaluate(physicalArm64.copy(executableSuPaths = setOf("/system/xbin/su"))),
        )
    }

    @Test
    fun `known root manager is rooted`() {
        assertEquals(
            DeviceSecurityResult.Rooted,
            DeviceSecurity.evaluate(physicalArm64.copy(installedRootPackages = setOf("com.topjohnwu.magisk"))),
        )
    }

    @Test
    fun `qemu fingerprint is emulator`() {
        assertEquals(
            DeviceSecurityResult.Emulator,
            DeviceSecurity.evaluate(
                physicalArm64.copy(
                    fingerprint = "google/sdk_gphone64_arm64/emu64a:14/UPB4/test-keys",
                    model = "sdk_gphone64_arm64",
                    hardware = "ranchu",
                    kernelQemu = true,
                ),
            ),
        )
    }

    @Test
    fun `x86 ABI is unsupported`() {
        assertEquals(
            DeviceSecurityResult.UnsupportedAbi,
            DeviceSecurity.evaluate(physicalArm64.copy(supportedAbis = listOf("x86_64", "x86"))),
        )
    }

    @Test
    fun `test keys and debuggable builds are rooted`() {
        assertEquals(
            DeviceSecurityResult.Rooted,
            DeviceSecurity.evaluate(physicalArm64.copy(buildTags = "test-keys")),
        )
        assertEquals(
            DeviceSecurityResult.Debuggable,
            DeviceSecurity.evaluate(physicalArm64.copy(appDebuggable = true)),
        )
        assertEquals(
            DeviceSecurityResult.Rooted,
            DeviceSecurity.evaluate(physicalArm64.copy(roDebuggable = true)),
        )
    }
}
