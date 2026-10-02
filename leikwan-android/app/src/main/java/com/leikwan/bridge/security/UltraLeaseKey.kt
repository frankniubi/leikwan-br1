package com.leikwan.bridge.security

import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Log
import androidx.annotation.RequiresApi
import java.io.File
import java.math.BigInteger
import java.nio.ByteBuffer
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.PrivateKey
import java.security.interfaces.ECPublicKey
import java.security.spec.ECGenParameterSpec
import java.security.spec.ECPrivateKeySpec
import java.security.spec.PKCS8EncodedKeySpec
import java.security.spec.X509EncodedKeySpec
import javax.crypto.Cipher
import javax.crypto.KeyAgreement
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Device-bound P-256 key that opens sealed Ultra leases
 * (docs/specs/2026-10-02-ultra-sealed-lease-v1.md §1). It is used for nothing else.
 */
interface UltraLeaseKey {
    /** The 65-byte uncompressed SEC1 point `0x04 || X || Y`; a fresh copy on every call. */
    fun publicKeyRaw(): ByteArray

    /** Raw ECDH shared secret with [peer]: the 32-byte big-endian x coordinate. */
    fun agree(peer: ECPublicKey): ByteArray
}

/** A P-256 private key paired with its public point. [provider] null = platform default. */
class EcUltraLeaseKey(
    private val privateKey: PrivateKey,
    publicKey: ECPublicKey,
    private val provider: String? = null,
) : UltraLeaseKey {
    private val publicRaw: ByteArray = P256.encodePoint(publicKey)

    override fun publicKeyRaw(): ByteArray = publicRaw.copyOf()

    override fun agree(peer: ECPublicKey): ByteArray {
        val agreement = if (provider == null) {
            KeyAgreement.getInstance("ECDH")
        } else {
            KeyAgreement.getInstance("ECDH", provider)
        }
        agreement.init(privateKey)
        agreement.doPhase(peer, true)
        return P256.fixedWidth(agreement.generateSecret())
    }

    companion object {
        /** Software key from a raw private scalar and its public point (test vectors). */
        fun fromPrivateScalar(scalar: ByteArray, publicRaw: ByteArray): EcUltraLeaseKey {
            val publicKey = P256.decodePoint(publicRaw)
            val privateKey = KeyFactory.getInstance("EC")
                .generatePrivate(ECPrivateKeySpec(BigInteger(1, scalar), publicKey.params))
            return EcUltraLeaseKey(privateKey, publicKey)
        }
    }
}

/** P-256 point helpers. Every peer point is checked to lie on the curve before use. */
object P256 {
    private const val COORDINATE_BYTES = 32
    const val RAW_POINT_BYTES = 65

    private val p = BigInteger("ffffffff00000001000000000000000000000000ffffffffffffffffffffffff", 16)
    private val b = BigInteger("5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604b", 16)
    private val three = BigInteger.valueOf(3)

    // DER SubjectPublicKeyInfo prefix for id-ecPublicKey + prime256v1 + 65-byte BIT STRING.
    private val spkiPrefix = byteArrayOf(
        0x30, 0x59, 0x30, 0x13, 0x06, 0x07, 0x2a, 0x86.toByte(), 0x48, 0xce.toByte(), 0x3d, 0x02, 0x01,
        0x06, 0x08, 0x2a, 0x86.toByte(), 0x48, 0xce.toByte(), 0x3d, 0x03, 0x01, 0x07, 0x03, 0x42, 0x00,
    )

    fun encodePoint(key: ECPublicKey): ByteArray {
        val raw = ByteArray(RAW_POINT_BYTES)
        raw[0] = 0x04
        fixedWidth(key.w.affineX.toByteArray()).copyInto(raw, 1)
        fixedWidth(key.w.affineY.toByteArray()).copyInto(raw, 1 + COORDINATE_BYTES)
        return raw
    }

