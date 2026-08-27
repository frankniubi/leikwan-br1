package com.leikwan.bridge.security

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.security.MessageDigest

class AndroidDeviceIdentityTest {
    private class FixtureIdentityKeyStore(
        private val spki: ByteArray = "fixture-stable-spki".toByteArray(),
    ) : IdentityKeyStore {
        var deleted = false

        override fun getOrCreatePublicKeySpki(): ByteArray = spki.copyOf()

        override fun delete() {
            deleted = true
        }
    }

    @Test
    fun `device ID is stable and scoped by client ID`() {
        val identity = AndroidDeviceIdentity(FixtureIdentityKeyStore())
        val first = identity.deviceId(17L)

        assertEquals(first, identity.deviceId(17L))
        assertNotEquals(first, identity.deviceId(18L))
        assertTrue(first.matches(Regex("android-client-17-[a-f0-9]{32}")))
    }

    @Test
    fun `device ID suffix is the stable SPKI hash prefix`() {
        val spki = "another-fixture-spki".toByteArray()
        val identity = AndroidDeviceIdentity(FixtureIdentityKeyStore(spki))
        val expected = MessageDigest.getInstance("SHA-256")
            .digest(spki)
            .joinToString("") { "%02x".format(it) }
            .take(32)

        assertEquals("android-client-7-$expected", identity.deviceId(7L))
    }

    @Test(expected = IllegalArgumentException::class)
    fun `device ID rejects invalid client ID`() {
        AndroidDeviceIdentity(FixtureIdentityKeyStore()).deviceId(0L)
    }

    @Test
    fun `clear deletes the identity key`() {
        val keyStore = FixtureIdentityKeyStore()
        AndroidDeviceIdentity(keyStore).clear()
        assertTrue(keyStore.deleted)
    }

    @Test
    fun `attestation proof binds nonce client device and identity hash`() {
        val identity = AndroidDeviceIdentity(FixtureIdentityKeyStore())
        val provider = object : AttestationKeyProvider {
            override fun create(challenge: ByteArray): AttestationKeyMaterial {
                assertEquals("nonce-a", challenge.toString(Charsets.UTF_8))
                return object : AttestationKeyMaterial {
                    override val certificateChain: List<ByteArray> = listOf("leaf-cert".toByteArray())
                    override val hardwareBacked: Boolean = true
                    override fun sign(message: ByteArray): ByteArray = MessageDigest.getInstance("SHA-256").digest(message)
                    override fun close() = Unit
                }
            }
        }
        val factory = AndroidAttestationProofFactory(identity, provider)
        val deviceId = identity.deviceId(17L)
        val proof = factory.create("nonce-a", 17L, deviceId)
        val identityHash = MessageDigest.getInstance("SHA-256")
            .digest(identity.publicKeySpki())
            .joinToString("") { "%02x".format(it) }
        val expectedMessage = "nonce-a|17|$deviceId|$identityHash".toByteArray()

        assertEquals(1, proof.certificateChain.size)
        assertEquals(
            MessageDigest.getInstance("SHA-256").digest(expectedMessage).toList(),
            java.util.Base64.getDecoder().decode(proof.bindingSignature).toList(),
        )
    }
}
