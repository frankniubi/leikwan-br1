package com.leikwan.bridge.security

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyInfo
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import com.google.gson.annotations.SerializedName
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.util.Base64
import java.util.UUID

data class AttestationProof(
    @SerializedName("certificate_chain") val certificateChain: List<String>,
    @SerializedName("identity_public_key") val identityPublicKey: String,
    @SerializedName("binding_signature") val bindingSignature: String,
)

interface AttestationKeyMaterial : AutoCloseable {
    val certificateChain: List<ByteArray>
    val hardwareBacked: Boolean
    fun sign(message: ByteArray): ByteArray
}

interface AttestationKeyProvider {
    fun create(challenge: ByteArray): AttestationKeyMaterial
}

class AndroidAttestationProofFactory(
    private val identity: AndroidDeviceIdentity = AndroidDeviceIdentity(),
    private val attestationKeys: AttestationKeyProvider = AndroidKeyStoreAttestationKeyProvider(),
) {
    fun create(nonce: String, clientId: Long, deviceId: String): AttestationProof {
        require(nonce.isNotBlank()) { "nonce is required" }
        require(clientId > 0L) { "clientId must be positive" }
        require(identity.deviceId(clientId) == deviceId) { "deviceId does not match identity key" }

        val identitySpki = identity.publicKeySpki()
        val challenge = nonce.toByteArray(Charsets.UTF_8)
        val identityHash = sha256Hex(identitySpki)
        val binding = "$nonce|$clientId|$deviceId|$identityHash".toByteArray(Charsets.UTF_8)
        try {
            attestationKeys.create(challenge).use { key ->
                check(key.hardwareBacked) { "hardware-backed attestation is required" }
                val signature = key.sign(binding)
                return AttestationProof(
                    certificateChain = key.certificateChain.map(Base64.getEncoder()::encodeToString),
                    identityPublicKey = Base64.getEncoder().encodeToString(identitySpki),
                    bindingSignature = Base64.getEncoder().encodeToString(signature),
                )
            }
        } finally {
            identitySpki.fill(0)
            challenge.fill(0)
            binding.fill(0)
        }
    }

    private fun sha256Hex(value: ByteArray): String = java.security.MessageDigest.getInstance("SHA-256")
        .digest(value)
        .joinToString("") { "%02x".format(it) }
}

class AndroidKeyStoreAttestationKeyProvider : AttestationKeyProvider {
    override fun create(challenge: ByteArray): AttestationKeyMaterial {
        require(challenge.isNotEmpty()) { "attestation challenge is required" }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            try {
                return generate(challenge, strongBox = true)
            } catch (_: StrongBoxUnavailableException) {
                // A TEE-backed key remains acceptable when StrongBox is absent.
            }
        }
        return generate(challenge, strongBox = false)
    }

    private fun generate(challenge: ByteArray, strongBox: Boolean): AttestationKeyMaterial {
        val alias = "leikwan_android_attestation_${UUID.randomUUID()}"
        val keyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }
        try {
            val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEY_STORE)
            val builder = KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY,
            )
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .setAttestationChallenge(challenge.copyOf())
                .setUserAuthenticationRequired(false)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                builder.setIsStrongBoxBacked(strongBox)
            }
            generator.initialize(builder.build())
            generator.generateKeyPair()

            val privateKey = keyStore.getKey(alias, null) as? PrivateKey
                ?: error("attestation private key unavailable")
            val chain = keyStore.getCertificateChain(alias)?.map { it.encoded.copyOf() }.orEmpty()
            val keyInfo = KeyFactory.getInstance(privateKey.algorithm, ANDROID_KEY_STORE)
                .getKeySpec(privateKey, KeyInfo::class.java)
            if (chain.isEmpty() || !isHardwareBacked(keyInfo)) {
                error("hardware-backed attestation key unavailable")
            }

            return AndroidAttestationKeyMaterial(alias, privateKey, chain)
        } catch (throwable: Throwable) {
            if (keyStore.containsAlias(alias)) {
                keyStore.deleteEntry(alias)
            }
            throw throwable
        }
    }

    private fun isHardwareBacked(keyInfo: KeyInfo): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            return keyInfo.securityLevel == KeyProperties.SECURITY_LEVEL_TRUSTED_ENVIRONMENT ||
                keyInfo.securityLevel == KeyProperties.SECURITY_LEVEL_STRONGBOX
        }
        @Suppress("DEPRECATION")
        return keyInfo.isInsideSecureHardware
    }

    private class AndroidAttestationKeyMaterial(
        private val alias: String,
        private val privateKey: PrivateKey,
        override val certificateChain: List<ByteArray>,
    ) : AttestationKeyMaterial {
        override val hardwareBacked: Boolean = true

        override fun sign(message: ByteArray): ByteArray = Signature.getInstance("SHA256withECDSA").run {
            initSign(privateKey)
            update(message)
            sign()
        }

        override fun close() {
            runCatching {
                KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }.deleteEntry(alias)
            }
        }
    }

    private companion object {
        const val ANDROID_KEY_STORE = "AndroidKeyStore"
    }
}
