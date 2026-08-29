package com.leikwan.bridge.update

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import java.io.File
import java.security.MessageDigest

data class AppUpdateApkMetadata(
    val packageName: String,
    val versionCode: Long,
    val versionName: String,
)

interface AppUpdateInstallerPort {
    fun createInstallIntent(file: File, manifest: AppUpdateManifest): Result<Intent>

    fun canRequestPackageInstalls(): Boolean

    fun unknownSourcesSettingsIntent(): Intent
}

class AppUpdateInstaller(
    private val context: Context,
    apkInspectorOverride: ((File) -> AppUpdateApkMetadata?)? = null,
    uriProviderOverride: ((Context, File) -> Uri)? = null,
) : AppUpdateInstallerPort {
    private val apkInspector: (File) -> AppUpdateApkMetadata? =
        apkInspectorOverride ?: { file -> inspectApk(file) }
    private val uriProvider: (Context, File) -> Uri = uriProviderOverride ?: { owner, file ->
        FileProvider.getUriForFile(owner, "${owner.packageName}.fileprovider", file)
    }

    override fun createInstallIntent(file: File, manifest: AppUpdateManifest): Result<Intent> {
        if (!file.isFile) return failure("update_file_missing")
        if (file.length() != manifest.sizeBytes) return failure("update_file_size_mismatch")
        if (file.sha256() != manifest.sha256) return failure("update_file_checksum_mismatch")

        val metadata = apkInspector(file) ?: return failure("update_apk_invalid")
        AppUpdateArtifactValidator.error(
            manifest = manifest,
            packageName = metadata.packageName,
            versionCode = metadata.versionCode,
            versionName = metadata.versionName,
        )?.let { return failure(it) }

        return runCatching {
            val uri = uriProvider(context, file)
            Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, APK_MIME_TYPE)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
        }.recoverCatching { throwable ->
            throw IllegalStateException("update_install_intent_failed", throwable)
        }
    }

    override fun canRequestPackageInstalls(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()

    override fun unknownSourcesSettingsIntent(): Intent = Intent(
        Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
        Uri.parse("package:${context.packageName}"),
    )

    private fun inspectApk(file: File): AppUpdateApkMetadata? {
        val packageInfo = context.packageManager.getPackageArchiveInfo(
            file.absolutePath,
            PackageManager.GET_META_DATA,
        ) ?: return null
        val versionCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            packageInfo.longVersionCode
        } else {
            @Suppress("DEPRECATION")
            packageInfo.versionCode.toLong()
        }
        return AppUpdateApkMetadata(
            packageName = packageInfo.packageName,
            versionCode = versionCode,
            versionName = packageInfo.versionName.orEmpty(),
        )
    }

    private fun File.sha256(): String {
        val digest = MessageDigest.getInstance("SHA-256")
        inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read < 0) break
                digest.update(buffer, 0, read)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it.toInt() and 0xff) }
    }

    private fun failure(message: String): Result<Intent> =
        Result.failure(IllegalStateException(message))

    companion object {
        const val APK_MIME_TYPE = "application/vnd.android.package-archive"
    }
}
