package com.leikwan.bridge.security

import com.google.gson.JsonObject
import java.security.KeyPairGenerator
import java.security.SecureRandom
import java.security.interfaces.ECPublicKey
import java.security.spec.ECGenParameterSpec
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** Test-only seal side, mirroring master/src/Support/UltraLeaseSealer.php. */
object UltraLeaseTestSealer {
    private val encoder = Base64.getUrlEncoder().withoutPadding()

    fun sealedEnvelope(plaintext: String, devicePublicRaw: ByteArray, leaseId: String): JsonObject {
        val generator = KeyPairGenerator.getInstance("EC")
        generator.initialize(ECGenParameterSpec("secp256r1"))
        val ephemeral = generator.generateKeyPair()
        val ephemeralKey = EcUltraLeaseKey(ephemeral.private, ephemeral.public as ECPublicKey)
        val epk = ephemeralKey.publicKeyRaw()
        val shared = ephemeralKey.agree(P256.decodePoint(devicePublicRaw))
        val key = UltraLeaseSeal.hkdfSha256(
            ikm = shared,
            salt = epk + devicePublicRaw,
            info = UltraLeaseSeal.INFO.toByteArray(Charsets.UTF_8),
            length = 32,
        )
        val iv = ByteArray(12).also(SecureRandom()::nextBytes)
        val ct = Cipher.getInstance("AES/GCM/NoPadding").run {
            init(Cipher.ENCRYPT_MODE, SecretKeySpec(key, "AES"), GCMParameterSpec(128, iv))
            updateAAD("${UltraLeaseSeal.INFO}|$leaseId".toByteArray(Charsets.UTF_8))
            doFinal(plaintext.toByteArray(Charsets.UTF_8))
        }
        return JsonObject().apply {
            addProperty("v", UltraLeaseSeal.VERSION)
            addProperty("alg", UltraLeaseSeal.ALGORITHM)
            addProperty("kid", P256.keyId(devicePublicRaw))
            addProperty("epk", encoder.encodeToString(epk))
            addProperty("iv", encoder.encodeToString(iv))
            addProperty("ct", encoder.encodeToString(ct))
        }
    }

    /** Moves endpoint / credential / external_uot of [lease] into a `sealed` envelope. */
    fun sealLease(lease: JsonObject, devicePublicRaw: ByteArray): JsonObject {
        val secret = JsonObject()
        val sealed = lease.deepCopy()
        listOf("endpoint", "credential", "external_uot").forEach { name ->
            sealed.remove(name)?.let { value -> secret.add(name, value) }
        }
        sealed.add(
            "sealed",
            sealedEnvelope(secret.toString(), devicePublicRaw, lease.get("lease_id").asString),
        )
        return sealed
    }
}
