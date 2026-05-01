package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test
import java.io.File
import java.nio.file.Files

class RuntimeBinaryLocatorTest {

    @Test
    fun `locator prefers native library dir when packaged runtime exists`() {
        val root = Files.createTempDirectory("runtime-locator-native").toFile()
        val nativeDir = File(root, "lib").apply { mkdirs() }
        val runtimeDir = File(root, "files/runtime/arm64-v8a").apply { mkdirs() }
        File(nativeDir, "libleikwan_ingress.so").writeText("native")

        val resolved = RuntimeBinaryLocator.resolve(
            nativeLibraryDir = nativeDir,
            nativeBinaryName = "libleikwan_ingress.so",
            runtimeDir = runtimeDir,
            extractedName = "leikwan-ingress",
            assetAvailable = true,
        )

        assertEquals(File(nativeDir, "libleikwan_ingress.so").absolutePath, resolved.path)
        assertNull(resolved.extractionTarget)
    }

    @Test
    fun `locator falls back to extracted asset when native runtime is missing`() {
        val root = Files.createTempDirectory("runtime-locator-asset").toFile()
        val runtimeDir = File(root, "files/runtime/arm64-v8a").apply { mkdirs() }

        val resolved = RuntimeBinaryLocator.resolve(
            nativeLibraryDir = File(root, "missing-lib"),
            nativeBinaryName = "libwstunnel.so",
            runtimeDir = runtimeDir,
            extractedName = "wstunnel",
            assetAvailable = true,
        )

        assertEquals(File(runtimeDir, "wstunnel").absolutePath, resolved.path)
        assertNotNull(resolved.extractionTarget)
    }

    @Test
    fun `locator returns null when neither native nor asset runtime exists`() {
        val root = Files.createTempDirectory("runtime-locator-none").toFile()
        val runtimeDir = File(root, "files/runtime/arm64-v8a").apply { mkdirs() }

        val resolved = RuntimeBinaryLocator.resolve(
            nativeLibraryDir = File(root, "missing-lib"),
            nativeBinaryName = "libmissing.so",
            runtimeDir = runtimeDir,
            extractedName = "missing",
            assetAvailable = false,
        )

        assertNull(resolved.path)
        assertNull(resolved.extractionTarget)
    }
}
