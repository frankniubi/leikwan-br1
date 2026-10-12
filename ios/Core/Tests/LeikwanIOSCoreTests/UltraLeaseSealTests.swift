import CryptoKit
import Foundation
import XCTest
@testable import LeikwanIOSCore

final class UltraLeaseSealTests: XCTestCase {
    // docs/specs/2026-10-02-ultra-sealed-lease-v1.md section 5.
    private let vectorScalarHex = "899847890f5f32c53126b22dc726d290a22e63fdc2bbc6dd2f063685199e5dfb"
    private let vectorPublic = "BJB5i5gLTy4P7-eKXbTqzY_-YNVJKKEaugOx14nJAuEHrTi_gY9XreyCRnuXCE86Ecgpq1pTbXRGQ0BCPfIHhJs"
    private let vectorLeaseJSON = """
    {"lease_id":"ul2_test-vector-0001","forward_id":42,"sealed":{"v":1,"alg":"ECDH-P256+HKDF-SHA256+A256GCM","kid":"c188836f4cbb9983ef0ce2f209a32767","epk":"BNcv3DbxPypDUaly9lSNYnpyfC2TFG6vjXq2CPkud-Y_eUPFQEtJv4ppTMWnLJ8FaohlckDI6vfBZQR1Ge8IdxU","iv":"ABEiM0RVZneImaq7","ct":"PBnTWzpuLWhTPFhpX86NSK30Xe3CbjjHaQOs6PSBQ0uymvpO2hvnLDuWaaTqdrVWBWORXa7dQfFmzKxr4pN6xJ6Bi-IEiHBKOE8MgpV1REPUugS3rInpQhM66G9rY1ZrWmSTysuUlhoyRo_HeRgGT5uPETJfdiOThqzck9ixD8_ykEXHkO0VstbSUnc09hBqChXJX92Jmuf_tSb-9DOgvnfxd7A8_UDn4x52VVC6HuGQwOXG11gWfhUFSI62OMIJbdvKo3u6TmeV7GHL"}}
    """
    private let vectorPlaintext = "{\"endpoint\":{\"ip\":\"203.0.113.7\",\"port\":40123},\"credential\":{\"user\":\"lg-user-1\",\"password\":\"lg-secret-1\"},\"external_uot\":{\"enabled\":true,\"sidecar_target\":\"198.51.100.9:7443\",\"token\":\"tok\"}}"

    private func hexData(_ hex: String) -> Data {
        var data = Data()
        var index = hex.startIndex
        while index < hex.endIndex {
            let next = hex.index(index, offsetBy: 2)
            data.append(UInt8(hex[index..<next], radix: 16)!)
            index = next
        }
        return data
    }

    private func vectorOpener() throws -> UltraSealedLeaseOpener {
        return try UltraSealedLeaseOpener(privateKeyRaw: hexData(vectorScalarHex))
    }

    private func vectorLease() -> [String: Any] {
        return JSONLenient.parseObject(vectorLeaseJSON)!
    }

    private func sampleLease() -> [String: Any] {
        return [
            "lease_id": "ul2_round-trip-0001",
            "forward_id": 12,
            "endpoint": ["ip": "203.0.113.9", "port": 40000] as [String: Any],
            "credential": ["user": "u-1", "password": "p/1+2"] as [String: Any],
            "external_uot": ["enabled": false] as [String: Any],
        ]
    }

    /// RFC 5869 HKDF-SHA256 written out with HMAC, independent of CryptoKit's HKDF helper.
    private func manualHKDF(ikm: Data, salt: Data, info: Data, length: Int) -> Data {
        let prk = Data(HMAC<SHA256>.authenticationCode(for: ikm, using: SymmetricKey(data: salt)))
        var output = Data()
        var previous = Data()
        var counter: UInt8 = 1
        while output.count < length {
            var block = previous
            block.append(info)
            block.append(counter)
            previous = Data(HMAC<SHA256>.authenticationCode(for: block, using: SymmetricKey(data: prk)))
            output.append(previous)
            counter += 1
        }
        return output.prefix(length)
    }

