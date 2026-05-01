package com.leikwan.bridge.runtime

import java.io.File

data class RuntimeBinaryLocation(
    val path: String?,
    val extractionTarget: File?,
)

object RuntimeBinaryLocator {
    fun resolve(
        nativeLibraryDir: File?,
        nativeBinaryName: String,
        runtimeDir: File,
        extractedName: String,
        assetAvailable: Boolean,
    ): RuntimeBinaryLocation {
        val nativePath = nativeLibraryDir
            ?.let { File(it, nativeBinaryName) }
            ?.takeIf { it.isFile }
            ?.absolutePath
        if (nativePath != null) {
            return RuntimeBinaryLocation(
                path = nativePath,
                extractionTarget = null,
            )
        }

        if (!assetAvailable) {
            return RuntimeBinaryLocation(
                path = null,
                extractionTarget = null,
            )
        }

        val extractionTarget = File(runtimeDir, extractedName)
        return RuntimeBinaryLocation(
            path = extractionTarget.absolutePath,
            extractionTarget = extractionTarget,
        )
    }
}