    /** Decodes an uncompressed point; throws [IllegalArgumentException] unless it is on P-256. */
    fun decodePoint(raw: ByteArray): ECPublicKey {
        require(raw.size == RAW_POINT_BYTES && raw[0] == 0x04.toByte()) { "p256_point_invalid" }
        val x = BigInteger(1, raw.copyOfRange(1, 1 + COORDINATE_BYTES))
        val y = BigInteger(1, raw.copyOfRange(1 + COORDINATE_BYTES, RAW_POINT_BYTES))
        require(x < p && y < p) { "p256_point_invalid" }
        val left = y.multiply(y).mod(p)
        val right = x.pow(3).subtract(x.multiply(three)).add(b).mod(p)
        require(left == right) { "p256_point_invalid" }
        val key = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(spkiPrefix + raw))
        return key as? ECPublicKey ?: throw IllegalArgumentException("p256_point_invalid")
    }

    /** Re-encodes any EC public key (e.g. a Keystore one) as a plain platform key. */
    fun normalize(key: ECPublicKey): ECPublicKey = decodePoint(encodePoint(key))

    /** Left-pads or strips a big-endian integer to exactly 32 bytes. */
    fun fixedWidth(value: ByteArray): ByteArray = when {
        value.size == COORDINATE_BYTES -> value
        value.size > COORDINATE_BYTES -> value.copyOfRange(value.size - COORDINATE_BYTES, value.size)
        else -> ByteArray(COORDINATE_BYTES - value.size) + value
    }

    /** First 32 hex chars of sha256(raw public key): the panel's `kid`. */
    fun keyId(raw: ByteArray): String = MessageDigest.getInstance("SHA-256")
        .digest(raw)
        .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
        .take(32)
}

fun interface UltraLeaseKeyStore {
    /** Returns the device lease key, creating it on first use. Throws when none can be provided. */
    fun getOrCreate(): UltraLeaseKey
}

/**
 * Software P-256 key whose PKCS#8 + SPKI bytes are sealed with an AES-GCM key and kept in an
 * app-private file. Used below API 31 (no Keystore ECDH) and when Keystore ECDH is unusable.
 *
 * File: MAGIC(4) || iv(12) || AES-GCM(u16 len || pkcs8 || spki) || tag(16), AAD = MAGIC.
 */