    /// Seals exactly like the PHP panel, but with a hand-written key schedule.
    private func manualSeal(plaintext: Data, devicePublic: Data, leaseId: String) throws -> [String: Any] {
        let ephemeral = P256.KeyAgreement.PrivateKey()
        let epk = ephemeral.publicKey.x963Representation
        let shared = try ephemeral.sharedSecretFromKeyAgreement(with: P256.KeyAgreement.PublicKey(x963Representation: devicePublic))
        let z = shared.withUnsafeBytes { Data($0) }
        XCTAssertEqual(z.count, 32)
        var salt = epk
        salt.append(devicePublic)
        let keyBytes = manualHKDF(ikm: z, salt: salt, info: Data("leikwan-ultra-lease-v1".utf8), length: 32)
        let iv = Data((0..<12).map { UInt8($0 * 7 & 0xff) })
        let box = try AES.GCM.seal(
            plaintext,
            using: SymmetricKey(data: keyBytes),
            nonce: AES.GCM.Nonce(data: iv),
            authenticating: Data(("leikwan-ultra-lease-v1|" + leaseId).utf8)
        )
        var blob = Data(box.ciphertext)
        blob.append(box.tag)
        return [
            "v": 1,
            "alg": "ECDH-P256+HKDF-SHA256+A256GCM",
            "kid": String(Hashing.sha256Hex(devicePublic).prefix(32)),
            "epk": UltraLeaseSealFormat.base64URLEncode(epk),
            "iv": UltraLeaseSealFormat.base64URLEncode(iv),
            "ct": UltraLeaseSealFormat.base64URLEncode(blob),
        ]
    }

    private func assertUnsealFails(_ lease: [String: Any], opener: UltraSealedLeaseOpener, file: StaticString = #filePath, line: UInt = #line) {
        XCTAssertThrowsError(try opener.openLease(lease), file: file, line: line) { error in
            XCTAssertEqual(error as? UltraLeaseError, UltraLeaseError("lease_unseal_failed"), file: file, line: line)
        }
    }

    private func flipCharacter(_ text: String, at offset: Int) -> String {
        var characters = Array(text)
        let index = min(max(offset, 0), characters.count - 1)
        characters[index] = characters[index] == "A" ? "B" : "A"
        return String(characters)
    }

    // MARK: Known-answer vector

    func testVectorPublicKeyHeaderAndKid() throws {
        let opener = try vectorOpener()
        XCTAssertEqual(opener.publicKeyHeader, vectorPublic)
        XCTAssertEqual(opener.leaseKeyHeaderValue(), vectorPublic)
        XCTAssertEqual(opener.publicKeyRaw.count, 65)
        XCTAssertEqual(opener.publicKeyRaw.first, 0x04)
        XCTAssertEqual(opener.keyId, "c188836f4cbb9983ef0ce2f209a32767")
        XCTAssertTrue(opener.selfTest())
    }

    func testVectorOpensToExpectedPlaintext() throws {
        let opener = try vectorOpener()
        let lease = vectorLease()
        let plaintext = try opener.openPlaintext(sealed: lease["sealed"] as! [String: Any], leaseId: "ul2_test-vector-0001")
        XCTAssertEqual(String(decoding: plaintext, as: UTF8.self), vectorPlaintext)

        let merged = try opener.openLease(lease)
        XCTAssertNil(merged["sealed"])
        XCTAssertEqual(merged["lease_id"] as? String, "ul2_test-vector-0001")
        XCTAssertEqual(JSONLenient.int64(merged["forward_id"]), 42)
        let endpoint = merged["endpoint"] as? [String: Any]
        XCTAssertEqual(endpoint?["ip"] as? String, "203.0.113.7")
        XCTAssertEqual(JSONLenient.int64(endpoint?["port"]), 40123)
        let credential = merged["credential"] as? [String: Any]
        XCTAssertEqual(credential?["user"] as? String, "lg-user-1")
        XCTAssertEqual(credential?["password"] as? String, "lg-secret-1")
        XCTAssertNotNil(merged["external_uot"] as? [String: Any])
    }

    func testVectorTamperingFails() throws {
        let opener = try vectorOpener()
        let lease = vectorLease()
        let sealed = lease["sealed"] as! [String: Any]
        for field in ["ct", "iv", "epk"] {
            var tampered = sealed
            let text = sealed[field] as! String
            tampered[field] = flipCharacter(text, at: text.count / 2)
            var copy = lease
            copy["sealed"] = tampered
            assertUnsealFails(copy, opener: opener)
        }
        var wrongId = lease
        wrongId["lease_id"] = "ul2_test-vector-0002"
        assertUnsealFails(wrongId, opener: opener)
    }

    func testEnvelopeHeaderChecks() throws {
        let opener = try vectorOpener()
        let lease = vectorLease()
        let sealed = lease["sealed"] as! [String: Any]
        let mutations: [(String, Any)] = [
            ("v", 2), ("v", "1"), ("alg", "ECDH-P384"), ("kid", "00000000000000000000000000000000"),
            ("iv", "ABEiM0RVZneI"), ("ct", "AAAA"), ("epk", "not base64!"),
        ]
        for (field, value) in mutations {
            var envelope = sealed
            envelope[field] = value
            var copy = lease
            copy["sealed"] = envelope
            assertUnsealFails(copy, opener: opener)
        }
        var notObject = lease
        notObject["sealed"] = "sealed"
        assertUnsealFails(notObject, opener: opener)
        var noLeaseId = lease
        noLeaseId.removeValue(forKey: "lease_id")
        assertUnsealFails(noLeaseId, opener: opener)
    }

