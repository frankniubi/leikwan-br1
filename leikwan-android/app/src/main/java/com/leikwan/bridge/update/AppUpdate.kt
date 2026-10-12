package com.leikwan.bridge.update

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import android.util.Log
import androidx.core.content.FileProvider
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.net.AppDns
import com.leikwan.bridge.net.MirrorNoSni
import com.leikwan.bridge.net.PanelNetwork
import com.leikwan.bridge.net.PanelNetworkFallbackInterceptor
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.io.IOException
import java.net.URI
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

private const val TAG = "AppUpdate"

/** The `android_client_apk` entry of the panel's `artifacts/versions.json`. */
data class AppUpdateInfo(
    val versionName: String,
    val versionCode: Int,
    val sha256: String,
    val sizeBytes: Long,
    /** Absolute download URL on the panel origin the device signed in through. */
    val downloadUrl: String,
)

object AppUpdateManifest {
    /** A 74 MB APK is expected; refuse anything implausible before downloading it. */
    private const val MAX_APK_BYTES = 512L * 1024 * 1024

    /** Parses the published Android entry, or null when the manifest has none we can trust. */
    fun parse(manifestJson: String, panelUrl: String): AppUpdateInfo? {
        val entry = runCatching {
            JsonParser.parseString(manifestJson).asJsonObject.getAsJsonObject("android_client_apk")
        }.getOrNull() ?: return null
        val versionName = entry.string("version") ?: return null
        val versionCode = entry.get("version_code")?.takeIf { it.isJsonPrimitive }?.asInt ?: return null
        val sha256 = entry.string("sha256")?.lowercase()?.takeIf { it.matches(Regex("^[a-f0-9]{64}$")) }
            ?: return null
        val size = entry.get("size_bytes")?.takeIf { it.isJsonPrimitive }?.asLong ?: return null
        if (versionCode <= 0 || size !in 1..MAX_APK_BYTES) return null
        val path = entry.string("artifact_url") ?: return null
        val downloadUrl = resolveOnPanel(panelUrl, path) ?: return null
        return AppUpdateInfo(versionName, versionCode, sha256, size, downloadUrl)
    }

    /** True when [info] is newer than the running build. */
    fun isNewer(info: AppUpdateInfo, installedVersionCode: Int = BuildConfig.VERSION_CODE): Boolean =
        info.versionCode > installedVersionCode

    /** Only same-origin relative paths: the manifest must never redirect the download elsewhere. */
    internal fun resolveOnPanel(panelUrl: String, path: String): String? {
        if (!path.startsWith("/") || path.startsWith("//")) return null
        val origin = runCatching { URI(panelUrl.trim()) }.getOrNull() ?: return null
        if (origin.scheme != "https" || origin.host.isNullOrBlank()) return null
        return "https://${origin.rawAuthority}$path"
    }

    private fun JsonObject.string(key: String): String? =
        get(key)?.takeIf { it.isJsonPrimitive }?.asString?.trim()?.takeIf { it.isNotEmpty() }
}

sealed interface AppUpdateState {
    data object Idle : AppUpdateState
    data object Checking : AppUpdateState
    data object UpToDate : AppUpdateState
    data class Available(val info: AppUpdateInfo) : AppUpdateState
    data class Downloading(val info: AppUpdateInfo, val progress: Float) : AppUpdateState
    data class ReadyToInstall(val info: AppUpdateInfo, val apk: File) : AppUpdateState
    data class Failed(val reason: String, val info: AppUpdateInfo? = null) : AppUpdateState
}

/**
 * Checks the panel's published Android build and installs it through the system installer.
 * The APK is fetched over the same panel client as the control plane (strict DoH, physical
 * network), and is installed only when its size and SHA-256 match the manifest.
 */
object AppUpdateController {
    /** Automatic checks run at most this often; the Settings button always checks. */
    private const val AUTO_CHECK_INTERVAL_MS = 6L * 60 * 60 * 1000

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val mutableState = MutableStateFlow<AppUpdateState>(AppUpdateState.Idle)
    val state: StateFlow<AppUpdateState> = mutableState.asStateFlow()

    @Volatile private var lastCheckAtMs = 0L
    private var job: Job? = null

    fun checkIfDue(context: Context) {
        if (System.currentTimeMillis() - lastCheckAtMs < AUTO_CHECK_INTERVAL_MS) return
        check(context, userInitiated = false)
    }

    fun check(context: Context, userInitiated: Boolean = true) {
        val appContext = context.applicationContext
        synchronized(this) {
            if (job?.isActive == true) return
            val current = mutableState.value
            // A finished download stays installable; do not throw it away on a re-check.
            if (current is AppUpdateState.ReadyToInstall && current.apk.isFile) return
            job = scope.launch {
                lastCheckAtMs = System.currentTimeMillis()
                if (userInitiated || current !is AppUpdateState.Available) {
                    mutableState.value = AppUpdateState.Checking
                }
                mutableState.value = try {
                    val info = fetchManifest(appContext)
                    if (info != null && AppUpdateManifest.isNewer(info)) {
                        AppUpdateState.Available(info)
                    } else {
                        AppUpdateState.UpToDate
                    }
                } catch (error: Exception) {
                    Log.w(TAG, "Update check failed", error)
                    if (userInitiated) AppUpdateState.Failed(error.message ?: "update_check_failed") else AppUpdateState.Idle
                }
            }
        }
    }

