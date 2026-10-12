import Foundation

/// What the app hands the packet tunnel when it starts or reloads it.
///
/// The app plans everything (sing-box config, runtime argument vectors) with
/// LeikwanIOSCore; the tunnel only executes the plan, so the tunnel stays small
/// and the logic stays unit-testable.
struct TunnelLaunchOptions: Codable, Equatable {
    static let optionKey = "launch"

    /// sing-box configuration JSON.
    var configContent: String
    /// Runtimes to start before sing-box, in order.
    var runtimes: [RuntimeLaunch]
    /// Panel session the tunnel uses for heartbeats and ultra lease renewal
    /// while the app is in the background. Nil for direct (no-forward) use.
    var panel: TunnelPanelSession?
    /// Ultra forwards whose runtimes need leases; the tunnel's UltraLeaseManager owns them.
    var ultra: [UltraForwardLaunch] = []

    enum CodingKeys: String, CodingKey {
        case configContent
        case runtimes
        case panel
        case ultra
    }

    func encoded() throws -> Data {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        return try encoder.encode(self)
    }

    static func decode(_ data: Data) throws -> TunnelLaunchOptions {
        try JSONDecoder().decode(TunnelLaunchOptions.self, from: data)
    }
}

extension TunnelLaunchOptions {
    /// Tolerates a launch snapshot written before `ultra` existed (app upgrade, then an
    /// on-demand start of the tunnel from the old snapshot). Declared in an extension so the
    /// memberwise initializer stays available.
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        configContent = try container.decode(String.self, forKey: .configContent)
        runtimes = try container.decode([RuntimeLaunch].self, forKey: .runtimes)
        panel = try container.decodeIfPresent(TunnelPanelSession.self, forKey: .panel)
        ultra = try container.decodeIfPresent([UltraForwardLaunch].self, forKey: .ultra) ?? []
    }
}

/// What the tunnel needs to keep one Ultra runtime supplied with leases.
struct UltraForwardLaunch: Codable, Equatable {
    var forwardID: Int64
    /// RuntimeLaunch.name of the runtime this entry feeds.
    var runtimeName: String
    /// Forward fields `UltraPolicy.from` validates against (target digest, mode, status).
    var target: String
    var backendMode: String
    var serviceStatus: String
    var listenPort: Int
    /// Canonical JSON of the forward's `ultra_access` object at plan time. The tunnel uses it
    /// for the first lease while it is still valid, then refreshes it with configs/pull.
    var ultraAccessJSON: String
    /// Static bindings baked into the runtime config; a policy that changes them needs a re-plan.
    var targetDigest: String
    var transport: String
    var controlSocketPath: String
    var statusPath: String
    /// Lease candidates to request (Android: 4, `selection_mode` "auto" when > 1).
    var maxCandidates: Int
    /// The runtime config has `auto_select` on (it probes a multi-candidate batch before answering).
    var autoSelect: Bool
}

struct RuntimeLaunch: Codable, Equatable {
    enum Kind: String, Codable {
        case ingress
        case bond
        case ultra
    }

    /// Stable label for logs and status ("fwd-12-ingress-3").
    var name: String
    var kind: Kind
    /// Argument vector for ingress/bond, exactly as Android passes it to the
    /// binary minus the program name.
    var arguments: [String]
    /// Runtime configuration JSON for ultra.
    var configJSON: String?
    /// Files the runtime reads (bond strategy file), written before start.
    var files: [RuntimeFile] = []
    /// Forward this runtime serves.
    var forwardID: Int64
}

struct TunnelPanelSession: Codable, Equatable {
    /// PanelEndpoint storage key ("cloudflare" / "aliyun_cdn").
    var endpoint: String
    var clientID: Int64
    var agentToken: String
    var installID: String
    var deviceName: String
    var appVersion: String
}

/// Requests the app sends with NETunnelProviderSession.sendProviderMessage.
struct TunnelRequest: Codable {
    enum Command: String, Codable {
        case status
        case logs
        case reload
    }

    var command: Command
    var launch: TunnelLaunchOptions?
}

struct TunnelStatusSnapshot: Codable, Equatable {
    var uplink: Int64 = 0
    var downlink: Int64 = 0
    var uplinkTotal: Int64 = 0
    var downlinkTotal: Int64 = 0
    var connectionsIn: Int32 = 0
    var connectionsOut: Int32 = 0
    var memory: Int64 = 0
    var runtimes: [RuntimeStatus] = []
    /// Terminal panel/runtime problem the app should surface (kicked,
    /// device limit, runtime failed).
    var alert: String?
}

struct RuntimeStatus: Codable, Equatable {
    var name: String
    var kind: RuntimeLaunch.Kind
    var running: Bool
    var error: String?
}

struct TunnelLogsSnapshot: Codable, Equatable {
    var lines: [String]
}

struct RuntimeFile: Codable, Equatable {
    var path: String
    var contents: String
}