class WrappedUltraLeaseKeyFile(
    private val file: File,
    private val wrapKey: () -> SecretKey,
) {
    fun exists(): Boolean = file.isFile

    fun load(): EcUltraLeaseKey {
        val blob = file.readBytes()
        require(blob.size > MAGIC.size + IV_BYTES + TAG_BYTES) { "lease_key_file_invalid" }
        require(blob.copyOfRange(0, MAGIC.size).contentEquals(MAGIC)) { "lease_key_file_invalid" }
        val iv = blob.copyOfRange(MAGIC.size, MAGIC.size + IV_BYTES)
        val cipher = Cipher.getInstance(AES_GCM)
        cipher.init(Cipher.DECRYPT_MODE, wrapKey(), GCMParameterSpec(TAG_BYTES * 8, iv))
        cipher.updateAAD(MAGIC)
        val plain = cipher.doFinal(blob, MAGIC.size + IV_BYTES, blob.size - MAGIC.size - IV_BYTES)
        try {
            val buffer = ByteBuffer.wrap(plain)
            val pkcs8Length = buffer.short.toInt() and 0xffff
            require(pkcs8Length in 1..(plain.size - 2)) { "lease_key_file_invalid" }
            val pkcs8 = ByteArray(pkcs8Length).also(buffer::get)
            val spki = ByteArray(buffer.remaining()).also(buffer::get)
            try {
                val factory = KeyFactory.getInstance("EC")
                val privateKey = factory.generatePrivate(PKCS8EncodedKeySpec(pkcs8))
                val publicKey = factory.generatePublic(X509EncodedKeySpec(spki)) as ECPublicKey
                return EcUltraLeaseKey(privateKey, P256.normalize(publicKey))
            } finally {
                pkcs8.fill(0)
            }
        } finally {
            plain.fill(0)
        }
    }

    fun create(): EcUltraLeaseKey {
        val generator = KeyPairGenerator.getInstance("EC")
        generator.initialize(ECGenParameterSpec("secp256r1"))
        val pair = generator.generateKeyPair()
        val publicKey = pair.public as ECPublicKey
        val pkcs8 = pair.private.encoded ?: error("lease_key_not_encodable")
        val spki = publicKey.encoded
        val plain = ByteBuffer.allocate(2 + pkcs8.size + spki.size)
            .putShort(pkcs8.size.toShort())
            .put(pkcs8)
            .put(spki)
            .array()
        try {
            val cipher = Cipher.getInstance(AES_GCM)
            cipher.init(Cipher.ENCRYPT_MODE, wrapKey())
            cipher.updateAAD(MAGIC)
            val iv = cipher.iv
            check(iv != null && iv.size == IV_BYTES) { "lease_key_wrap_iv_invalid" }
            val sealed = cipher.doFinal(plain)
            file.parentFile?.mkdirs()
            val temp = File(file.parentFile, file.name + ".tmp")
            temp.writeBytes(MAGIC + iv + sealed)
            if (!temp.renameTo(file)) {
                temp.delete()
                error("lease_key_file_write_failed")
            }
        } finally {
            pkcs8.fill(0)
            plain.fill(0)
        }
        return EcUltraLeaseKey(pair.private, P256.normalize(publicKey))
    }

    fun delete() {
        file.delete()
        File(file.parentFile, file.name + ".tmp").delete()
    }

    private companion object {
        const val AES_GCM = "AES/GCM/NoPadding"
        const val IV_BYTES = 12
        const val TAG_BYTES = 16
        val MAGIC = byteArrayOf(0x4c, 0x4b, 0x4c, 0x31) // "LKL1"
    }
}

/**
 * The production lease key store.
 *
 * * API 31+: a non-extractable Android Keystore P-256 key with `PURPOSE_AGREE_KEY`.
 * * Below API 31, or when Keystore ECDH does not work on this device: a software P-256 key in
 *   [WrappedUltraLeaseKeyFile], wrapped with a non-extractable Keystore AES-256-GCM key.
 *
 * Whichever key exists is reused; a new key is generated only when none exists or the stored one
 * fails a local ECDH self-test (lost, invalidated or corrupt). A panel `lease_device_key_conflict`
 * never reaches here, so it can never trigger regeneration.
 */
class AndroidUltraLeaseKeyStore(context: Context) : UltraLeaseKeyStore {
    private val wrappedFile = WrappedUltraLeaseKeyFile(
        File(context.applicationContext.noBackupFilesDir, WRAPPED_KEY_FILE),
        ::getOrCreateWrapKey,
    )

    override fun getOrCreate(): UltraLeaseKey = synchronized(lock) {
        cached?.let { return@synchronized it }
        val key = loadExisting() ?: generate()
        cached = key
        key
    }

