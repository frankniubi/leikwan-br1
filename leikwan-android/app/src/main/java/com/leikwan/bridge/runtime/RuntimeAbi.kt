package com.leikwan.bridge.runtime

import java.util.Locale

/** ABI metadata shared by runtime lookup and native artifact validation. */
data class RuntimeAbi(
    val directoryName: String,
    val goArch: String,
    val elfMachine: Int,
) {
    companion object {
        private val arm64 = RuntimeAbi("arm64-v8a", "arm64", 183)
        private val x86_64 = RuntimeAbi("x86_64", "amd64", 62)

        fun fromSupportedAbis(supportedAbis: List<String>): RuntimeAbi? {
            supportedAbis.forEach { value ->
                when (value.trim().lowercase(Locale.ROOT)) {
                    "arm64-v8a" -> return arm64
                    "x86_64" -> return x86_64
                }
            }
            return null
        }
    }
}
