package com.leikwan.bridge.runtime

import android.content.Context
import android.os.Build
import android.system.Os
import android.system.OsConstants
import java.io.File

open class RuntimeBinaryInstaller(private val context: Context) {

    open fun prepareBinaryPaths(): RuntimeBinaryPaths {
        val abi = RuntimeAbi.fromSupportedAbis(Build.SUPPORTED_ABIS?.toList().orEmpty())
            ?.directoryName
            ?: Build.SUPPORTED_ABIS.firstOrNull().orEmpty().ifBlank { "arm64-v8a" }
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
            ultraBinary = resolveBinaryPath(
                nativeLibraryDir = nativeLibraryDir,
                nativeBinaryName = "libultra_client.so",
                assetPath = "runtime/$abi/ultra-client",
                runtimeDir = runtimeDir,
                extractedName = "ultra-client",
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
        Os.chmod(
            target.absolutePath,
            OsConstants.S_IRUSR or OsConstants.S_IWUSR or OsConstants.S_IXUSR,
        )
        return target.absolutePath
    }
}