    private fun loadExisting(): UltraLeaseKey? {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val keystoreKey = runCatching { loadKeystoreAgreeKey() }.getOrNull()
            if (keystoreKey != null) {
                if (selfTest(keystoreKey)) return keystoreKey
                Log.w(TAG, "Stored Keystore lease key is unusable; replacing it")
                runCatching { deleteKeystoreAlias(AGREE_ALIAS) }
            }
        }
        if (wrappedFile.exists()) {
            val wrapped = runCatching { wrappedFile.load() }.getOrNull()
            if (wrapped != null && selfTest(wrapped)) return wrapped
            Log.w(TAG, "Stored wrapped lease key is unusable; replacing it")
            wrappedFile.delete()
            runCatching { deleteKeystoreAlias(WRAP_ALIAS) }
        }
        return null
    }

    private fun generate(): UltraLeaseKey {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val keystoreKey = runCatching { createKeystoreAgreeKey() }
                .onFailure { error -> Log.w(TAG, "Keystore ECDH unavailable (${error.javaClass.simpleName}); using a wrapped key") }
                .getOrNull()
            if (keystoreKey != null && selfTest(keystoreKey)) return keystoreKey
            runCatching { deleteKeystoreAlias(AGREE_ALIAS) }
        }
        val wrapped = wrappedFile.create()
        check(selfTest(wrapped)) { "lease_key_self_test_failed" }
        return wrapped
    }

    @RequiresApi(Build.VERSION_CODES.S)
    private fun loadKeystoreAgreeKey(): UltraLeaseKey? {
        val keyStore = androidKeyStore()
        if (!keyStore.containsAlias(AGREE_ALIAS)) return null
        val privateKey = keyStore.getKey(AGREE_ALIAS, null) as? PrivateKey ?: return null
        val publicKey = keyStore.getCertificate(AGREE_ALIAS)?.publicKey as? ECPublicKey ?: return null
        return EcUltraLeaseKey(privateKey, publicKey, ANDROID_KEY_STORE)
    }

    @RequiresApi(Build.VERSION_CODES.S)
    private fun createKeystoreAgreeKey(): UltraLeaseKey {
        val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEY_STORE)
        generator.initialize(
            KeyGenParameterSpec.Builder(AGREE_ALIAS, KeyProperties.PURPOSE_AGREE_KEY)
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setUserAuthenticationRequired(false)
                .build(),
        )
        val pair = generator.generateKeyPair()
        return EcUltraLeaseKey(pair.private, pair.public as ECPublicKey, ANDROID_KEY_STORE)
    }

    private fun getOrCreateWrapKey(): SecretKey {
        val keyStore = androidKeyStore()
        (keyStore.getKey(WRAP_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEY_STORE)
        generator.init(
            KeyGenParameterSpec.Builder(
                WRAP_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setUserAuthenticationRequired(false)
                .build(),
        )
        return generator.generateKey()
    }

    private fun deleteKeystoreAlias(alias: String) {
        val keyStore = androidKeyStore()
        if (keyStore.containsAlias(alias)) keyStore.deleteEntry(alias)
    }

    private fun androidKeyStore(): KeyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }

    companion object {
        private const val TAG = "UltraLeaseKey"
        private const val ANDROID_KEY_STORE = "AndroidKeyStore"
        private const val AGREE_ALIAS = "leikwan_ultra_lease_agree_v1"
        private const val WRAP_ALIAS = "leikwan_ultra_lease_wrap_v1"
        private const val WRAPPED_KEY_FILE = "ultra_lease_key_v1.bin"
        private val lock = Any()
        @Volatile private var cached: UltraLeaseKey? = null

        /**
         * The lease key, or null when this device cannot provide one right now. The lease is then
         * requested without the header (what an older client does); the panel decides whether
         * that is still acceptable (`lease_sealing_required` once sealing is mandatory).
         */
        fun getOrNull(context: Context): UltraLeaseKey? = try {
            AndroidUltraLeaseKeyStore(context).getOrCreate()
        } catch (error: Exception) {
            Log.w(TAG, "Ultra lease key unavailable (${error.javaClass.simpleName})")
            null
        }

        /** True when [key] derives the same secret as a fresh software peer (proves it works). */
        internal fun selfTest(key: UltraLeaseKey): Boolean = runCatching {
            val generator = KeyPairGenerator.getInstance("EC")
            generator.initialize(ECGenParameterSpec("secp256r1"))
            val peer = generator.generateKeyPair()
            val ours = key.agree(P256.normalize(peer.public as ECPublicKey))
            val theirs = EcUltraLeaseKey(peer.private, peer.public as ECPublicKey)
                .agree(P256.decodePoint(key.publicKeyRaw()))
            try {
                ours.size == 32 && MessageDigest.isEqual(ours, theirs)
            } finally {
                ours.fill(0)
                theirs.fill(0)
            }
        }.getOrDefault(false)
    }
}
