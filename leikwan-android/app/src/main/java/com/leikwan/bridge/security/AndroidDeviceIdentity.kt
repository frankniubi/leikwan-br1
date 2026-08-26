package com.leikwan.bridge.security

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.spec.ECGenParameterSpec

interface IdentityKeyStore {
    fun getOrCreatePublicKeySpki(): ByteArray
    fun delete()
}

class AndroidDeviceIdentity(
    private val keyStore: IdentityKeyStore = AndroidKeyStoreIdentityKeyStore(),
) {
    fun publicKeySpki(): ByteArray = keyStore.getOrCreatePublicKeySpki()

    fun deviceId(clientId: Long): String {
        require(clientId > 0L) { "clientId must be positive" }
        val publicKey = publicKeySpki()
        return try {
            val suffix = MessageDigest.getInstance("SHA-256")
                .digest(publicKey)
                .joinToString("") { "%02x".format(it) }
                .take(32)
            "android-client-$clientId-$suffix"
        } finally {
            publicKey.fill(0)
        }
    }

    fun clear() = keyStore.delete()
}

class AndroidKeyStoreIdentityKeyStore : IdentityKeyStore {
    override fun getOrCreatePublicKeySpki(): ByteArray = synchronized(lock) {
        val keyStore = androidKeyStore()
        val existing = keyStore.getCertificate(alias)?.publicKey?.encoded
        if (existing != null && existing.isNotEmpty()) {
            return@synchronized existing.copyOf()
        }

        val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEY_STORE)
        generator.initialize(
            KeyGenParameterSpec.Builder(
                alias,
                KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY,
            )
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256)
                .setUserAuthenticationRequired(false)
                .build(),
        )
        generator.generateKeyPair()
        keyStore.getCertificate(alias)?.publicKey?.encoded?.copyOf()
            ?: error("Android identity public key unavailable")
    }

    override fun delete() = synchronized(lock) {
        val keyStore = androidKeyStore()
        if (keyStore.containsAlias(alias)) {
            keyStore.deleteEntry(alias)
        }
    }

    private fun androidKeyStore(): KeyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }

    private companion object {
        const val ANDROID_KEY_STORE = "AndroidKeyStore"
        const val alias = "leikwan_android_identity_v1"
        val lock = Any()
    }
}
