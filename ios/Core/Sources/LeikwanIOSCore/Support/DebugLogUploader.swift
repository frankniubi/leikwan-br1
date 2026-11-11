import CryptoKit
import Foundation

public struct DebugCollectionMeta: Equatable {
    public let uuid: String
    public let status: String
    public let expiresAt: String

    public init(uuid: String, status: String, expiresAt: String = "") {
        self.uuid = uuid
        self.status = status
        self.expiresAt = expiresAt
    }

    public var isActive: Bool {
        status.lowercased() == "active" && DebugLogUploader.isValidCollectionUUID(uuid)
    }
}

public struct DebugLogChunk: Equatable {
    public let sequence: Int
    public let sha256: String
    public let chunkBase64: String
    public let byteCount: Int

    public init(sequence: Int, sha256: String, chunkBase64: String, byteCount: Int) {
        self.sequence = sequence
        self.sha256 = sha256
        self.chunkBase64 = chunkBase64
        self.byteCount = byteCount
    }
}

/// Redacts credentials and IP literals from iOS client logs and splits them into
/// 256 KiB SHA-256-verified chunks for `POST api/client/debug/log`.
public enum DebugLogUploader {
    public static let chunkBytes = 256 * 1024
    public static let maxBundleBytes = 4 * 1024 * 1024

    private static let uuidRegex = try! NSRegularExpression(
        pattern: "^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
        options: [.caseInsensitive]
    )
    private static let bearerRegex = try! NSRegularExpression(
        pattern: "(authorization\\s*:\\s*bearer\\s+)[^\\s,;]+",
        options: [.caseInsensitive]
    )
    private static let secretRegex = try! NSRegularExpression(
        pattern: "(\\b(?:password|passwd|secret|token|agent_token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\\b\\s*[:=]\\s*)(?:\"[^\"]*\"|'[^']*'|[^\\s,;&]+)",
        options: [.caseInsensitive]
    )
    private static let querySecretRegex = try! NSRegularExpression(
        pattern: "([?&](?:token|password|secret|credential|key)=)[^&\\s]+",
        options: [.caseInsensitive]
    )
    private static let ipv4Regex = try! NSRegularExpression(
        pattern: "(?<![A-Za-z0-9])(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}(?![A-Za-z0-9])",
        options: []
    )
    private static let ipv6CandidateRegex = try! NSRegularExpression(
        pattern: "(?<![A-Za-z0-9])(?:[0-9A-Fa-f]{0,4}:){2,}[0-9A-Fa-f:.]*(?![A-Za-z0-9])",
        options: []
    )

    public static func isValidCollectionUUID(_ uuid: String) -> Bool {
        let trimmed = uuid.trimmingCharacters(in: .whitespacesAndNewlines)
        let range = NSRange(trimmed.startIndex..<trimmed.endIndex, in: trimmed)
        return uuidRegex.firstMatch(in: trimmed, options: [], range: range) != nil
    }

    public static func redact(_ value: String) -> String {
        var text = value.replacingOccurrences(of: "\0", with: "")
        text = replace(bearerRegex, in: text, with: "$1[REDACTED]")
        text = replace(secretRegex, in: text, with: "$1[REDACTED]")
        text = replace(querySecretRegex, in: text, with: "$1[REDACTED]")
        text = replace(ipv4Regex, in: text, with: "[REDACTED]")

        let matches = ipv6CandidateRegex.matches(
            in: text,
            options: [],
            range: NSRange(text.startIndex..<text.endIndex, in: text)
        )
        for match in matches.reversed() {
            guard let swiftRange = Range(match.range, in: text) else { continue }
            let candidate = String(text[swiftRange]).trimmingCharacters(in: CharacterSet(charactersIn: "[]"))
            if IPLiteral.normalize(candidate)?.contains(":") == true {
                text.replaceSubrange(swiftRange, with: "[REDACTED]")
            }
        }
        return text
    }

    public static func buildChunks(fromRawText rawText: String) -> [DebugLogChunk] {
        var sanitized = Data(redact(rawText).utf8)
        if sanitized.isEmpty {
            sanitized = Data("=== empty log ===\n".utf8)
        }
        if sanitized.count > maxBundleBytes {
            sanitized = sanitized.prefix(maxBundleBytes)
        }
        var chunks: [DebugLogChunk] = []
        var offset = 0
        var seq = 0
        while offset < sanitized.count {
            let end = min(offset + chunkBytes, sanitized.count)
            let slice = sanitized.subdata(in: offset..<end)
            let sha = SHA256.hash(data: slice).map { String(format: "%02x", $0) }.joined()
            chunks.append(DebugLogChunk(
                sequence: seq,
                sha256: sha,
                chunkBase64: slice.base64EncodedString(),
                byteCount: slice.count
            ))
            seq += 1
            offset = end
        }
        return chunks
    }

    private static func replace(_ regex: NSRegularExpression, in text: String, with template: String) -> String {
        let range = NSRange(text.startIndex..<text.endIndex, in: text)
        return regex.stringByReplacingMatches(in: text, options: [], range: range, withTemplate: template)
    }
}

public extension PullConfigsResponse {
    var debugCollection: DebugCollectionMeta? {
        guard let clientObj = client as? [String: Any],
              let coll = clientObj["debug_collection"] as? [String: Any]
        else { return nil }
        let uuid = (coll["uuid"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let status = (coll["status"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let expiresAt = coll["expires_at"] as? String ?? ""
        guard !uuid.isEmpty else { return nil }
        return DebugCollectionMeta(uuid: uuid, status: status, expiresAt: expiresAt)
    }
}
