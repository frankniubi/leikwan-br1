package com.leikwan.bridge.security

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertSame
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.util.Base64
import javax.crypto.spec.SecretKeySpec

/** Vector and failure cases from docs/specs/2026-10-02-ultra-sealed-lease-v1.md §5. */
class UltraLeaseSealTest {
    @get:Rule val temp = TemporaryFolder()

    private val decoder = Base64.getUrlDecoder()
    private val encoder = Base64.getUrlEncoder().withoutPadding()

    private val vectorKey = EcUltraLeaseKey.fromPrivateScalar(
        hex("899847890f5f32c53126b22dc726d290a22e63fdc2bbc6dd2f063685199e5dfb"),
        decoder.decode(DEVICE_PUBLIC_B64URL),
    )

    private fun vectorLease(): JsonObject = JsonParser.parseString(
        """
        {
          "lease_id": "ul2_test-vector-0001",
          "forward_id": 42,
          "sealed": {
            "v": 1,
            "alg": "ECDH-P256+HKDF-SHA256+A256GCM",
            "kid": "c188836f4cbb9983ef0ce2f209a32767",
            "epk": "BNcv3DbxPypDUaly9lSNYnpyfC2TFG6vjXq2CPkud-Y_eUPFQEtJv4ppTMWnLJ8FaohlckDI6vfBZQR1Ge8IdxU",
            "iv": "ABEiM0RVZneImaq7",
            "ct": "PBnTWzpuLWhTPFhpX86NSK30Xe3CbjjHaQOs6PSBQ0uymvpO2hvnLDuWaaTqdrVWBWORXa7dQfFmzKxr4pN6xJ6Bi-IEiHBKOE8MgpV1REPUugS3rInpQhM66G9rY1ZrWmSTysuUlhoyRo_HeRgGT5uPETJfdiOThqzck9ixD8_ykEXHkO0VstbSUnc09hBqChXJX92Jmuf_tSb-9DOgvnfxd7A8_UDn4x52VVC6HuGQwOXG11gWfhUFSI62OMIJbdvKo3u6TmeV7GHL"
          }
        }
        """.trimIndent(),
    ).asJsonObject

    @Test
    fun `header and kid match the vector public key`() {
        assertEquals(DEVICE_PUBLIC_B64URL, UltraLeaseSeal.headerValue(vectorKey))
        assertEquals("c188836f4cbb9983ef0ce2f209a32767", P256.keyId(vectorKey.publicKeyRaw()))
    }

    @Test
    fun `spec vector opens to exactly the expected plaintext`() {
        val lease = vectorLease()
        val opened = UltraLeaseSeal.openLease(lease, vectorKey)

        val expected = JsonParser.parseString(EXPECTED_PLAINTEXT).asJsonObject
        assertFalse(opened.has("sealed"))
        assertEquals("ul2_test-vector-0001", opened.get("lease_id").asString)
        assertEquals(42L, opened.get("forward_id").asLong)
        assertEquals(setOf("lease_id", "forward_id") + expected.keySet(), opened.keySet())
        expected.entrySet().forEach { (name, value) -> assertEquals(value, opened.get(name)) }
        val openedSecret = JsonObject().apply { expected.keySet().forEach { add(it, opened.get(it)) } }
        assertEquals(EXPECTED_PLAINTEXT, openedSecret.toString())
        // The input object is left untouched.
        assertTrue(lease.has("sealed"))
    }

    @Test
    fun `flipping any byte of ct iv epk or lease_id fails`() {
        listOf("ct", "iv", "epk").forEach { field ->
            val original = decoder.decode(vectorLease().getAsJsonObject("sealed").get(field).asString)
            listOf(0, original.size / 2, original.size - 1).forEach { index ->
                val lease = vectorLease()
                val bytes = original.copyOf().also { it[index] = (it[index].toInt() xor 0x01).toByte() }
                lease.getAsJsonObject("sealed").addProperty(field, encoder.encodeToString(bytes))
                assertUnsealFails("$field[$index]", lease, vectorKey)
            }
        }
        val renamed = vectorLease().apply { addProperty("lease_id", "ul2_test-vector-0002") }
        assertUnsealFails("lease_id", renamed, vectorKey)
    }

    @Test
    fun `envelope header mismatches fail closed`() {
        val cases = mapOf<String, (JsonObject) -> Unit>(
            "v" to { it.addProperty("v", 2) },
            "v as string" to { it.addProperty("v", "1") },
            "alg" to { it.addProperty("alg", "ECDH-P384+HKDF-SHA384+A256GCM") },
            "kid" to { it.addProperty("kid", "00000000000000000000000000000000") },
            "iv length" to { it.addProperty("iv", "ABEiM0RVZneI") },
            "bad base64" to { it.addProperty("ct", "not base64!") },
            "missing ct" to { it.remove("ct") },
        )
        cases.forEach { (name, mutate) ->
            val lease = vectorLease()
            mutate(lease.getAsJsonObject("sealed"))
            assertUnsealFails(name, lease, vectorKey)
        }
        assertUnsealFails("sealed not an object", vectorLease().apply { addProperty("sealed", "x") }, vectorKey)
        assertUnsealFails("missing lease_id", vectorLease().apply { remove("lease_id") }, vectorKey)
        assertUnsealFails("no device key", vectorLease(), null)
    }

