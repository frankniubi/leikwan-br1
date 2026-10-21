import Foundation

/// A panel origin the user typed in (each profile has one, plus an optional backup).
/// Always `https://<host>[:port][/path]` with a lowercased authority and no trailing slash,
/// query or fragment, so equal servers compare equal.
public struct PanelEndpoint: Equatable, Hashable, Codable {
    public enum ParseError: Error, Equatable {
        case empty
        /// `http://`; the panel carries the Agent Token and must use TLS.
        case insecure
        /// `user:password@` in the address.
        case hasCredentials
        case invalid
    }

    public let urlString: String
    public let baseURL: URL

    private init(urlString: String, baseURL: URL) {
        self.urlString = urlString
        self.baseURL = baseURL
    }

    /// Normalizes what the user typed: trims it, adds `https://` when no scheme is given, and
    /// drops a trailing slash, query and fragment.
    public static func parse(_ input: String) -> Result<PanelEndpoint, ParseError> {
        var text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return .failure(.empty) }
        if let separator = text.range(of: "://") {
            let scheme = text[..<separator.lowerBound].lowercased()
            if scheme == "http" { return .failure(.insecure) }
            guard scheme == "https" else { return .failure(.invalid) }
            text = String(text[separator.upperBound...])
        }
        if let cut = text.firstIndex(where: { $0 == "?" || $0 == "#" }) {
            text = String(text[..<cut])
        }
        let slash = text.firstIndex(of: "/") ?? text.endIndex
        let authority = text[..<slash].lowercased()
        var path = String(text[slash...])
        while path.hasSuffix("/") { path.removeLast() }
        guard !authority.isEmpty, !authority.contains(where: { $0.isWhitespace }) else { return .failure(.invalid) }
        if authority.contains("@") { return .failure(.hasCredentials) }
        guard !path.contains(where: { $0.isWhitespace }) else { return .failure(.invalid) }
        let normalized = "https://" + authority + path
        guard let url = URL(string: normalized), let host = url.host, !host.isEmpty else { return .failure(.invalid) }
        return .success(PanelEndpoint(urlString: normalized, baseURL: url))
    }

    /// A previously stored `urlString`; nil for anything that is not already normalized
    /// (including the removed "cloudflare" / "aliyun_cdn" keys of older builds).
    public init?(storage: String?) {
        guard let storage = storage, case .success(let endpoint) = PanelEndpoint.parse(storage),
              endpoint.urlString == storage
        else {
            return nil
        }
        self = endpoint
    }

    /// Lowercased host without IPv6 brackets.
    public var host: String {
        var value = (baseURL.host ?? "").lowercased()
        if value.hasPrefix("[") && value.hasSuffix("]") {
            value = String(value.dropFirst().dropLast())
        }
        return value
    }

    public var storageKey: String { return urlString }

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        let value = try container.decode(String.self)
        guard let endpoint = PanelEndpoint(storage: value) else {
            throw DecodingError.dataCorruptedError(in: container, debugDescription: "not a panel address: \(value)")
        }
        self = endpoint
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(urlString)
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
            "User-Agent": "Bridge-iOS/\(appVersion)",
            "X-Device-Id": deviceId,
        ]
        if let name = IOSDeviceIdentity.sanitizeDeviceName(deviceName) {
            headers["X-Device-Name"] = name
        }
        return headers
    }
}
