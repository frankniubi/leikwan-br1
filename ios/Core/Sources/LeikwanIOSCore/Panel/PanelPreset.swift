import Foundation

/// The two panel addresses built into the app. Each one is the other's backup: when the address
/// a user picked cannot be reached, the client falls back to the other one. A user never types a
/// backup; a custom server address has none.
public enum PanelPreset: CaseIterable, Hashable {
    case cloudflare
    case direct

    public var title: String {
        switch self {
        case .cloudflare: return "Cloudflare CDN"
        case .direct: return "Directly CDN"
        }
    }

    public var endpoint: PanelEndpoint {
        // Constant addresses already in normalized form; PanelTests pins both values.
        switch self {
        case .cloudflare: return PanelEndpoint(storage: "https://v3.leikwanhost.com")!
        case .direct: return PanelEndpoint(storage: "https://api-leikwanbridge.nbwly.com")!
        }
    }

    /// The address tried after this preset's own address fails.
    public var backup: PanelEndpoint {
        switch self {
        case .cloudflare: return PanelPreset.direct.endpoint
        case .direct: return PanelPreset.cloudflare.endpoint
        }
    }

    /// The preset an address belongs to, or nil for a custom server.
    public static func matching(_ server: PanelEndpoint) -> PanelPreset? {
        return allCases.first { $0.endpoint == server }
    }

    /// The built-in backup for a server address: the other preset's address, or nil for a custom
    /// server.
    public static func backup(for server: PanelEndpoint) -> PanelEndpoint? {
        return matching(server)?.backup
    }
}
