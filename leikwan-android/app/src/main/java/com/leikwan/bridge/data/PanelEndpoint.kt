package com.leikwan.bridge.data

/** Allowlisted panel origins presented in the login selector. */
enum class PanelEndpoint(
    val url: String,
    val storageKey: String,
) {
    CLOUDFLARE("https://v3.leikwanhost.com", "cloudflare"),
    ALIYUN_CDN("https://api-leikwanbridge.nbwly.com", "aliyun_cdn");

    companion object {
        val DEFAULT: PanelEndpoint = CLOUDFLARE

        fun fromStorage(value: String?): PanelEndpoint = entries.firstOrNull {
            it.storageKey.equals(value?.trim(), ignoreCase = true)
        } ?: DEFAULT
    }
}
