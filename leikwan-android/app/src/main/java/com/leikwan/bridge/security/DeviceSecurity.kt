package com.leikwan.bridge.security

import android.content.Context
import android.content.pm.ApplicationInfo
import android.os.Build
import java.io.File

data class DeviceSignals(
    val supportedAbis: List<String>,
    val fingerprint: String,
    val model: String,
    val manufacturer: String,
    val brand: String,
    val device: String,
    val product: String,
    val hardware: String,
    val buildTags: String,
    val kernelQemu: Boolean,
    val roDebuggable: Boolean,
    val appDebuggable: Boolean,
    val installedRootPackages: Set<String>,
    val executableSuPaths: Set<String>,
    val roSecure: Boolean = true,
)

sealed interface DeviceSecurityResult {
    data object Allowed : DeviceSecurityResult
    data object Rooted : DeviceSecurityResult
    data object Emulator : DeviceSecurityResult
    data object UnsupportedAbi : DeviceSecurityResult
    data object Debuggable : DeviceSecurityResult
}

object DeviceSecurity {
    private val rootPackages = setOf(
        "com.topjohnwu.magisk",
        "eu.chainfire.supersu",
        "com.noshufou.android.su",
        "com.koushikdutta.superuser",
        "com.kingroot.kinguser",
    )

    private val suPaths = setOf(
        "/system/bin/su",
        "/system/xbin/su",
        "/sbin/su",
        "/vendor/bin/su",
        "/system/sd/xbin/su",
        "/system/bin/failsafe/su",
        "/data/local/bin/su",
        "/data/local/xbin/su",
        "/data/local/su",
        "/su/bin/su",
    )

    private val emulatorMarkers = setOf(
        "generic",
        "unknown",
        "emulator",
        "sdk_gphone",
        "google_sdk",
        "android sdk built for",
        "goldfish",
        "ranchu",
        "vbox",
        "genymotion",
        "nox",
    )

    fun evaluate(signals: DeviceSignals): DeviceSecurityResult {
        if (signals.supportedAbis.none { it.equals("arm64-v8a", ignoreCase = true) }) {
            return DeviceSecurityResult.UnsupportedAbi
        }

        val buildIdentity = listOf(
            signals.fingerprint,
            signals.model,
            signals.manufacturer,
            signals.brand,
            signals.device,
            signals.product,
            signals.hardware,
        ).joinToString("|").lowercase()
        if (signals.kernelQemu || emulatorMarkers.any(buildIdentity::contains)) {
            return DeviceSecurityResult.Emulator
        }

        if (signals.appDebuggable) {
            return DeviceSecurityResult.Debuggable
        }

        if (signals.roDebuggable
            || !signals.roSecure
            || signals.buildTags.lowercase().contains("test-keys")
            || signals.installedRootPackages.isNotEmpty()
            || signals.executableSuPaths.isNotEmpty()
        ) {
            return DeviceSecurityResult.Rooted
        }

        return DeviceSecurityResult.Allowed
    }

    fun evaluate(context: Context): DeviceSecurityResult = evaluate(collect(context))

    fun collect(context: Context): DeviceSignals {
        val installedRootPackages = rootPackages.filterTo(mutableSetOf()) { packageName ->
            @Suppress("DEPRECATION")
            runCatching { context.packageManager.getPackageInfo(packageName, 0) }.isSuccess
        }
        val executableSuPaths = suPaths.filterTo(mutableSetOf()) { path ->
            val file = File(path)
            file.isFile && file.canExecute()
        }
        val applicationInfo = context.applicationInfo

        return DeviceSignals(
            supportedAbis = Build.SUPPORTED_ABIS?.toList().orEmpty(),
            fingerprint = Build.FINGERPRINT.orEmpty(),
            model = Build.MODEL.orEmpty(),
            manufacturer = Build.MANUFACTURER.orEmpty(),
            brand = Build.BRAND.orEmpty(),
            device = Build.DEVICE.orEmpty(),
            product = Build.PRODUCT.orEmpty(),
            hardware = Build.HARDWARE.orEmpty(),
            buildTags = Build.TAGS.orEmpty(),
            kernelQemu = systemProperty("ro.kernel.qemu") == "1",
            roDebuggable = systemProperty("ro.debuggable") == "1",
            appDebuggable = applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE != 0,
            installedRootPackages = installedRootPackages,
            executableSuPaths = executableSuPaths,
            roSecure = systemProperty("ro.secure").let { it.isBlank() || it == "1" },
        )
    }

    private fun systemProperty(name: String): String = runCatching {
        val type = Class.forName("android.os.SystemProperties")
        type.getMethod("get", String::class.java, String::class.java)
            .invoke(null, name, "") as String
    }.getOrDefault("")
}
