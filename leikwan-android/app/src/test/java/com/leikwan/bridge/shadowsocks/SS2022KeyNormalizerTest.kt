package com.leikwan.bridge.shadowsocks

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

class SS2022KeyNormalizerTest {
    @Test
    fun `exact length aes-256 key passes through`() {
        val key32 = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=" // 32 bytes (padded)
        val result = SS2022KeyNormalizer.normalizePassword(key32, "2022-blake3-aes-256-gcm")
        assertNull(result.error)
        assertEquals(key32, result.normalized)
    }

    @Test
    fun `exact length aes-128 key passes through`() {
        val key16 = "MTIzNDU2Nzg5MDEyMzQ1Ng==" // 16 bytes (padded)
        val result = SS2022KeyNormalizer.normalizePassword(key16, "2022-blake3-aes-128-gcm")
        assertNull(result.error)
        assertEquals(key16, result.normalized)
    }

    @Test
    fun `over-long key is hashed to target length`() {
        val longKey = "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM=" // 47 bytes
        val result = SS2022KeyNormalizer.normalizePassword(longKey, "2022-blake3-aes-256-gcm")
        assertNull(result.error)
        // SHA256 of the decoded bytes, truncated to 32, re-encoded (padded)
        assertNotNull(result.normalized)
        assertEquals(44, result.normalized.length) // base64 of 32 bytes, padded
    }

    @Test
    fun `raw ASCII 32-char key is accepted and re-encoded`() {
        val rawKey = "a!b@c#d\$e%f^g&h*i(j)k-l=m[n]o{p}" // 32 ASCII chars, not valid base64
        val result = SS2022KeyNormalizer.normalizePassword(rawKey, "2022-blake3-aes-256-gcm")
        assertNull(result.error)
        // base64 of the raw bytes (padded)
        assertEquals("YSFiQGMjZCRlJWZeZyZoKmkoailrLWw9bVtuXW97cH0=", result.normalized)
    }

    @Test
    fun `multi-user iPSK colon uPSK both exact length`() {
        val key = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU="
        val result = SS2022KeyNormalizer.normalizePassword(key, "2022-blake3-aes-256-gcm")
        assertNull(result.error)
        assertEquals(key, result.normalized)
    }

    @Test
    fun `multi-user with one over-long segment normalizes that segment only`() {
        val exactKey = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
        val longKey = "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM="
        val result = SS2022KeyNormalizer.normalizePassword("$exactKey:$longKey", "2022-blake3-aes-256-gcm")
        assertNull(result.error)
        val parts = result.normalized.split(":")
        assertEquals(2, parts.size)
        assertEquals(exactKey, parts[0])
        assertNotNull(parts[1])
        assertEquals(44, parts[1].length) // hashed segment, padded
    }

    @Test
    fun `under-length key returns error`() {
        val shortKey = "c2hvcnQ=" // "short" = 5 bytes
        val result = SS2022KeyNormalizer.normalizePassword(shortKey, "2022-blake3-aes-256-gcm")
        assertNotNull(result.error)
        assert(result.error!!.contains("密钥长度"))
        assert(result.error!!.contains("32 字节"))
    }

    @Test
    fun `empty PSK returns error`() {
        val result = SS2022KeyNormalizer.normalizePassword("", "2022-blake3-aes-256-gcm")
        assertNotNull(result.error)
        assert(result.error!!.contains("PSK 为空"))
    }

    @Test
    fun `non-2022 method passes through unchanged`() {
        val password = "some-legacy-password"
        val result = SS2022KeyNormalizer.normalizePassword(password, "aes-256-gcm")
        assertNull(result.error)
        assertEquals(password, result.normalized)
    }

    @Test
    fun `raw ascii key of wrong length is rejected`() {
        val rawKey = "a!b@c#d\$e%f^g&h*i(j)k-l=m[n]o{p}xyz" // 35 ASCII chars, not valid base64
        val result = SS2022KeyNormalizer.normalizePassword(rawKey, "2022-blake3-aes-256-gcm")
        assertNotNull(result.error)
        assert(result.error!!.contains("可打印 ASCII"))
    }

    @Test
    fun `raw ascii key with non-ascii char is rejected`() {
        val rawKey = "a!b@c#d\$e%f^g&h*i(j)k-l=m[n]o{p}密" // 32 chars but not ASCII
        val result = SS2022KeyNormalizer.normalizePassword(rawKey, "2022-blake3-aes-256-gcm")
        assertNotNull(result.error)
        assert(result.error!!.contains("可打印 ASCII"))
    }

    @Test
    fun `chacha20-poly1305 requires 32 bytes`() {
        val key32 = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
        val result = SS2022KeyNormalizer.normalizePassword(key32, "2022-blake3-chacha20-poly1305")
        assertNull(result.error)
        assertEquals(key32, result.normalized)
    }
}
