package com.leikwan.bridge.security

import java.security.ProviderException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Test

class AndroidAttestationFallbackTest {
    @Test
    fun `provider failure during StrongBox generation falls back to TEE`() {
        val attempts = mutableListOf<Boolean>()
        val teeMaterial = FakeAttestationKeyMaterial()

        val result = createAttestationKeyWithFallback(sdkInt = 28) { strongBox ->
            attempts += strongBox
            if (strongBox) throw ProviderException("ATTESTATION_KEYS_NOT_PROVISIONED")
            teeMaterial
        }

        assertSame(teeMaterial, result)
        assertEquals(listOf(true, false), attempts)
    }

    @Test
    fun `unclassified StrongBox generation failure falls back to TEE`() {
        val attempts = mutableListOf<Boolean>()
        val teeMaterial = FakeAttestationKeyMaterial()

        val result = createAttestationKeyWithFallback(sdkInt = 28) { strongBox ->
            attempts += strongBox
            if (strongBox) throw IllegalStateException("StrongBox key generation failed")
            teeMaterial
        }

        assertSame(teeMaterial, result)
        assertEquals(listOf(true, false), attempts)
    }

    private class FakeAttestationKeyMaterial : AttestationKeyMaterial {
        override val certificateChain: List<ByteArray> = listOf(byteArrayOf(1))
        override val hardwareBacked: Boolean = true
        override fun sign(message: ByteArray): ByteArray = byteArrayOf(1)
        override fun close() = Unit
    }

    private fun softwareProof(allowQa: Boolean): Pair<AndroidAttestationProofFactory, String> {
        val identity = AndroidDeviceIdentity(
            object : IdentityKeyStore {
                override fun getOrCreatePublicKeySpki(): ByteArray = "qa-fixture-spki".toByteArray()
                override fun delete() = Unit
            },
        )
        val softwareProvider = object : AttestationKeyProvider {
            override fun create(challenge: ByteArray): AttestationKeyMaterial = object : AttestationKeyMaterial {
                override val certificateChain: List<ByteArray> = listOf("software-leaf".toByteArray())
                override val hardwareBacked: Boolean = false
                override fun sign(message: ByteArray): ByteArray =
                    java.security.MessageDigest.getInstance("SHA-256").digest(message)

                override fun close() = Unit
            }
        }
        return Pair(
            AndroidAttestationProofFactory(
                identity,
                softwareProvider,
                allowQaTestAttestation = allowQa,
                errorLogger = { _, _ -> },
            ),
            identity.deviceId(13L),
        )
    }

    @Test
    fun `software attestation proof is rejected without qa test exception`() {
        val (factory, deviceId) = softwareProof(allowQa = false)
        try {
            factory.create("nonce-q", 13L, deviceId)
            throw AssertionError("expected software attestation to be rejected")
        } catch (expected: IllegalStateException) {
            assertEquals("hardware-backed attestation is required", expected.message)
        }
    }

    @Test
    fun `qa test exception allows software attestation proof`() {
        val (factory, deviceId) = softwareProof(allowQa = true)
        val proof = factory.create("nonce-q", 13L, deviceId)

        assertEquals(1, proof.certificateChain.size)
    }
}
