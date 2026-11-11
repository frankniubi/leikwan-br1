package com.leikwan.bridge.shadowsocks

import java.security.MessageDigest
import java.util.Base64

/**
 * Normalize SS2022 PSK strings to the format sing-box clients accept (base64, decoded length
 * equals key length). Sing-box servers tolerate longer keys via SHA256 hash truncation; our
 * outbound configs do not. Xray accepts raw ASCII keys; we convert those to base64.
 *
 * Reasoning: sing-box server-side `shadowaead_2022` allows `len(psk) > keySaltLength` and
 * hashes via `Key(psk, keySaltLength)` (SHA256 truncated). The client-side library
 * `sing-shadowsocks2` rejects that with `bad key length`. Xray's `ParseKey` accepts either
 * base64 or raw bytes; sing-box clients only accept base64. Normalizing here to what the
 * strictest consumer (sing-box client) requires ensures maximum compatibility.
 */
object SS2022KeyNormalizer {
    data class NormalizeResult(
        val normalized: String,
        val error: String? = null,
    )

    /**
     * Normalize a single PSK segment. Returns a new base64 string or an error.
     * @param psk One segment of a password string (split by ':').
     * @param keyLength Expected key length in bytes (16 for aes-128, 32 for aes-256/chacha20).
     */
    fun normalizePSK(psk: String, keyLength: Int): NormalizeResult {
        if (psk.isEmpty()) {
            return NormalizeResult("", "PSK 为空")
        }

        // Attempt base64 decode
        val decoded = try {
            Base64.getDecoder().decode(psk)
        } catch (e: IllegalArgumentException) {
            // Not valid base64 → raw ASCII of exactly the required length (Xray compat)
            if (psk.length != keyLength || !psk.all { it.code in 0x20..0x7E }) {
                return NormalizeResult("", "密钥无效：非法 base64 且不是 $keyLength 字节的可打印 ASCII")
            }
            psk.toByteArray(Charsets.US_ASCII)
        }

        when {
            decoded.size == keyLength -> {
                // Exact match: re-encode to ensure standard base64 (no URL-safe / padding artifacts)
                val reencoded = Base64.getEncoder().encodeToString(decoded)
                return NormalizeResult(reencoded)
            }
            decoded.size > keyLength -> {
                // Over-long: hash via SHA256 and truncate (sing-box server compat)
                val sha = MessageDigest.getInstance("SHA-256").digest(decoded)
                val truncated = sha.copyOfRange(0, keyLength)
                val hashed = Base64.getEncoder().encodeToString(truncated)
                return NormalizeResult(hashed)
            }
            else -> {
                val methodName = when (keyLength) {
                    16 -> "2022-blake3-aes-128-gcm"
                    32 -> "2022-blake3-aes-256-gcm / chacha20-poly1305"
                    else -> "SS2022"
                }
                return NormalizeResult("", "密钥长度 ${decoded.size} 字节，$methodName 需要 $keyLength 字节")
            }
        }
    }

    /**
     * Normalize a full password string (may contain ':' for multi-user mode).
     * @param password The raw password from user input or node config.
     * @param method Shadowsocks method string, e.g. "2022-blake3-aes-256-gcm".
     * @return Normalized password string (all segments processed) or error.
     */
    fun normalizePassword(password: String, method: String): NormalizeResult {
        val keyLength = when (method.lowercase()) {
            "2022-blake3-aes-128-gcm" -> 16
            "2022-blake3-aes-256-gcm", "2022-blake3-chacha20-poly1305" -> 32
            else -> return NormalizeResult(password) // Not an SS2022 method, pass through
        }

        val segments = password.split(":")
        val normalized = mutableListOf<String>()

        for ((idx, seg) in segments.withIndex()) {
            val result = normalizePSK(seg, keyLength)
            if (result.error != null) {
                val position = if (segments.size > 1) "第 ${idx + 1} 段" else ""
                return NormalizeResult("", "$position${result.error}")
            }
            normalized += result.normalized
        }

        return NormalizeResult(normalized.joinToString(":"))
    }
}
