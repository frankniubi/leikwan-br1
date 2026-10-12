import Foundation

/// Outcome of a paste / QR import (port of `proxy/ImportRouter.kt`).
/// Unlike Android, socks:// and socks5:// lines are kept in mixed pastes.
public struct ImportResult: Equatable {
    public var nodes: [ProxyNode]
    public var subscriptionURLs: [String]
    /// Non-empty lines that were neither an importable node nor a subscription URL
    /// (unsupported schemes like vmess/vless/trojan/wg, malformed URIs, junk).
    public var skippedLines: Int

    public init(nodes: [ProxyNode], subscriptionURLs: [String], skippedLines: Int) {
        self.nodes = nodes
        self.subscriptionURLs = subscriptionURLs
        self.skippedLines = skippedLines
    }

    public var isEmpty: Bool { return nodes.isEmpty && subscriptionURLs.isEmpty }
    public var isMixed: Bool { return !nodes.isEmpty && !subscriptionURLs.isEmpty }
}

public enum ImportRouter {
    /// Node schemes recognised line-by-line on iOS.
    public static let supportedNodeSchemes = ["ss://", "socks://", "socks5://"]

    public static func process(_ text: String) -> ImportResult {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.isEmpty { return ImportResult(nodes: [], subscriptionURLs: [], skippedLines: 0) }

        var nodes: [ProxyNode] = []
        var urls: [String] = []
        var skipped = 0
        let lines = ProxyUriParser.lines(of: trimmed)
        for line in lines {
            let lower = line.lowercased()
            if supportedNodeSchemes.contains(where: { lower.hasPrefix($0) }) {
                if let node = ProxyUriParser.parse(line) {
                    nodes.append(node)
                } else {
                    skipped += 1
                }
            } else if lower.hasPrefix("http://") || lower.hasPrefix("https://") {
                urls.append(line)
            } else {
                skipped += 1
            }
        }

        // Nothing matched line by line: maybe a base64 subscription body was pasted.
        if nodes.isEmpty && urls.isEmpty {
            let decoded = ProxyUriParser.parseMultiple(trimmed)
            if !decoded.nodes.isEmpty {
                return ImportResult(nodes: decoded.nodes, subscriptionURLs: [], skippedLines: decoded.skippedLines)
            }
        }
        return ImportResult(nodes: nodes, subscriptionURLs: urls, skippedLines: skipped)
    }

    public static func looksImportable(_ text: String) -> Bool {
        let lower = text.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return supportedNodeSchemes.contains(where: { lower.hasPrefix($0) })
            || lower.hasPrefix("http://")
            || lower.hasPrefix("https://")
    }

    /// Subscription response body -> nodes (Android `SubscriptionFetcher.fetch` parsing step).
    /// Fetching is left to the app: use a direct (non-proxied) URLSession with UA "LeiKwanBridge/0.1.0".
    public static func parseSubscriptionBody(_ body: String) -> ProxyParseResult {
        return ProxyUriParser.parseMultiple(body)
    }

    public static let subscriptionUserAgent = "LeiKwanBridge/0.1.0"
}