    /** Downloads (if needed) and hands the verified APK to the system installer. */
    fun downloadAndInstall(context: Context) {
        val appContext = context.applicationContext
        synchronized(this) {
            if (job?.isActive == true) return
            when (val current = mutableState.value) {
                is AppUpdateState.ReadyToInstall -> {
                    if (current.apk.isFile) {
                        launchInstaller(appContext, current.apk)
                        return
                    }
                    startDownload(appContext, current.info)
                }
                is AppUpdateState.Available -> startDownload(appContext, current.info)
                is AppUpdateState.Failed -> current.info?.let { startDownload(appContext, it) }
                else -> Unit
            }
        }
    }

    private fun startDownload(context: Context, info: AppUpdateInfo) {
        job = scope.launch {
            mutableState.value = AppUpdateState.Downloading(info, 0f)
            mutableState.value = try {
                val apk = download(context, info) { progress ->
                    mutableState.value = AppUpdateState.Downloading(info, progress)
                }
                AppUpdateState.ReadyToInstall(info, apk)
            } catch (error: Exception) {
                Log.w(TAG, "Update download failed", error)
                AppUpdateState.Failed(error.message ?: "update_download_failed", info)
            }
            (mutableState.value as? AppUpdateState.ReadyToInstall)?.let { ready ->
                withContext(Dispatchers.Main) { launchInstaller(context, ready.apk) }
            }
        }
    }

    private fun fetchManifest(context: Context): AppUpdateInfo? {
        val panelUrl = Prefs.get(context).panelUrl
        val request = Request.Builder()
            .url(panelUrl.trimEnd('/') + "/artifacts/versions.json")
            .header("Cache-Control", "no-cache")
            .build()
        client(context, panelUrl, downloading = false).newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("update_manifest_http_${response.code}")
            val body = response.body?.string() ?: throw IOException("update_manifest_empty")
            return AppUpdateManifest.parse(body, panelUrl)
        }
    }

    private fun download(context: Context, info: AppUpdateInfo, onProgress: (Float) -> Unit): File {
        val directory = File(context.cacheDir, "updates").apply { mkdirs() }
        val target = File(directory, "leikwan-${info.versionCode}.apk")
        val partial = File(directory, "leikwan-${info.versionCode}.apk.part")
        // Keep only this build's partial download, which a retry resumes.
        directory.listFiles()?.forEach { if (it != partial) it.delete() }
        if (partial.length() > info.sizeBytes) partial.delete()

        val digest = MessageDigest.getInstance("SHA-256")
        var received = partial.length()
        if (received > 0) {
            partial.inputStream().use { input ->
                val buffer = ByteArray(64 * 1024)
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    digest.update(buffer, 0, read)
                }
            }
        }
        val request = Request.Builder().url(info.downloadUrl).apply {
            if (received > 0) header("Range", "bytes=$received-")
        }.build()
        client(context, Prefs.get(context).panelUrl, downloading = true).newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("update_download_http_${response.code}")
            if (received > 0 && response.code != 206) {
                // The server ignored the range: start over from byte zero.
                received = 0
                digest.reset()
            }
            val body = response.body ?: throw IOException("update_download_empty")
            var lastReported = -1
            body.byteStream().use { input ->
                java.io.FileOutputStream(partial, received > 0).use { output ->
                    val buffer = ByteArray(64 * 1024)
                    while (true) {
                        val read = input.read(buffer)
                        if (read < 0) break
                        received += read
                        if (received > info.sizeBytes) {
                            partial.delete()
                            throw IOException("update_size_mismatch")
                        }
                        digest.update(buffer, 0, read)
                        output.write(buffer, 0, read)
                        val percent = (received * 100 / info.sizeBytes).toInt()
                        if (percent != lastReported) {
                            lastReported = percent
                            onProgress(percent / 100f)
                        }
                    }
                }
            }
        }
        if (received != info.sizeBytes) throw IOException("update_download_incomplete")
        val actual = digest.digest().joinToString("") { "%02x".format(it) }
        if (actual != info.sha256) {
            partial.delete()
            throw IOException("update_checksum_mismatch")
        }
        if (!partial.renameTo(target)) throw IOException("update_store_failed")
        return target
    }

    private fun client(context: Context, panelUrl: String, downloading: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .dns(AppDns.strict())
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(if (downloading) 60 else 15, TimeUnit.SECONDS)
            // A download on a slow mobile link may take long; only a stalled read fails it.
            .callTimeout(if (downloading) 0L else 1L, TimeUnit.MINUTES)
        val host = runCatching { URI(panelUrl).host }.getOrNull()
        if (MirrorNoSni.shouldRewrite(host)) MirrorNoSni.applyTo(builder)
        val binder = PanelNetwork.ensureInstalled(context)
        PanelNetwork.applyTo(builder, binder)
        if (binder != null) builder.addInterceptor(PanelNetworkFallbackInterceptor { binder })
        return builder.build()
    }

    /** Opens the system installer, first sending the user to allow installs when needed. */
    fun launchInstaller(context: Context, apk: File) {
        val packageManager = context.packageManager
        if (!packageManager.canRequestPackageInstalls()) {
            val settings = Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:${context.packageName}"),
            ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            runCatching { context.startActivity(settings) }
                .onFailure { Log.w(TAG, "Cannot open install-permission settings", it) }
            return
        }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.updates", apk)
        val install = Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        try {
            context.startActivity(install)
        } catch (error: ActivityNotFoundException) {
            Log.w(TAG, "No package installer available", error)
            mutableState.value = AppUpdateState.Failed("update_installer_unavailable")
        }
    }
}
