import Foundation

/// One saved sign-in: the server address the user typed (plus an optional backup address) and
/// the Client ID. The Agent Token is not part of the profile; the app keeps it in the Keychain
/// under `tokenAccount`.
public struct PanelProfile: Codable, Equatable, Identifiable {
    public var id: UUID
    /// User label; blank shows `host · clientId`.
    public var name: String
    public var server: PanelEndpoint
    /// Tried after a transport failure of `server`.
    public var backupServer: PanelEndpoint?
    public var clientId: Int64
    /// Keep the Agent Token on this device after signing out of the profile.
    public var rememberToken: Bool

    public init(
        id: UUID = UUID(),
        name: String = "",
        server: PanelEndpoint,
        backupServer: PanelEndpoint? = nil,
        clientId: Int64,
        rememberToken: Bool = true
    ) {
        self.id = id
        self.name = name
        self.server = server
        self.backupServer = backupServer == server ? nil : backupServer
        self.clientId = clientId
        self.rememberToken = rememberToken
    }

    /// Keychain account of this profile's Agent Token.
    public var tokenAccount: String {
        return "agent_token." + id.uuidString.lowercased()
    }

    public var displayName: String {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? "\(server.host) · \(clientId)" : trimmed
    }

    /// Server + Client ID: everything the device caches for an account (cloud nodes, forward
    /// choices) belongs to this key, so a profile with another key starts from a clean slate.
    public var accountKey: String {
        return "\(server.urlString)#\(clientId)"
    }

    /// Hosts that must never go through the tunnel (sing-box direct rules).
    public var panelHosts: [String] {
        var hosts = [server.host]
        if let backup = backupServer?.host, !hosts.contains(backup) { hosts.append(backup) }
        return hosts
    }
}

/// The saved profiles and which one is signed in.
public struct PanelProfileList: Codable, Equatable {
    public var profiles: [PanelProfile]
    /// The signed-in profile; nil after signing out.
    public var activeId: UUID?

    public init(profiles: [PanelProfile] = [], activeId: UUID? = nil) {
        self.profiles = profiles
        self.activeId = activeId
    }

    public var active: PanelProfile? {
        guard let id = activeId else { return nil }
        return profiles.first { $0.id == id }
    }

    public func profile(id: UUID) -> PanelProfile? {
        return profiles.first { $0.id == id }
    }

    /// Saves `profile`: replaces the entry with the same id, else the entry for the same account
    /// (server + Client ID, which keeps that entry's id), else appends. Returns the stored profile.
    @discardableResult
    public mutating func upsert(_ profile: PanelProfile) -> PanelProfile {
        if let index = profiles.firstIndex(where: { $0.id == profile.id }) {
            profiles[index] = profile
            return profile
        }
        if let index = profiles.firstIndex(where: { $0.accountKey == profile.accountKey }) {
            var merged = profile
            merged.id = profiles[index].id
            if merged.name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                merged.name = profiles[index].name
            }
            profiles[index] = merged
            return merged
        }
        profiles.append(profile)
        return profile
    }

    public mutating func remove(id: UUID) {
        profiles.removeAll { $0.id == id }
        if activeId == id { activeId = nil }
    }
}