    @Test
    fun `another device key cannot open the lease`() {
        val other = WrappedUltraLeaseKeyFile(File(temp.root, "other.bin"), ::fixedWrapKey).create()
        val lease = vectorLease()
        // Same kid would be needed to get past the key-id check; force it to reach GCM.
        lease.getAsJsonObject("sealed").addProperty("kid", P256.keyId(other.publicKeyRaw()))
        assertUnsealFails("other key", lease, other)
    }

    @Test
    fun `a non-object plaintext fails`() {
        val lease = JsonObject().apply {
            addProperty("lease_id", "ul2_non_object_lease")
            add("sealed", UltraLeaseTestSealer.sealedEnvelope("[1,2]", vectorKey.publicKeyRaw(), "ul2_non_object_lease"))
        }
        assertUnsealFails("array plaintext", lease, vectorKey)
    }

    @Test
    fun `a lease without sealed is returned as-is`() {
        val lease = JsonParser.parseString(
            """{"lease_id":"ul2_clear","endpoint":{"ip":"203.0.113.7","port":1}}""",
        ).asJsonObject
        assertSame(lease, UltraLeaseSeal.openLease(lease, vectorKey))
        assertSame(lease, UltraLeaseSeal.openLease(lease, null))
    }

    @Test
    fun `seal round trip with a generated key`() {
        val key = WrappedUltraLeaseKeyFile(File(temp.root, "k.bin"), ::fixedWrapKey).create()
        val lease = JsonObject().apply {
            addProperty("lease_id", "ul2_round_trip_lease")
            add("endpoint", JsonParser.parseString("""{"ip":"198.51.100.1","port":443}"""))
        }
        val opened = UltraLeaseSeal.openLease(UltraLeaseTestSealer.sealLease(lease, key.publicKeyRaw()), key)
        assertEquals(lease, opened)
    }

    @Test
    fun `hkdf matches RFC 5869 test case 1`() {
        val okm = UltraLeaseSeal.hkdfSha256(
            ikm = hex("0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b"),
            salt = hex("000102030405060708090a0b0c"),
            info = hex("f0f1f2f3f4f5f6f7f8f9"),
            length = 42,
        )
        assertArrayEquals(
            hex("3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865"),
            okm,
        )
    }

    @Test
    fun `off-curve points are rejected`() {
        val raw = vectorKey.publicKeyRaw().also { it[64] = (it[64].toInt() xor 0x01).toByte() }
        assertThrows(IllegalArgumentException::class.java) { P256.decodePoint(raw) }
        assertThrows(IllegalArgumentException::class.java) { P256.decodePoint(ByteArray(65).also { it[0] = 4 }) }
    }

    @Test
    fun `wrapped key file persists the same key and rejects tampering`() {
        val file = File(temp.root, "nested/ultra_lease_key_v1.bin")
        val store = WrappedUltraLeaseKeyFile(file, ::fixedWrapKey)
        assertFalse(store.exists())
        val created = store.create()
        assertTrue(store.exists())
        val loaded = store.load()
        assertArrayEquals(created.publicKeyRaw(), loaded.publicKeyRaw())
        assertTrue(AndroidUltraLeaseKeyStore.selfTest(loaded))
        assertFalse(file.readBytes().containsSubsequence(created.publicKeyRaw()))

        val wrongWrap = WrappedUltraLeaseKeyFile(file) { SecretKeySpec(ByteArray(32) { 7 }, "AES") }
        assertThrows(Exception::class.java) { wrongWrap.load() }

        val bytes = file.readBytes()
        bytes[bytes.size - 1] = (bytes[bytes.size - 1].toInt() xor 0x01).toByte()
        file.writeBytes(bytes)
        assertThrows(Exception::class.java) { store.load() }

        store.delete()
        assertFalse(store.exists())
        assertNotEquals(
            P256.keyId(created.publicKeyRaw()),
            P256.keyId(store.create().publicKeyRaw()),
        )
    }

    private fun assertUnsealFails(case: String, lease: JsonObject, key: UltraLeaseKey?) {
        val error = assertThrows(case, IllegalStateException::class.java) { UltraLeaseSeal.openLease(lease, key) }
        assertEquals(case, UltraLeaseSeal.UNSEAL_FAILED, error.message)
    }

    private fun fixedWrapKey() = SecretKeySpec(ByteArray(32) { 3 }, "AES")

    private fun ByteArray.containsSubsequence(needle: ByteArray): Boolean =
        (0..size - needle.size).any { start -> needle.indices.all { this[start + it] == needle[it] } }

    private fun hex(value: String): ByteArray = ByteArray(value.length / 2) { index ->
        value.substring(index * 2, index * 2 + 2).toInt(16).toByte()
    }

    private companion object {
        const val DEVICE_PUBLIC_B64URL =
            "BJB5i5gLTy4P7-eKXbTqzY_-YNVJKKEaugOx14nJAuEHrTi_gY9XreyCRnuXCE86Ecgpq1pTbXRGQ0BCPfIHhJs"
        const val EXPECTED_PLAINTEXT =
            """{"endpoint":{"ip":"203.0.113.7","port":40123},"credential":{"user":"lg-user-1","password":"lg-secret-1"},"external_uot":{"enabled":true,"sidecar_target":"198.51.100.9:7443","token":"tok"}}"""
    }
}
