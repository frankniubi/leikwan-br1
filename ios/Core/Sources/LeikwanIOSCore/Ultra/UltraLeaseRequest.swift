import Foundation

/// One forward entry of the `POST api/client/ultra/lease` body (port of `UltraLeaseForwardRequest`).
/// Optional fields are omitted from JSON when nil (Gson default).
public struct UltraLeaseForwardRequest: Equatable {
    public var forwardId: Int64
    public var sessionTicket: String
    public var policyGeneration: String
    public var nonce: String
    public var targetDigest: String
    public var transport: String
    public var purpose: String?
    public var routeScope: String?
    public var routePolicy: String?
    public var selectionMode: String?
    public var maxCandidates: Int?

    public init(
        forwardId: Int64,
        sessionTicket: String,
        policyGeneration: String,
        nonce: String,
        targetDigest: String,
        transport: String,
        purpose: String? = nil,
        routeScope: String? = nil,
        routePolicy: String? = nil,
        selectionMode: String? = nil,
        maxCandidates: Int? = nil
    ) {
        self.forwardId = forwardId
        self.sessionTicket = sessionTicket
        self.policyGeneration = policyGeneration
        self.nonce = nonce
        self.targetDigest = targetDigest
        self.transport = transport
        self.purpose = purpose
        self.routeScope = routeScope
        self.routePolicy = routePolicy
        self.selectionMode = selectionMode
        self.maxCandidates = maxCandidates
    }

    public func jsonObject() -> [String: Any] {
        var obj: [String: Any] = [
            "forward_id": forwardId,
            "session_ticket": sessionTicket,
            "policy_generation": policyGeneration,
            "nonce": nonce,
            "target_digest": targetDigest,
            "transport": transport,
        ]
        if let purpose = purpose { obj["purpose"] = purpose }
        if let routeScope = routeScope { obj["route_scope"] = routeScope }
        if let routePolicy = routePolicy { obj["route_policy"] = routePolicy }
        if let selectionMode = selectionMode { obj["selection_mode"] = selectionMode }
        if let maxCandidates = maxCandidates { obj["max_candidates"] = maxCandidates }
        return obj
    }
}

public struct UltraLeaseRequest: Equatable {
    public var deviceId: String
    public var forwards: [UltraLeaseForwardRequest]

    public init(deviceId: String, forwards: [UltraLeaseForwardRequest]) {
        self.deviceId = deviceId
        self.forwards = forwards
    }

    /// Ordinary Ultra lease request exactly as Android `resolveUltraLeases` builds it.
    public static func ordinary(
        policy: UltraPolicy,
        deviceId: String,
        maxCandidates: Int = UltraConstants.maxLeaseCandidates,
        nonceHex: String = UltraLeaseRequest.randomNonceHex()
    ) throws -> UltraLeaseRequest {
        if maxCandidates < 1 || maxCandidates > UltraConstants.maxLeaseCandidates {
            throw UltraLeaseError("ultra_candidate_limit_invalid")
        }
        return UltraLeaseRequest(deviceId: deviceId, forwards: [
            UltraLeaseForwardRequest(
                forwardId: policy.forwardId,
                sessionTicket: policy.sessionTicket,
                policyGeneration: policy.policyGeneration,
                nonce: nonceHex,
                targetDigest: policy.targetDigest,
                transport: policy.transport,
                selectionMode: maxCandidates > 1 ? "auto" : "single",
                maxCandidates: maxCandidates
            ),
        ])
    }

    /// 16 random bytes as lowercase hex.
    public static func randomNonceHex() -> String {
        var generator = SystemRandomNumberGenerator()
        var text = ""
        for _ in 0..<16 {
            let byte = UInt8.random(in: 0...255, using: &generator)
            text += String(format: "%02x", byte)
        }
        return text
    }

    public func jsonObject() -> [String: Any] {
        return [
            "device_id": deviceId,
            "forwards": forwards.map { $0.jsonObject() },
        ]
    }

    public func jsonData() throws -> Data {
        return try CanonicalJSON.data(jsonObject())
    }
}

public struct UltraLeaseError: Error, Equatable, CustomStringConvertible {
    public let message: String
    public init(_ message: String) { self.message = message }
    public var description: String { return message }
}

/// Parsed `ultra/lease` response (port of `UltraLeaseResponse`).
public struct UltraLeaseResponse {
    public let ok: Bool
    public let error: String?
    public let leases: [[String: Any]]

    public init(ok: Bool, error: String?, leases: [[String: Any]]) {
        self.ok = ok
        self.error = error
        self.leases = leases
    }

    public static func parse(_ data: Data) -> UltraLeaseResponse? {
        guard let obj = JSONLenient.parseObject(data) else { return nil }
        let leases = JSONLenient.array(obj["leases"]).compactMap { $0 as? [String: Any] }
        return UltraLeaseResponse(
            ok: JSONLenient.bool(obj["ok"]) ?? false,
            error: JSONLenient.string(obj["error"]),
            leases: leases
        )
    }

    /// Validates and opens every lease like Android `resolveUltraLeases` (all-or-nothing).
    public func validatedLeases(
        policy: UltraPolicy,
        maxCandidates: Int,
        sealer: UltraLeaseSealer,
        now: Date = Date()
    ) throws -> [UltraLease] {
        if !ok {
            throw UltraLeaseError(error ?? "ultra_lease_failed")
        }
        if leases.isEmpty || leases.count > maxCandidates {
            throw UltraLeaseError("ultra_lease_invalid")
        }
        var result: [UltraLease] = []
        do {
            for raw in leases {
                let opened = try sealer.openLease(raw)
                guard let lease = UltraLease.from(opened, policy: policy, now: now) else {
                    throw UltraLeaseError("ultra_lease_invalid")
                }
                result.append(lease)
            }
        } catch {
            for index in result.indices { result[index].wipe() }
            throw error
        }
        return result
    }
}

/// Seam for the sealed-lease scheme (ECDH-P256 + HKDF-SHA256 + A256GCM on Android).
/// Not implemented yet on iOS; `PlaintextLeaseOpener` refuses sealed leases.
public protocol UltraLeaseSealer {
    /// Value for the `X-Leikwan-Lease-Key` header, or nil to omit it (panel then sends plaintext leases).
    func leaseKeyHeaderValue() -> String?
    /// Returns the lease unchanged when it has no `sealed` envelope, otherwise the opened fields.
    /// Must throw `UltraLeaseError("lease_unseal_failed")` on any failure.
    func openLease(_ lease: [String: Any]) throws -> [String: Any]
}

public struct PlaintextLeaseOpener: UltraLeaseSealer {
    public init() {}

    public func leaseKeyHeaderValue() -> String? {
        return nil
    }

    public func openLease(_ lease: [String: Any]) throws -> [String: Any] {
        if lease["sealed"] != nil {
            throw UltraLeaseError("lease_unseal_failed")
        }
        return lease
    }
}
