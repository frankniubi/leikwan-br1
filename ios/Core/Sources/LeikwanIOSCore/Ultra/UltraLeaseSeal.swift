import Foundation
import CryptoKit

/// Ultra sealed lease v1 (docs/specs/2026-10-02-ultra-sealed-lease-v1.md, panel side
/// `master/src/Support/UltraLeaseSealer.php`, Android `security/UltraLeaseSeal.kt`).
///
/// ```
/// Z     = ECDH(device_private, epk)                      32-byte x coordinate
/// key   = HKDF-SHA256(ikm = Z, salt = epk || device_public, info = INFO, L = 32)
/// plain = AES-256-GCM-Decrypt(key, iv, ct[:-16], tag = ct[-16:], aad = INFO + "|" + lease_id)
/// ```
///
/// Never log the opened plaintext: it carries the provider endpoint and credential.
public enum UltraLeaseSealFormat {
    public static let version = 1
    public static let algorithm = "ECDH-P256+HKDF-SHA256+A256GCM"
    public static let info = "leikwan-ultra-lease-v1"
    public static let unsealFailed = "lease_unseal_failed"
    /// Lease fields that only ever travel inside the sealed envelope.
    public static let sealedFields = ["endpoint", "credential", "external_uot"]
    public static let sealedField = "sealed"

    static let ivBytes = 12
    static let tagBytes = 16
    static let keyBytes = 32
    static let rawPointBytes = 65

    /// First 32 hex chars of sha256(raw public key): the panel's `kid`.
    public static func keyId(_ rawPublicKey: Data) -> String {
        return String(Hashing.sha256Hex(rawPublicKey).prefix(32))
    }

    /// `INFO || "|" || lease_id` as UTF-8.
    public static func aad(leaseId: String) -> Data {
        return Data((info + "|" + leaseId).utf8)
    }

    public static func base64URLEncode(_ data: Data) -> String {
        var text = data.base64EncodedString()
        text = text.replacingOccurrences(of: "+", with: "-")
        text = text.replacingOccurrences(of: "/", with: "_")
        while text.hasSuffix("=") { text.removeLast() }
        return text
    }

    /// Strict base64url (padding optional), like Android's `BASE64URL` regex + URL decoder.
    public static func base64URLDecode(_ text: String) -> Data? {
        if text.range(of: "^[A-Za-z0-9_-]*={0,2}$", options: .regularExpression) == nil { return nil }
        var body = text
        while body.hasSuffix("=") { body.removeLast() }
        body = body.replacingOccurrences(of: "-", with: "+")
        body = body.replacingOccurrences(of: "_", with: "/")
        let remainder = body.count % 4
        if remainder == 1 { return nil }
        if remainder > 0 { body += String(repeating: "=", count: 4 - remainder) }
        return Data(base64Encoded: body)
    }

    /// HKDF-SHA256(ikm = shared, salt = epk || device_public, info = INFO, L = 32).
    static func derivedKey(shared: SharedSecret, epk: Data, devicePublic: Data) -> SymmetricKey {
        var salt = Data()
        salt.append(epk)
        salt.append(devicePublic)
        return shared.hkdfDerivedSymmetricKey(
            using: SHA256.self,
            salt: salt,
            sharedInfo: Data(info.utf8),
            outputByteCount: keyBytes
        )
    }

    /// Reference seal side, byte-for-byte what the panel does (`UltraLeaseSealer::seal`).
    /// The client never needs it at runtime; tests use it to prove a round trip.
    public static func seal(
        plaintext: Data,
        devicePublicKey: Data,
        leaseId: String,
        ephemeral: P256.KeyAgreement.PrivateKey = P256.KeyAgreement.PrivateKey(),
        iv: Data? = nil
    ) throws -> [String: Any] {
        let devicePublic = try P256.KeyAgreement.PublicKey(x963Representation: devicePublicKey)
        let epk = ephemeral.publicKey.x963Representation
        let shared = try ephemeral.sharedSecretFromKeyAgreement(with: devicePublic)
        let key = derivedKey(shared: shared, epk: epk, devicePublic: devicePublicKey)
        let nonce: AES.GCM.Nonce
        if let iv = iv {
            nonce = try AES.GCM.Nonce(data: iv)
        } else {
            nonce = AES.GCM.Nonce()
        }
        let box = try AES.GCM.seal(plaintext, using: key, nonce: nonce, authenticating: aad(leaseId: leaseId))
        var blob = Data()
        blob.append(box.ciphertext)
        blob.append(box.tag)
        let nonceData = nonce.withUnsafeBytes { Data($0) }
        return [
            "v": version,
            "alg": algorithm,
            "kid": keyId(devicePublicKey),
            "epk": base64URLEncode(epk),
            "iv": base64URLEncode(nonceData),
            "ct": base64URLEncode(blob),
        ]
    }

    /// Moves `sealedFields` out of `lease` into a `sealed` envelope (`UltraLeaseSealer::sealLease`).
    public static func sealLease(
        _ lease: [String: Any],
        devicePublicKey: Data,
        ephemeral: P256.KeyAgreement.PrivateKey = P256.KeyAgreement.PrivateKey(),
        iv: Data? = nil
    ) throws -> [String: Any] {
        var output = lease
        var secret: [String: Any] = [:]
        for field in sealedFields {
            if let value = output[field] {
                secret[field] = value
                output.removeValue(forKey: field)
            }
        }
        let plaintext = try JSONSerialization.data(withJSONObject: secret, options: [.withoutEscapingSlashes])
        let leaseId = (lease["lease_id"] as? String) ?? ""
        output[sealedField] = try seal(
            plaintext: plaintext,
            devicePublicKey: devicePublicKey,
            leaseId: leaseId,
            ephemeral: ephemeral,
            iv: iv
        )
        return output
    }
}

