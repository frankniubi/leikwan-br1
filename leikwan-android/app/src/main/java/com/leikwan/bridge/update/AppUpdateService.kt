package com.leikwan.bridge.update

import android.content.Context
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.net.AppDns
import com.leikwan.bridge.net.MirrorNoSni
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.io.IOException
import java.net.URI
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

sealed interface AppUpdateCheckResult {
    data class Available(val manifest: AppUpdateManifest) : AppUpdateCheckResult
    data object UpToDate : AppUpdateCheckResult
    data class Failure(val message: String) : AppUpdateCheckResult
}

sealed interface AppUpdateDownloadResult {
    data class Success(val file: File) : AppUpdateDownloadResult
    data class Failure(val message: String) : AppUpdateDownloadResult
}

interface AppUpdateSource {
    suspend fun checkForUpdate(): AppUpdateCheckResult

    suspend fun download(
        manifest: AppUpdateManifest,
        onProgress: (downloadedBytes: Long, totalBytes: Long) -> Unit = { _, _ -> },
    ): AppUpdateDownloadResult
}

object AppUpdateArtifactValidator {
    fun error(
        manifest: AppUpdateManifest,
        packageName: String,
        versionCode: Long,
        versionName: String,
    ): String? {
        if (packageName != BuildConfig.APPLICATION_ID) return "update_package_mismatch"
        if (versionCode != manifest.versionCode) return "update_version_code_mismatch"
        if (versionName != manifest.versionName) return "update_version_name_mismatch"
        return null
    }
}

class AppUpdateService(
    private val context: Context,
    private val panelUrl: String = BuildConfig.PANEL_URL,
    private val client: OkHttpClient = defaultUpdateClient(panelUrl),
    private val currentVersionCode: Long = BuildConfig.VERSION_CODE.toLong(),
    private val allowInsecureHttp: Boolean = false,
) : AppUpdateSource {
    override suspend fun checkForUpdate(): AppUpdateCheckResult = withContext(Dispatchers.IO) {
        try {
            val request = Request.Builder()
                .url(resolveUrl("/artifacts/versions.json"))
                .header("Cache-Control", "no-cache")
                .get()
                .build()
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) {
                    return@withContext AppUpdateCheckResult.Failure(
                        "Update check failed: HTTP ${response.code}",
                    )
                }
                val manifest = AppUpdateManifest.parse(response.body?.string().orEmpty())
                    ?: return@withContext AppUpdateCheckResult.Failure("Update manifest is invalid")
                if (AppUpdateVersionPolicy.isUpdateAvailable(currentVersionCode, manifest.versionCode)) {
                    AppUpdateCheckResult.Available(manifest)
                } else {
                    AppUpdateCheckResult.UpToDate
                }
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: IOException) {
            AppUpdateCheckResult.Failure(e.message ?: "Update check failed")
        } catch (e: IllegalArgumentException) {
            AppUpdateCheckResult.Failure(e.message ?: "Update check failed")
        }
    }

    override suspend fun download(
        manifest: AppUpdateManifest,
        onProgress: (downloadedBytes: Long, totalBytes: Long) -> Unit,
    ): AppUpdateDownloadResult = withContext(Dispatchers.IO) {
        val updatesDir = File(context.filesDir, "updates")
        val temp = File(updatesDir, ".update-${manifest.versionCode}.apk.part")
        val target = File(updatesDir, "update-${manifest.versionCode}.apk")
        try {
            if (!updatesDir.exists() && !updatesDir.mkdirs()) {
                return@withContext AppUpdateDownloadResult.Failure("Unable to create update directory")
            }
            temp.delete()
            val request = Request.Builder()
                .url(resolveUrl(manifest.artifactUrl))
                .header("Cache-Control", "no-cache")
                .get()
                .build()
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) {
                    return@withContext AppUpdateDownloadResult.Failure(
                        "Update download failed: HTTP ${response.code}",
                    )
                }
                val body = response.body
                    ?: return@withContext AppUpdateDownloadResult.Failure("Update download was empty")
                val digest = MessageDigest.getInstance("SHA-256")
                var downloaded = 0L
                body.byteStream().use { input ->
                    temp.outputStream().use { output ->
                        val buffer = ByteArray(DOWNLOAD_BUFFER_BYTES)
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            downloaded += read
                            if (downloaded > manifest.sizeBytes) {
                                temp.delete()
                                return@withContext AppUpdateDownloadResult.Failure(
                                    "Update download exceeded declared size",
                                )
                            }
                            digest.update(buffer, 0, read)
                            output.write(buffer, 0, read)
                            onProgress(downloaded, manifest.sizeBytes)
                        }
                    }
                }

                if (downloaded != manifest.sizeBytes) {
                    temp.delete()
                    return@withContext AppUpdateDownloadResult.Failure(
                        "Update size mismatch",
                    )
                }
                val actualSha = digest.digest().toHex()
                if (actualSha != manifest.sha256) {
                    temp.delete()
                    return@withContext AppUpdateDownloadResult.Failure(
                        "Update checksum mismatch",
                    )
                }
            }

            if (target.exists() && !target.delete()) {
                temp.delete()
                return@withContext AppUpdateDownloadResult.Failure("Unable to replace cached update")
            }
            if (!temp.renameTo(target)) {
                temp.delete()
                return@withContext AppUpdateDownloadResult.Failure("Unable to finalize update download")
            }
            AppUpdateDownloadResult.Success(target)
        } catch (e: CancellationException) {
            temp.delete()
            throw e
        } catch (e: IOException) {
            temp.delete()
            AppUpdateDownloadResult.Failure(e.message ?: "Update download failed")
        } catch (e: IllegalArgumentException) {
            temp.delete()
            AppUpdateDownloadResult.Failure(e.message ?: "Update download failed")
        }
    }

    private fun resolveUrl(path: String): String {
        val base = panelUrl.trimEnd('/')
        val baseUri = URI(base)
        val scheme = baseUri.scheme?.lowercase()
        val host = baseUri.host?.lowercase()
        val localTestHost = host == "localhost" || host == "127.0.0.1" || host == "::1"
        require(scheme == "https" || allowInsecureHttp || (BuildConfig.DEBUG && localTestHost)) {
            "Update endpoint must use HTTPS"
        }
        require(path.startsWith("/artifacts/") && !path.startsWith("//") && !path.contains("..")) {
            "Update artifact path is invalid"
        }
        return base + path
    }

    private fun ByteArray.toHex(): String = joinToString("") { "%02x".format(it.toInt() and 0xff) }

    companion object {
        private const val DOWNLOAD_BUFFER_BYTES = 64 * 1024

        private fun defaultUpdateClient(panelUrl: String): OkHttpClient {
            val host = runCatching { URI(panelUrl).host }.getOrNull()
            val builder = OkHttpClient.Builder()
                .dns(AppDns.strict())
                .callTimeout(30, TimeUnit.SECONDS)
                .connectTimeout(10, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
            if (MirrorNoSni.shouldRewrite(host)) {
                MirrorNoSni.applyTo(builder)
            }
            return builder.build()
        }
    }
}
