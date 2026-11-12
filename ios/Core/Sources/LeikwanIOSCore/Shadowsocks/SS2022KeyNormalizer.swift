import Foundation
import CryptoKit

/// Normalize SS2022 PSK strings to the format sing-box clients accept (base64, decoded length
/// equals key length). Sing-box servers tolerate longer keys via SHA256 hash truncation; our
/// outbound configs do not. Xray accepts raw ASCII keys; we convert those to base64.
///
/// Reasoning: sing-box server-side `shadowaead_2022` allows `len(psk) > keySaltLength` and
/// hashes via `Key(psk, keySaltLength)` (SHA256 truncated). The client-side library
/// `sing-shadowsocks2` rejects that with `bad key length`. Xray's `ParseKey` accepts either
/// base64 or raw bytes; sing-box clients only accept base64. Normalizing here to what the
/// strictest consumer (sing-box client) requires ensures maximum compatibility.
public enum SS2022KeyNormalizer {
    public struct NormalizeResult {
        public let normalized: String
        public let error: String?

        public init(normalized: String, error: String? = nil) {
            self.normalized = normalized
            self.error = error
        }
    }

    /// Normalize a single PSK segment. Returns a new base64 string or an error.
    /// - Parameters:
    ///   - psk: One segment of a password string (split by ':').
    ///   - keyLength: Expected key length in bytes (16 for aes-128, 32 for aes-256/chacha20).
    public static func normalizePSK(_ psk: String, keyLength: Int) -> NormalizeResult {
        guard !psk.isEmpty else {
            return NormalizeResult(normalized: "", error: "PSK 为空")
        }

        // Attempt base64 decode. Apple's Data(base64Encoded:) requires correct
        // padding (a 43-char unpadded key returns nil), so re-pad like the C#
        // normalizer before giving up and treating the input as raw ASCII.
        var b64 = psk.trimmingCharacters(in: .whitespaces)
        if b64.count % 4 != 0 {
            b64 += String(repeating: "=", count: 4 - b64.count % 4)
        }
        let decoded: Data
        if let b64Data = Data(base64Encoded: b64) {
            decoded = b64Data
        } else {
            // Not valid base64 → raw ASCII of exactly the required length (Xray compat)
            let scalars = psk.unicodeScalars
            let isPrintableASCII = psk.count == keyLength && scalars.allSatisfy { $0.value >= 0x20 && $0.value <= 0x7E }
            guard isPrintableASCII, let ascii = psk.data(using: .ascii) else {
                return NormalizeResult(
                    normalized: "",
                    error: "密钥无效：非法 base64 且不是 \(keyLength) 字节的可打印 ASCII"
                )
            }
            decoded = ascii
        }

        switch decoded.count {
        case keyLength:
            // Exact match: re-encode to canonical padded base64
            let reencoded = decoded.base64EncodedString()
            return NormalizeResult(normalized: reencoded)
        default:
            break
        }
        if decoded.count > keyLength {
            // Over-long: hash via SHA256 and truncate (sing-box server compat)
            let sha = SHA256.hash(data: decoded)
            let truncated = Data(sha.prefix(keyLength))
            let hashed = truncated.base64EncodedString()
            return NormalizeResult(normalized: hashed)
        }
        let methodName: String
        switch keyLength {
        case 16:
            methodName = "2022-blake3-aes-128-gcm"
        case 32:
            methodName = "2022-blake3-aes-256-gcm / chacha20-poly1305"
        default:
            methodName = "SS2022"
        }
        return NormalizeResult(
            normalized: "",
            error: "密钥长度 \(decoded.count) 字节，\(methodName) 需要 \(keyLength) 字节"
        )
    }

    /// Normalize a full password string (may contain ':' for multi-user mode).
    /// - Parameters:
    ///   - password: The raw password from user input or node config.
    ///   - method: Shadowsocks method string, e.g. "2022-blake3-aes-256-gcm".
    /// - Returns: Normalized password string (all segments processed) or error.
    public static func normalizePassword(_ password: String, method: String) -> NormalizeResult {
        let keyLength: Int
        switch method.lowercased() {
        case "2022-blake3-aes-128-gcm":
            keyLength = 16
        case "2022-blake3-aes-256-gcm", "2022-blake3-chacha20-poly1305":
            keyLength = 32
        default:
            // Not an SS2022 method, pass through
            return NormalizeResult(normalized: password)
        }

        // Keep empty segments so an empty password reaches normalizePSK's
        // "PSK 为空" error (Swift's split(separator:) drops empty parts).
        let segments = password.split(separator: ":", omittingEmptySubsequences: false).map(String.init)
        var normalized: [String] = []

        for (idx, seg) in segments.enumerated() {
            let result = normalizePSK(seg, keyLength: keyLength)
            if let error = result.error {
                let position = segments.count > 1 ? "第 \(idx + 1) 段" : ""
                return NormalizeResult(normalized: "", error: "\(position)\(error)")
            }
            normalized.append(result.normalized)
        }

        return NormalizeResult(normalized: normalized.joined(separator: ":"))
    }
}
