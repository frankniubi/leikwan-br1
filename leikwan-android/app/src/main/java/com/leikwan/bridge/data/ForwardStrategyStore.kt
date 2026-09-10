package com.leikwan.bridge.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKeys
import com.leikwan.bridge.runtime.ForwardStrategy
import java.io.File
import java.io.FileOutputStream
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.security.MessageDigest

class ForwardStrategyStore(
    private val preferences: SharedPreferences,
    panelId: String,
    clientId: Long,
    private val runtimeDirectory: File,
) {
    private val scope = "${panelHash(panelId)}:${clientId.coerceAtLeast(0)}"

    fun get(forwardId: Long): ForwardStrategy =
        ForwardStrategy.fromWireValue(preferences.getString(preferenceKey(forwardId), null))

    fun set(forwardId: Long, strategy: ForwardStrategy) {
        require(forwardId > 0) { "forwardId must be positive" }
        check(preferences.edit().putString(preferenceKey(forwardId), strategy.wireValue).commit()) {
            "failed to persist forward strategy"
        }
        writeRuntimeFile(runtimeFilePath(forwardId), strategy.wireValue)
    }

    fun runtimeFile(forwardId: Long): File {
        val path = runtimeFilePath(forwardId)
        if (!path.isFile || path.length() == 0L) {
            writeRuntimeFile(path, get(forwardId).wireValue)
        }
        return path
    }

    private fun preferenceKey(forwardId: Long): String {
        require(forwardId > 0) { "forwardId must be positive" }
        return "forward_strategy:$scope:$forwardId"
    }

    private fun runtimeFilePath(forwardId: Long): File =
        File(runtimeDirectory, "${scope.replace(':', '-')}-${forwardId}.strategy")

    private fun writeRuntimeFile(destination: File, value: String) {
        destination.parentFile?.mkdirs()
        val temporary = File(destination.parentFile, ".${destination.name}.${System.nanoTime()}.tmp")
        try {
            FileOutputStream(temporary).use { stream ->
                stream.write("$value\n".toByteArray(Charsets.UTF_8))
                stream.fd.sync()
            }
            Files.move(
                temporary.toPath(),
                destination.toPath(),
                StandardCopyOption.REPLACE_EXISTING,
                StandardCopyOption.ATOMIC_MOVE,
            )
        } finally {
            temporary.delete()
        }
    }

    companion object {
        fun from(context: Context, panelId: String, clientId: Long): ForwardStrategyStore {
            val shared = EncryptedSharedPreferences.create(
                "leikwan_forward_strategy_prefs",
                MasterKeys.getOrCreate(MasterKeys.AES256_GCM_SPEC),
                context.applicationContext,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
            return ForwardStrategyStore(
                shared,
                panelId,
                clientId,
                File(context.applicationContext.filesDir, "forward-strategies"),
            )
        }

        fun ensureRuntimeFile(
            context: Context,
            panelId: String,
            clientId: Long,
            forwardId: Long,
        ): File {
            require(forwardId > 0) { "forwardId must be positive" }
            val scope = "${panelHash(panelId)}-${clientId.coerceAtLeast(0)}"
            val destination = File(
                File(context.applicationContext.filesDir, "forward-strategies"),
                "$scope-$forwardId.strategy",
            )
            if (!destination.isFile || destination.length() == 0L) {
                destination.parentFile?.mkdirs()
                destination.writeText("${ForwardStrategy.LATENCY_BEST.wireValue}\n")
            }
            return destination
        }

        private fun panelHash(panelId: String): String =
            MessageDigest.getInstance("SHA-256")
                .digest(panelId.trim().lowercase().toByteArray(Charsets.UTF_8))
                .take(8)
                .joinToString("") { "%02x".format(it) }
    }
}