/// The device side of sealed leases. The caller owns persistence of the private key
/// (the packet tunnel keeps its raw representation in its own Keychain).
public final class UltraSealedLeaseOpener: UltraLeaseSealer {
    private let privateKey: P256.KeyAgreement.PrivateKey
    /// The 65-byte uncompressed SEC1 point `0x04 || X || Y`.
    public let publicKeyRaw: Data

    public init(privateKey: P256.KeyAgreement.PrivateKey) {
        self.privateKey = privateKey
        self.publicKeyRaw = privateKey.publicKey.x963Representation
    }

    /// `rawRepresentation` is the 32-byte private scalar.
    public convenience init(privateKeyRaw: Data) throws {
        self.init(privateKey: try P256.KeyAgreement.PrivateKey(rawRepresentation: privateKeyRaw))
    }

    /// A fresh private key, as the 32-byte scalar to persist.
    public static func generatePrivateKeyRaw() -> Data {
        return P256.KeyAgreement.PrivateKey().rawRepresentation
    }

    /// `X-Leikwan-Lease-Key` value: base64url, no padding, of the 65-byte public point.
    public var publicKeyHeader: String {
        return UltraLeaseSealFormat.base64URLEncode(publicKeyRaw)
    }

    public var keyId: String {
        return UltraLeaseSealFormat.keyId(publicKeyRaw)
    }

    public func leaseKeyHeaderValue() -> String? {
        return publicKeyHeader
    }

    /// True when this key derives the same secret as a fresh peer (the stored key works).
    public func selfTest() -> Bool {
        let peer = P256.KeyAgreement.PrivateKey()
        guard let ours = try? privateKey.sharedSecretFromKeyAgreement(with: peer.publicKey),
              let devicePublic = try? P256.KeyAgreement.PublicKey(x963Representation: publicKeyRaw),
              let theirs = try? peer.sharedSecretFromKeyAgreement(with: devicePublic)
        else { return false }
        let left = ours.withUnsafeBytes { Data($0) }
        let right = theirs.withUnsafeBytes { Data($0) }
        return left.count == 32 && left == right
    }

    /// Returns `lease` unchanged when it carries no `sealed` envelope; otherwise a copy with the
    /// opened fields merged in and `sealed` removed. Every failure is `lease_unseal_failed`.
    public func openLease(_ lease: [String: Any]) throws -> [String: Any] {
        guard let sealedValue = lease[UltraLeaseSealFormat.sealedField] else { return lease }
        guard let sealed = sealedValue as? [String: Any],
              let leaseId = lease["lease_id"] as? String,
              let plaintext = try? openPlaintext(sealed: sealed, leaseId: leaseId),
              let opened = JSONLenient.parseObject(plaintext)
        else {
            throw UltraLeaseError(UltraLeaseSealFormat.unsealFailed)
        }
        var merged = lease
        merged.removeValue(forKey: UltraLeaseSealFormat.sealedField)
        for (name, value) in opened {
            merged[name] = value
        }
        return merged
    }

    /// Opens one envelope and returns the raw plaintext bytes.
    public func openPlaintext(sealed: [String: Any], leaseId: String) throws -> Data {
        let failure = UltraLeaseError(UltraLeaseSealFormat.unsealFailed)
        guard let version = sealed["v"] as? NSNumber, !JSONLenient.isBoolean(version),
              version.stringValue == String(UltraLeaseSealFormat.version)
        else { throw failure }
        guard (sealed["alg"] as? String) == UltraLeaseSealFormat.algorithm else { throw failure }
        guard (sealed["kid"] as? String) == keyId else { throw failure }
        guard let epkText = sealed["epk"] as? String, let epk = UltraLeaseSealFormat.base64URLDecode(epkText),
              let ivText = sealed["iv"] as? String, let iv = UltraLeaseSealFormat.base64URLDecode(ivText),
              let ctText = sealed["ct"] as? String, let blob = UltraLeaseSealFormat.base64URLDecode(ctText)
        else { throw failure }
        if epk.count != UltraLeaseSealFormat.rawPointBytes || iv.count != UltraLeaseSealFormat.ivBytes
            || blob.count < UltraLeaseSealFormat.tagBytes {
            throw failure
        }
        do {
            let peer = try P256.KeyAgreement.PublicKey(x963Representation: epk)
            let shared = try privateKey.sharedSecretFromKeyAgreement(with: peer)
            let key = UltraLeaseSealFormat.derivedKey(shared: shared, epk: epk, devicePublic: publicKeyRaw)
            let split = blob.count - UltraLeaseSealFormat.tagBytes
            let ciphertext = blob.prefix(split)
            let tag = blob.suffix(UltraLeaseSealFormat.tagBytes)
            let box = try AES.GCM.SealedBox(nonce: AES.GCM.Nonce(data: iv), ciphertext: ciphertext, tag: tag)
            return try AES.GCM.open(box, using: key, authenticating: UltraLeaseSealFormat.aad(leaseId: leaseId))
        } catch {
            // Collapse every cause into one code: cause text could echo envelope bytes into logs.
            throw failure
        }
    }
}
