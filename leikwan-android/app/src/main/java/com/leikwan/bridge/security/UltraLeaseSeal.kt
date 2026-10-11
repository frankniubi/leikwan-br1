package com.leikwan.bridge.security

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/**
 * Open side of the Ultra sealed lease (docs/specs/2026-10-02-ultra-sealed-lease-v1.md §3).
 *
 * ```
 * Z     = ECDH(device_private, epk)
 * key   = HKDF-SHA256(ikm = Z, salt = epk || device_public, info = INFO, L = 32)
 * plain = AES-256-GCM-Decrypt(key, iv, ct, aad = INFO + "|" + lease_id)
 * ```
 *
 * Never log the opened plaintext: it carries the provider endpoint and credential.
 */
object UltraLeaseSeal {
    const val HEADER = "X-Leikwan-Lease-Key"
    const val VERSION = 1
    const val ALGORITHM = "ECDH-P256+HKDF-SHA256+A256GCM"
    const val INFO = "leikwan-ultra-lease-v1"
    const val UNSEAL_FAILED = "lease_unseal_failed"

    private const val SEALED_FIELD = "sealed"
    private const val IV_BYTES = 12
    private const val TAG_BYTES = 16
    private const val KEY_BYTES = 32

    /** `X-Leikwan-Lease-Key` value: base64url, no padding, of the 65-byte public point. */
    fun headerValue(key: UltraLeaseKey): String =
        Base64.getUrlEncoder().withoutPadding().encodeToString(key.publicKeyRaw())

    /**
     * Returns [lease] itself when it carries no `sealed` envelope; otherwise a copy with the
     * opened fields merged in and `sealed` removed. Any failure to open throws
     * [IllegalStateException] with [UNSEAL_FAILED]; there is no fallback.
     */
    fun openLease(lease: JsonObject, key: UltraLeaseKey?): JsonObject {
        if (!lease.has(SEALED_FIELD)) return lease
        val opened = try {
            open(lease, key ?: throw IllegalStateException(UNSEAL_FAILED))
        } catch (_: Exception) {
            // Collapse every cause (bad base64, GCM tag, JSON, key) into one code: the cause
            // text could otherwise echo envelope or plaintext bytes into logs.
            null
        } ?: throw IllegalStateException(UNSEAL_FAILED)
        val merged = lease.deepCopy()
        merged.remove(SEALED_FIELD)
        opened.entrySet().forEach { (name, value) -> merged.add(name, value) }
        return merged
    }

    private fun open(lease: JsonObject, key: UltraLeaseKey): JsonObject? {
        val sealed = lease.get(SEALED_FIELD)?.takeIf { it.isJsonObject }?.asJsonObject ?: return null
        val leaseId = lease.get("lease_id")
            ?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }
            ?.asString ?: return null
        val version = sealed.get("v")?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isNumber }
            ?: return null
        if (version.asString != VERSION.toString()) return null
        if (sealed.stringField("alg") != ALGORITHM) return null

        val devicePublic = key.publicKeyRaw()
        if (sealed.stringField("kid") != P256.keyId(devicePublic)) return null
        val epk = sealed.bytesField("epk") ?: return null
        val iv = sealed.bytesField("iv") ?: return null
        val ct = sealed.bytesField("ct") ?: return null
        if (epk.size != P256.RAW_POINT_BYTES || iv.size != IV_BYTES || ct.size < TAG_BYTES) return null

        val shared = key.agree(P256.decodePoint(epk))
        val aesKey = try {
            hkdfSha256(ikm = shared, salt = epk + devicePublic, info = INFO.toByteArray(Charsets.UTF_8), length = KEY_BYTES)
        } finally {
            shared.fill(0)
        }
        val plain = try {
            Cipher.getInstance("AES/GCM/NoPadding").run {
                init(Cipher.DECRYPT_MODE, SecretKeySpec(aesKey, "AES"), GCMParameterSpec(TAG_BYTES * 8, iv))
                updateAAD("$INFO|$leaseId".toByteArray(Charsets.UTF_8))
                doFinal(ct)
            }
        } finally {
            aesKey.fill(0)
        }
        return try {
            JsonParser.parseString(String(plain, Charsets.UTF_8))
                .takeIf { it.isJsonObject }
                ?.asJsonObject
        } finally {
            plain.fill(0)
        }
    }

    /** RFC 5869 HKDF with HMAC-SHA256. */
    internal fun hkdfSha256(ikm: ByteArray, salt: ByteArray, info: ByteArray, length: Int): ByteArray {
        require(length in 1..(255 * 32)) { "hkdf_length_invalid" }
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(if (salt.isEmpty()) ByteArray(32) else salt, "HmacSHA256"))
        val prk = mac.doFinal(ikm)
        try {
            mac.init(SecretKeySpec(prk, "HmacSHA256"))
            val output = ByteArray(length)
            var previous = ByteArray(0)
            var offset = 0
            var counter = 1
            while (offset < length) {
                mac.update(previous)
                mac.update(info)
                mac.update(counter.toByte())
                val block = mac.doFinal()
                previous.fill(0)
                val count = minOf(block.size, length - offset)
                block.copyInto(output, offset, 0, count)
                previous = block
                offset += count
                counter += 1
            }
            previous.fill(0)
            return output
        } finally {
            prk.fill(0)
        }
    }

    private fun JsonObject.stringField(name: String): String? =
        get(name)?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }?.asString

    private fun JsonObject.bytesField(name: String): ByteArray? {
        val value = stringField(name) ?: return null
        if (!value.matches(BASE64URL)) return null
        return runCatching { Base64.getUrlDecoder().decode(value) }.getOrNull()
    }

    private val BASE64URL = Regex("^[A-Za-z0-9_-]*={0,2}$")
}
