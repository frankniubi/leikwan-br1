package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import java.security.MessageDigest

/** Memory-only bootstrap material. Never log or persist its token in applied state. */
class ExternalUotGrant private constructor(private val payload: JsonObject) {
    fun toBootstrapJson(): JsonObject = payload.deepCopy()
    val fingerprint: String get() = MessageDigest.getInstance("SHA-256")
        .digest(payload.toString().toByteArray(Charsets.UTF_8))
        .joinToString("") { "%02x".format(it.toInt() and 255) }
    override fun toString(): String = "ExternalUotGrant([REDACTED])"

    companion object {
        /** Absence/explicit disable is native UDP. Enabled malformed grants must fail closed. */
        fun from(raw: JsonObject): ExternalUotGrant {
            require(raw.get("enabled")?.toString() == "true") { "external_uot_invalid" }
            fun text(key: String): String = raw.get(key)?.takeIf {
                it.isJsonPrimitive && it.asJsonPrimitive.isString
            }?.asString?.trim().orEmpty()
            val target = text("sidecar_target")
            val token = text("token")
            val split = Regex("^(\\[[0-9a-fA-F:]+]|[A-Za-z0-9._-]+):([0-9]{1,5})$").matchEntire(target)
            require(target.length <= 512 && split != null &&
                (split.groupValues[2].toIntOrNull() ?: 0) in 1..65535 &&
                token.isNotEmpty() && token.toByteArray(Charsets.UTF_8).size <= 8192 &&
                token.none { it.isWhitespace() }) { "external_uot_invalid" }
            val mode = text("delivery_mode").ifEmpty { "raw" }
            require(mode in setOf("raw", "reliable_datagram_v1")) { "external_uot_delivery_mode_invalid" }
            val payload = JsonObject().apply {
                addProperty("enabled", true)
                addProperty("sidecar_target", target)
                addProperty("token", token)
                for ((key, bounds) in mapOf(
                    "queue_packets" to 1..4096, "keepalive_seconds" to 5..30,
                    "reconnect_grace_seconds" to 5..300, "frame_payload_max" to 256..65507,
                )) {
                    val number = raw.get(key)?.toString()?.toIntOrNull() ?: 0
                    addProperty(key, if (number in bounds) number else 0)
                }
                if (mode != "raw") addProperty("delivery_mode", mode)
            }
            return ExternalUotGrant(payload)
        }
    }
}
