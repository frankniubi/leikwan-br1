package com.leikwan.bridge.runtime

import android.content.Context
import android.os.Build
import java.io.File

class RuntimeBinaryInstaller(private val context: Context) {

    fun prepareBinaryPaths(): RuntimeBinaryPaths {
        val abi = Build.SUPPORTED_ABIS.firstOrNull().orEmpty().ifBlank { "arm64-v8a" }
        val runtimeDir = File(context.filesDir, "runtime/$abi").apply { mkdirs() }
        val nativeLibraryDir = context.applicationInfo.nativeLibraryDir?.let(::File)

        return RuntimeBinaryPaths(
            wstunnelBinary = resolveBinaryPath(
                nativeLibraryDir = nativeLibraryDir,
                nativeBinaryName = "libwstunnel.so",
                assetPath = "runtime/$abi/wstunnel",
                runtimeDir = runtimeDir,
                extractedName = "wstunnel",
            ),
            ingressBinary = resolveBinaryPath(
                nativeLibraryDir = nativeLibraryDir,
                nativeBinaryName = "libleikwan_ingress.so",
                assetPath = "runtime/$abi/leikwan-ingress",
                runtimeDir = runtimeDir,
                extractedName = "leikwan-ingress",
            ),
            bondBinary = resolveBinaryPath(
                nativeLibraryDir = nativeLibraryDir,
                nativeBinaryName = "libleikwan_bond.so",
                assetPath = "runtime/$abi/leikwan-bond",
                runtimeDir = runtimeDir,
                extractedName = "leikwan-bond",
            ),
        )
    }

    private fun resolveBinaryPath(
        nativeLibraryDir: File?,
        nativeBinaryName: String,
        assetPath: String,
        runtimeDir: File,
        extractedName: String,
    ): String? {
        val location = RuntimeBinaryLocator.resolve(
            nativeLibraryDir = nativeLibraryDir,
            nativeBinaryName = nativeBinaryName,
            runtimeDir = runtimeDir,
            extractedName = extractedName,
            assetAvailable = assetExists(assetPath),
        )
        location.extractionTarget?.let { target ->
            return installAssetBinary(assetPath, target)
        }
        return location.path
    }

    private fun assetExists(assetPath: String): Boolean {
        val assets = context.assets
        return runCatching {
            assets.open(assetPath).close()
            true
        }.getOrDefault(false)
    }

    private fun installAssetBinary(assetPath: String, target: File): String? {
        val assets = context.assets
        assets.open(assetPath).use { input ->
            target.outputStream().use { output -> input.copyTo(output) }
        }
        target.setReadable(true, false)
        target.setExecutable(true, false)
        target.setWritable(true, true)
        return target.absolutePath
    }
}
