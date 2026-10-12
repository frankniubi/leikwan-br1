import Foundation

/// Allowlisted panel origins (port of `data/PanelEndpoint.kt`).
public enum PanelEndpoint: String, CaseIterable, Equatable {
    case cloudflare = "cloudflare"
    case aliyunCDN = "aliyun_cdn"

    public static let defaultEndpoint: PanelEndpoint = .cloudflare
    /// Panel mirror host that must also stay direct (Android `SafePathRegistry` baseline).
    public static let mirrorHost = "v3-mirror.leikwanhost.com"

    public var urlString: String {
        switch self {
        case .cloudflare: return "https://v3.leikwanhost.com"
        case .aliyunCDN: return "https://api-leikwanbridge.nbwly.com"
        }
    }

    public var baseURL: URL {
        // Both literals are valid absolute URLs.
        return URL(string: urlString)!
    }

    public var host: String {
        return baseURL.host ?? ""
    }

    public var storageKey: String { return rawValue }

    /// The other endpoint, used for transport-failure failover.
    public var alternate: PanelEndpoint {
        return self == .cloudflare ? .aliyunCDN : .cloudflare
    }

    public static func fromStorage(_ value: String?) -> PanelEndpoint {
        let normalized = (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return PanelEndpoint(rawValue: normalized) ?? defaultEndpoint
    }

    /// Every panel host plus the mirror; all are routed direct by the sing-box config.
    public static var allProtectedHosts: [String] {
        return PanelEndpoint.allCases.map { $0.host } + [mirrorHost]
    }
}

/// Stable iOS device id: `ios-client-<clientId>-<hash16>`, hash16 = first 16 lowercase hex chars of
/// SHA-256("ios|<clientId>|<installId>"). Mirrors Windows `BuildWindowsDeviceId`: the install id is
/// trimmed and lowercased, and a blank install id becomes "unknown-install".
public enum IOSDeviceIdentity {
    public static func normalizedInstallId(_ installId: String) -> String {
        let trimmed = installId.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return trimmed.isEmpty ? "unknown-install" : trimmed
    }

    public static func deviceId(clientId: Int64, installId: String) -> String {
        let material = "ios|\(clientId)|\(normalizedInstallId(installId))"
        let hash16 = String(Hashing.sha256Hex(material).prefix(16))
        return "ios-client-\(clientId)-\(hash16)"
    }

    /// Strips CR/LF, trims, caps at 128 characters; nil when blank (header omitted).
    public static func sanitizeDeviceName(_ value: String) -> String? {
        let replaced = value.replacingOccurrences(of: "\r", with: " ").replacingOccurrences(of: "\n", with: " ")
        let trimmed = replaced.trimmingCharacters(in: .whitespacesAndNewlines)
        let capped = String(trimmed.prefix(128))
        return capped.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : capped
    }
}

/// Login material (Client ID + Agent Token) plus device facts sent on every request.
public struct PanelCredentials: Equatable {
    public var clientId: Int64
    public var agentToken: String
    /// Stable random string kept in the Keychain by the app.
    public var installId: String
    public var deviceName: String
    public var appVersion: String

    public init(clientId: Int64, agentToken: String, installId: String, deviceName: String, appVersion: String) {
        self.clientId = clientId
        self.agentToken = agentToken
        self.installId = installId
        self.deviceName = deviceName
        self.appVersion = appVersion
    }

    public var deviceId: String {
        return IOSDeviceIdentity.deviceId(clientId: clientId, installId: installId)
    }

    /// Headers attached to every panel request.
    public func headers() -> [String: String] {
        var headers: [String: String] = [
            "Authorization": "Bearer \(agentToken.trimmingCharacters(in: .whitespacesAndNewlines))",
            "X-Device-Platform": "ios",
            "X-Client-Version": appVersion,
            "User-Agent": "LeiKwan-iOS/\(appVersion)",
            "X-Device-Id": deviceId,
        ]
        if let name = IOSDeviceIdentity.sanitizeDeviceName(deviceName) {
            headers["X-Device-Name"] = name
        }
        return headers
    }
}
