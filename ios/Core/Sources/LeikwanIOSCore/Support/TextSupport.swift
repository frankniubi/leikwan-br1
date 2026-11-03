import Foundation
import CryptoKit

public enum Hashing {
    /// Lowercase hex SHA-256 of the UTF-8 bytes of `text`.
    public static func sha256Hex(_ text: String) -> String {
        return sha256Hex(Data(text.utf8))
    }

    public static func sha256Hex(_ data: Data) -> String {
        let digest = SHA256.hash(data: data)
        return digest.map { String(format: "%02x", $0) }.joined()
    }

    public static func isLowerHex64(_ value: String) -> Bool {
        guard value.utf8.count == 64 else { return false }
        for byte in value.utf8 {
            let isDigit = byte >= 48 && byte <= 57
            let isLowerHex = byte >= 97 && byte <= 102
            if !isDigit && !isLowerHex { return false }
        }
        return true
    }
}

/// Base64 decoding as permissive as the Android `decodeBase64Flexible` (standard or URL alphabet,
/// padding optional). Whitespace/newlines are ignored, which is slightly more lenient than Java.
public enum FlexibleBase64 {
    public static func decode(_ input: String) -> Data? {
        var cleaned = ""
        cleaned.reserveCapacity(input.count)
        for scalar in input.unicodeScalars {
            if scalar == " " || scalar == "\n" || scalar == "\r" || scalar == "\t" { continue }
            if scalar == "-" {
                cleaned.append("+")
            } else if scalar == "_" {
                cleaned.append("/")
            } else {
                cleaned.unicodeScalars.append(scalar)
            }
        }
        while cleaned.hasSuffix("=") { cleaned.removeLast() }
        if cleaned.isEmpty { return nil }
        for scalar in cleaned.unicodeScalars {
            let v = scalar.value
            let ok = (v >= 65 && v <= 90) || (v >= 97 && v <= 122) || (v >= 48 && v <= 57) || v == 43 || v == 47
            if !ok { return nil }
        }
        let remainder = cleaned.count % 4
        if remainder == 1 { return nil }
        if remainder > 0 {
            cleaned += String(repeating: "=", count: 4 - remainder)
        }
        return Data(base64Encoded: cleaned)
    }

    /// Decodes to a String with Java-like replacement of invalid UTF-8.
    public static func decodeString(_ input: String) -> String? {
        guard let data = decode(input) else { return nil }
        return String(decoding: data, as: UTF8.self)
    }
}

public enum PercentCoding {
    /// `java.net.URLDecoder.decode` semantics: '+' becomes a space, malformed escapes fail.
    public static func formDecode(_ value: String) -> String? {
        return percentDecode(value.replacingOccurrences(of: "+", with: " "))
    }

    /// RFC 3986 percent-decoding only ('+' is kept). Returns nil for malformed escapes.
    public static func percentDecode(_ value: String) -> String? {
        if !value.contains("%") { return value }
        return value.removingPercentEncoding
    }

    /// `URLEncoder.encode(value, UTF-8).replace("+", "%20")`.
    public static func queryValueEncode(_ value: String) -> String {
        var allowed = CharacterSet()
        allowed.insert(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-*_")
        return value.addingPercentEncoding(withAllowedCharacters: allowed) ?? value
    }
}

/// IP literal parsing/normalisation without DNS, matching `InetAddress.getHostAddress` output
/// (IPv4 dotted quad; IPv6 lowercase, uncompressed groups, IPv4-mapped collapses to IPv4).
public enum IPLiteral {
    public static func normalize(_ value: String) -> String? {
        let text = value.trimmingCharacters(in: .whitespaces)
        if text.isEmpty { return nil }
        if !text.contains(":") {
            guard let octets = parseIPv4(text) else { return nil }
            return octets.map { String($0) }.joined(separator: ".")
        }
        guard let groups = parseIPv6(text) else { return nil }
        if groups[0] == 0 && groups[1] == 0 && groups[2] == 0 && groups[3] == 0 && groups[4] == 0 && groups[5] == 0xffff {
            let a = groups[6] >> 8, b = groups[6] & 0xff, c = groups[7] >> 8, d = groups[7] & 0xff
            return "\(a).\(b).\(c).\(d)"
        }
        return groups.map { String($0, radix: 16) }.joined(separator: ":")
    }

    public static func isIPv6(_ normalized: String) -> Bool {
        return normalized.contains(":")
    }

    public static func parseIPv4(_ text: String) -> [UInt8]? {
        let parts = text.split(separator: ".", omittingEmptySubsequences: false)
        if parts.count != 4 { return nil }
        var result: [UInt8] = []
        for part in parts {
            if part.isEmpty || part.count > 3 { return nil }
            if !part.allSatisfy({ (ch: Character) -> Bool in ch >= "0" && ch <= "9" }) { return nil }
            guard let number = Int(part), number <= 255 else { return nil }
            result.append(UInt8(number))
        }
        return result
    }

    public static func parseIPv6(_ input: String) -> [UInt16]? {
        var text = input
        if let percent = text.firstIndex(of: "%") { text = String(text[..<percent]) }
        if text.isEmpty { return nil }
        let doubleColonRanges = text.components(separatedBy: "::")
        if doubleColonRanges.count > 2 { return nil }

        func parseSide(_ side: String) -> [UInt16]? {
            if side.isEmpty { return [] }
            let pieces = side.split(separator: ":", omittingEmptySubsequences: false).map(String.init)
            var groups: [UInt16] = []
            for (index, piece) in pieces.enumerated() {
                if piece.contains(".") {
                    if index != pieces.count - 1 { return nil }
                    guard let v4 = parseIPv4(piece) else { return nil }
                    groups.append(UInt16(v4[0]) << 8 | UInt16(v4[1]))
                    groups.append(UInt16(v4[2]) << 8 | UInt16(v4[3]))
                    continue
                }
                if piece.isEmpty || piece.count > 4 { return nil }
                guard let value = UInt16(piece, radix: 16) else { return nil }
                groups.append(value)
            }
            return groups
        }

        if doubleColonRanges.count == 2 {
            guard let head = parseSide(doubleColonRanges[0]), let tail = parseSide(doubleColonRanges[1]) else { return nil }
            let missing = 8 - head.count - tail.count
            if missing < 1 { return nil }
            return head + [UInt16](repeating: 0, count: missing) + tail
        }
        guard let all = parseSide(text), all.count == 8 else { return nil }
        return all
    }
}