    // MARK: Round trip

    func testRoundTripThroughReferenceSealer() throws {
        let opener = UltraSealedLeaseOpener(privateKey: P256.KeyAgreement.PrivateKey())
        let lease = sampleLease()
        let sealedLease = try UltraLeaseSealFormat.sealLease(lease, devicePublicKey: opener.publicKeyRaw)
        XCTAssertNil(sealedLease["endpoint"])
        XCTAssertNil(sealedLease["credential"])
        XCTAssertNil(sealedLease["external_uot"])
        let envelope = sealedLease["sealed"] as? [String: Any]
        XCTAssertEqual(envelope?["kid"] as? String, opener.keyId)
        XCTAssertEqual(envelope?["alg"] as? String, "ECDH-P256+HKDF-SHA256+A256GCM")

        let opened = try opener.openLease(sealedLease)
        XCTAssertNil(opened["sealed"])
        let credential = opened["credential"] as? [String: Any]
        XCTAssertEqual(credential?["user"] as? String, "u-1")
        XCTAssertEqual(credential?["password"] as? String, "p/1+2")
        let endpoint = opened["endpoint"] as? [String: Any]
        XCTAssertEqual(endpoint?["ip"] as? String, "203.0.113.9")
        XCTAssertEqual(JSONLenient.int64(endpoint?["port"]), 40000)
    }

    func testOpensManuallySealedLease() throws {
        let opener = UltraSealedLeaseOpener(privateKey: P256.KeyAgreement.PrivateKey())
        let plaintext = Data("{\"endpoint\":{\"ip\":\"198.51.100.1\",\"port\":1},\"credential\":{\"user\":\"a\",\"password\":\"b\"}}".utf8)
        var lease: [String: Any] = ["lease_id": "ul2_manual-0001", "forward_id": 7]
        lease["sealed"] = try manualSeal(plaintext: plaintext, devicePublic: opener.publicKeyRaw, leaseId: "ul2_manual-0001")
        let opened = try opener.openLease(lease)
        XCTAssertEqual((opened["credential"] as? [String: Any])?["user"] as? String, "a")

        // Same envelope, tampered ciphertext: GCM must refuse it.
        var envelope = lease["sealed"] as! [String: Any]
        let ct = envelope["ct"] as! String
        envelope["ct"] = flipCharacter(ct, at: 3)
        lease["sealed"] = envelope
        assertUnsealFails(lease, opener: opener)
    }

    func testOtherDeviceCannotOpen() throws {
        let owner = UltraSealedLeaseOpener(privateKey: P256.KeyAgreement.PrivateKey())
        let other = UltraSealedLeaseOpener(privateKey: P256.KeyAgreement.PrivateKey())
        let sealedLease = try UltraLeaseSealFormat.sealLease(sampleLease(), devicePublicKey: owner.publicKeyRaw)
        assertUnsealFails(sealedLease, opener: other)
    }

    func testPlainLeasePassesThrough() throws {
        let opener = UltraSealedLeaseOpener(privateKey: P256.KeyAgreement.PrivateKey())
        let lease = sampleLease()
        let opened = try opener.openLease(lease)
        XCTAssertEqual(opened["lease_id"] as? String, "ul2_round-trip-0001")
        XCTAssertNotNil(opened["credential"])
    }

    func testBase64URLHelpers() {
        let data = Data([0xfb, 0xff, 0xfe, 0x00, 0x01])
        let encoded = UltraLeaseSealFormat.base64URLEncode(data)
        XCTAssertFalse(encoded.contains("="))
        XCTAssertFalse(encoded.contains("+"))
        XCTAssertFalse(encoded.contains("/"))
        XCTAssertEqual(UltraLeaseSealFormat.base64URLDecode(encoded), data)
        XCTAssertEqual(UltraLeaseSealFormat.base64URLDecode(encoded + "="), data)
        XCTAssertNil(UltraLeaseSealFormat.base64URLDecode("ab+c"))
        XCTAssertNil(UltraLeaseSealFormat.base64URLDecode("a"))
    }

    func testRawKeyRoundTrip() throws {
        let raw = UltraSealedLeaseOpener.generatePrivateKeyRaw()
        XCTAssertEqual(raw.count, 32)
        let first = try UltraSealedLeaseOpener(privateKeyRaw: raw)
        let second = try UltraSealedLeaseOpener(privateKeyRaw: raw)
        XCTAssertEqual(first.publicKeyHeader, second.publicKeyHeader)
        XCTAssertThrowsError(try UltraSealedLeaseOpener(privateKeyRaw: Data([1, 2, 3])))
    }
}
