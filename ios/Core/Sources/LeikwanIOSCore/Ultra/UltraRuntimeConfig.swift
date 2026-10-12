import Foundation

/// Caller-supplied file locations for one Ultra runtime instance.
/// Note: a Unix socket path is limited to 104 bytes on Darwin; keep `controlSocketPath` short.
public struct UltraRuntimePaths: Equatable {
    public var configPath: String
    public var controlSocketPath: String
    public var statusPath: String
    public var locationCachePath: String
    public var locationLockPath: String

    public init(configPath: String, controlSocketPath: String, statusPath: String, locationCachePath: String, locationLockPath: String) {
        self.configPath = configPath
        self.controlSocketPath = controlSocketPath
        self.statusPath = statusPath
        self.locationCachePath = locationCachePath
        self.locationLockPath = locationLockPath
    }

    /// Android naming: `<dir>/forward-<id>-<generation[0..12]>{.json,.control.sock,.status.json,.location.json,.location.lock}`.
    public static func make(directory: String, forwardId: Int64, policyGeneration: String) -> UltraRuntimePaths {
        let base = directory.hasSuffix("/") ? String(directory.dropLast()) : directory
        let prefix = base + "/forward-\(forwardId)-\(String(policyGeneration.prefix(12)))"
        return UltraRuntimePaths(
            configPath: prefix + ".json",
            controlSocketPath: prefix + ".control.sock",
            statusPath: prefix + ".status.json",
            locationCachePath: prefix + ".location.json",
            locationLockPath: prefix + ".location.lock"
        )
    }

    /// Every app-private file the runtime may create (delete them when the forward stops).
    public var allPaths: [String] {
        return [configPath, controlSocketPath, statusPath, locationCachePath, locationLockPath]
    }
}

public struct UltraRuntimePlan {
    public let forwardId: Int64
    public let localListenPort: Int
    public let paths: UltraRuntimePaths
    /// Static runtime config (no provider credentials). Deterministic JSON.
    public let configJSON: String
    public let config: [String: Any]
    /// `["--config", <configPath>]`, the argv Android passes to ultra-client (minus the binary).
    public let invocation: RuntimeInvocation
}

/// Port of `UltraForwardRunner.buildPlan` (non-native path only; native SOCKS5 is not supported on iOS).
public enum UltraRuntimeConfigBuilder {
    public static func buildPlan(
        forward: ForwardContract,
        policy: UltraPolicy,
        paths: UltraRuntimePaths,
        autoSelect: Bool = false,
        listenPortOverride: Int? = nil
    ) throws -> UltraRuntimePlan {
        if forward.forwardId != policy.forwardId {
            throw UltraLeaseError("ultra_policy_forward_mismatch")
        }
        let listenPort = listenPortOverride ?? forward.listenPort
        if listenPort < 1 || listenPort > 65535 {
            throw UltraLeaseError("ultra_listen_port_invalid")
        }
        var config: [String: Any] = [
            "forward_id": forward.forwardId,
            "listen_host": "127.0.0.1",
            "listen_port": listenPort,
            "target": forward.target.trimmingCharacters(in: .whitespacesAndNewlines),
            "control_socket": paths.controlSocketPath,
            "policy_generation": policy.policyGeneration,
            "target_digest": policy.targetDigest,
            "transport": policy.transport,
            "max_tcp_connections": 512,
            "udp_queue_packets": 1024,
            "connect_timeout_seconds": 5,
            "location_cache_path": paths.locationCachePath,
            "location_lock_path": paths.locationLockPath,
            "status_path": paths.statusPath,
            "probe_timeout_ms": 800,
        ]
        if let firstFlight = policy.tcpFirstFlight {
            config["tcp_first_flight"] = firstFlight.bootstrapJSON()
        }
        if autoSelect {
            config["probe_target"] = "1.1.1.1:53"
            config["auto_select"] = true
            config["probe_duration_seconds"] = 30
            config["probe_samples"] = 30
            config["probe_interval_ms"] = 1000
            config["probe_workers"] = 4
        } else {
            config["auto_select"] = false
            config["probe_workers"] = 4
        }
        let json = try CanonicalJSON.string(config)
        return UltraRuntimePlan(
            forwardId: forward.forwardId,
            localListenPort: listenPort,
            paths: paths,
            configJSON: json,
            config: config,
            invocation: RuntimeInvocation(role: .ultra, arguments: ["--config", paths.configPath])
        )
    }

    /// Length-prefixed (4-byte big-endian) control frame for one lease or a candidate batch,
    /// matching Android `UltraLeaseClient.sendPayload`. Returns nil when the payload is out of bounds.
    public static func controlFrame(leases: [UltraLease]) throws -> Data? {
        if leases.isEmpty || leases.count > UltraConstants.maxLeaseCandidates { return nil }
        let body: Data
        if leases.count == 1 {
            body = try CanonicalJSON.data(leases[0].controlPayload())
        } else {
            body = try CanonicalJSON.data(["leases": leases.map { $0.controlPayload() }])
        }
        if body.isEmpty || body.count > 64 * 1024 { return nil }
        var frame = Data()
        let length = UInt32(body.count)
        frame.append(UInt8((length >> 24) & 0xff))
        frame.append(UInt8((length >> 16) & 0xff))
        frame.append(UInt8((length >> 8) & 0xff))
        frame.append(UInt8(length & 0xff))
        frame.append(body)
        return frame
    }

    /// Android `replyAccepted`: `ok == true`, matching `forward_id` and `policy_generation`.
    public static func controlReplyAccepted(_ reply: Data, forwardId: Int64, policyGeneration: String) -> Bool {
        guard let obj = JSONLenient.parseObject(reply) else { return false }
        return JSONLenient.isBoolean(obj["ok"]) && (obj["ok"] as? Bool) == true
            && JSONLenient.int64(obj["forward_id"]) == forwardId
            && JSONLenient.string(obj["policy_generation"]) == policyGeneration
    }
}
