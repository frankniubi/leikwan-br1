import Foundation

/// Per-forward Ultra usage delta carried on the heartbeat (port of `UltraUsageRow`).
public struct UltraUsageRow: Equatable {
    public var forwardId: Int64
    public var policyGeneration: String
    public var sequence: Int64
    public var bytesIn: Int64
    public var bytesOut: Int64

    public init(forwardId: Int64, policyGeneration: String, sequence: Int64, bytesIn: Int64, bytesOut: Int64) {
        self.forwardId = forwardId
        self.policyGeneration = policyGeneration
        self.sequence = sequence
        self.bytesIn = bytesIn
        self.bytesOut = bytesOut
    }

    public func jsonObject() -> [String: Any] {
        return [
            "forward_id": forwardId,
            "policy_generation": policyGeneration,
            "sequence": sequence,
            "bytes_in": bytesIn,
            "bytes_out": bytesOut,
        ]
    }
}

/// `POST api/client/heartbeat` body. iOS never advertises route-direction / exact-destination rules.
public struct HeartbeatRequest: Equatable {
    public static let clientPlatform = "ios"

    public var osRelease: String
    public var ultraUsage: [UltraUsageRow]?

    public init(osRelease: String, ultraUsage: [UltraUsageRow]? = nil) {
        self.osRelease = osRelease
        self.ultraUsage = ultraUsage
    }

    public func jsonObject() -> [String: Any] {
        var obj: [String: Any] = [
            "client_platform": HeartbeatRequest.clientPlatform,
            "os_release": osRelease,
            "supports_route_direction_rules": false,
            "supports_exact_destination_rules": false,
        ]
        if let usage = ultraUsage, !usage.isEmpty {
            obj["ultra_usage"] = usage.map { $0.jsonObject() }
        }
        return obj
    }
}

public struct HeartbeatResponse: Equatable {
    public let ok: Bool
    /// Absent on older panels: then `ok` acknowledges the usage batch.
    public let ultraUsageAcknowledged: Bool?

    public init(ok: Bool, ultraUsageAcknowledged: Bool?) {
        self.ok = ok
        self.ultraUsageAcknowledged = ultraUsageAcknowledged
    }

    public var usageAcknowledged: Bool { return ultraUsageAcknowledged ?? ok }

    public static func parse(_ data: Data) -> HeartbeatResponse? {
        guard let obj = JSONLenient.parseObject(data) else { return nil }
        return HeartbeatResponse(
            ok: JSONLenient.bool(obj["ok"]) ?? false,
            ultraUsageAcknowledged: JSONLenient.bool(obj["ultra_usage_acknowledged"])
        )
    }
}

/// `GET api/client/configs/pull` answer. Only the parts iOS uses are modelled; `raw` keeps the rest.
public struct PullConfigsResponse {
    public let ok: Bool
    public let client: Any?
    public let forwards: [Any]
    public let raw: [String: Any]

    public init(ok: Bool, client: Any?, forwards: [Any], raw: [String: Any]) {
        self.ok = ok
        self.client = client
        self.forwards = forwards
        self.raw = raw
    }

    public static func parse(_ data: Data) -> PullConfigsResponse? {
        guard let obj = JSONLenient.parseObject(data) else { return nil }
        return PullConfigsResponse(
            ok: JSONLenient.bool(obj["ok"]) ?? false,
            client: obj["client"],
            forwards: JSONLenient.array(obj["forwards"]),
            raw: obj
        )
    }

    public func catalog(selectedForwardId: Int64?) -> IOSForwardCatalog {
        var catalog = IOSForwardFilter.buildCatalog(rawForwards: forwards, selectedForwardId: selectedForwardId)
        catalog.nativeOutbound = nativeOutbound
        return catalog
    }
}
