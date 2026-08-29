package com.leikwan.bridge.update

import com.google.gson.JsonParser

data class AppUpdateManifest(
    val versionName: String,
    val versionCode: Long,
    val artifactUrl: String,
    val sha256: String,
    val sizeBytes: Long,
) {
    companion object {
        fun parse(json: String): AppUpdateManifest? = runCatching {
            val root = JsonParser.parseString(json).takeIf { it.isJsonObject }?.asJsonObject
                ?: return@runCatching null
            val artifact = root.getAsJsonObject("android_client_apk") ?: return@runCatching null
            val versionName = artifact.get("version")?.asString?.trim().orEmpty()
            val versionCode = artifact.get("version_code")?.asLong ?: return@runCatching null
            val artifactUrl = artifact.get("artifact_url")?.asString?.trim().orEmpty()
            val sha256 = artifact.get("sha256")?.asString?.trim()?.lowercase().orEmpty()
            val sizeBytes = artifact.get("size_bytes")?.asLong ?: return@runCatching null

            if (versionName.isEmpty()
                || versionCode <= 0L
                || !artifactUrl.startsWith("/artifacts/")
                || artifactUrl.startsWith("//")
                || artifactUrl.contains("..")
                || !sha256.matches(Regex("^[a-f0-9]{64}$"))
                || sizeBytes <= 0L
            ) {
                return@runCatching null
            }

            AppUpdateManifest(versionName, versionCode, artifactUrl, sha256, sizeBytes)
        }.getOrNull()
    }
}

object AppUpdateVersionPolicy {
    fun isUpdateAvailable(currentVersionCode: Long, remoteVersionCode: Long): Boolean =
        remoteVersionCode > currentVersionCode
}
