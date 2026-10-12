import Foundation

/// Constants shared with the Android/desktop Ultra hosts.
public enum UltraConstants {
    public static let policyProtocol = "leikwan-ultra-policy-v2"
    public static let maxSessionTicketLength = 4096
    /// Upper bound on how far in the future a policy (and any lease bound to it) may expire.
    public static let maxTTLSeconds: TimeInterval = 3600
    public static let maxQuotaBytes: Int64 = 107_374_182_400
    public static let maxRateMbps: Int64 = 100_000
    /// Offline validity this client asks for on configs/pull and ultra/lease.
    public static let offlineValiditySeconds = 1800
    public static let offlineValidityHeaderName = "X-Leikwan-Ultra-Offline-Validity"
    /// Sealed-lease device key header (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).
    public static let leaseKeyHeaderName = "X-Leikwan-Lease-Key"
    public static let maxLeaseCandidates = 4
    public static let allowedTransports: Set<String> = ["tcp", "udp", "tcp+udp"]
    public static let allowedSelectionTiers: Set<String> = ["paid", "mixed", "free", "unknown"]
}

/// Port of `UltraPolicy` (the bounded, forward-bound `ultra_access` grant).
public struct UltraPolicy: Equatable {
    public let forwardId: Int64
    public let sessionTicket: String
    public let policyGeneration: String
    public let issuedAt: Date
    public let renewAfter: Date
    public let expiresAt: Date
    public let transport: String
    public let rateLimitMbps: Int64
    public let quotaRemainingBytes: Int64
    public let targetDigest: String
    public let tcpFirstFlight: UltraTcpFirstFlight?

    public init(
        forwardId: Int64,
        sessionTicket: String,
        policyGeneration: String,
        issuedAt: Date,
        renewAfter: Date,
        expiresAt: Date,
        transport: String,
        rateLimitMbps: Int64,
        quotaRemainingBytes: Int64,
        targetDigest: String,
        tcpFirstFlight: UltraTcpFirstFlight? = nil
    ) {
        self.forwardId = forwardId
        self.sessionTicket = sessionTicket
        self.policyGeneration = policyGeneration
        self.issuedAt = issuedAt
        self.renewAfter = renewAfter
        self.expiresAt = expiresAt
        self.transport = transport
        self.rateLimitMbps = rateLimitMbps
        self.quotaRemainingBytes = quotaRemainingBytes
        self.targetDigest = targetDigest
        self.tcpFirstFlight = tcpFirstFlight
    }

    /// SHA-256 hex of the trimmed forward target.
    public static func targetDigest(_ target: String) -> String {
        return Hashing.sha256Hex(target.trimmingCharacters(in: .whitespacesAndNewlines))
    }

    /// Validates `forward.ultraAccess` exactly like Android; nil when anything is off.
    public static func from(_ forward: ForwardContract, now: Date = Date()) -> UltraPolicy? {
        if forward.backendMode.lowercased() != "ultra" || forward.serviceStatus.lowercased() != "active" {
            return nil
        }
        guard let access = forward.ultraAccess,
              let proto = JSONLenient.requiredString(access["protocol"]),
              let forwardId = JSONLenient.strictInt64(access["forward_id"]),
              let ticket = JSONLenient.requiredString(access["session_ticket"]),
              let generation = JSONLenient.requiredString(access["policy_generation"]),
              let issuedRaw = JSONLenient.requiredString(access["issued_at"]), let issuedAt = ISOInstant.parse(issuedRaw),
              let renewRaw = JSONLenient.requiredString(access["renew_after"]), let renewAfter = ISOInstant.parse(renewRaw),
              let expiresRaw = JSONLenient.requiredString(access["expires_at"]), let expiresAt = ISOInstant.parse(expiresRaw),
              let serviceState = JSONLenient.requiredString(access["service_state"]),
              let transportRaw = JSONLenient.requiredString(access["transport"]),
              let rateLimit = JSONLenient.strictInt64(access["rate_limit_mbps"]),
              let quota = JSONLenient.strictInt64(access["quota_remaining_bytes"]),
              let policyTargetDigest = JSONLenient.requiredString(access["target_digest"])
        else { return nil }
        let transport = transportRaw.lowercased()
        if proto != UltraConstants.policyProtocol { return nil }
        if forwardId != forward.forwardId { return nil }
        if serviceState.lowercased() != "active" { return nil }
        if ticket.isEmpty || ticket.count > UltraConstants.maxSessionTicketLength { return nil }
        if !Hashing.isLowerHex64(generation) { return nil }
        if !Hashing.isLowerHex64(policyTargetDigest) { return nil }
        if policyTargetDigest != targetDigest(forward.target) { return nil }
        if !(issuedAt < renewAfter) { return nil }
        if !(renewAfter < expiresAt) { return nil }
        if !(expiresAt > now) { return nil }
        if expiresAt > now.addingTimeInterval(UltraConstants.maxTTLSeconds) { return nil }
        if !UltraConstants.allowedTransports.contains(transport) { return nil }
        if rateLimit < 0 || rateLimit > UltraConstants.maxRateMbps { return nil }
        if quota < 1 || quota > UltraConstants.maxQuotaBytes { return nil }
        var firstFlight: UltraTcpFirstFlight?
        if let raw = access["tcp_first_flight"] as? [String: Any] {
            firstFlight = UltraTcpFirstFlight.from(raw, forwardTarget: forward.target, policyGeneration: generation, now: now)
        }
        return UltraPolicy(
            forwardId: forwardId,
            sessionTicket: ticket,
            policyGeneration: generation,
            issuedAt: issuedAt,
            renewAfter: renewAfter,
            expiresAt: expiresAt,
            transport: transport,
            rateLimitMbps: rateLimit,
            quotaRemainingBytes: quota,
            targetDigest: policyTargetDigest,
            tcpFirstFlight: firstFlight
        )
    }
}

/// Port of `UltraTcpFirstFlight`.
public struct UltraTcpFirstFlight: Equatable {
    public static let maxCandidateHashes = 16

    public let target: String
    public let candidateHashes: [String]
    public let prefixBytes: Int
    public let delayMillis: Int

    public init(target: String, candidateHashes: [String], prefixBytes: Int, delayMillis: Int) {
        self.target = target
        self.candidateHashes = candidateHashes
        self.prefixBytes = prefixBytes
        self.delayMillis = delayMillis
    }

    /// The `tcp_first_flight` object written into the runtime config.
    public func bootstrapJSON() -> [String: Any] {
        return [
            "target": target,
            "candidate_hashes": candidateHashes,
            "prefix_bytes": prefixBytes,
            "delay_ms": delayMillis,
        ]
    }

    public static func from(_ raw: [String: Any], forwardTarget: String, policyGeneration: String, now: Date) -> UltraTcpFirstFlight? {
        guard let target = JSONLenient.requiredString(raw["target"]),
              let generation = JSONLenient.requiredString(raw["policy_generation"]),
              let expiresRaw = JSONLenient.requiredString(raw["expires_at"]), let expiresAt = ISOInstant.parse(expiresRaw),
              let prefix = JSONLenient.strictInt64(raw["prefix_bytes"]),
              let delay = JSONLenient.strictInt64(raw["delay_ms"]),
              let rawHashes = raw["candidate_hashes"] as? [Any]
        else { return nil }
        var hashes: [String] = []
        for item in rawHashes {
            guard let value = JSONLenient.requiredString(item)?.lowercased(), Hashing.isLowerHex64(value) else { continue }
            if !hashes.contains(value) { hashes.append(value) }
            if hashes.count >= maxCandidateHashes { break }
        }
        if target != forwardTarget.trimmingCharacters(in: .whitespacesAndNewlines) { return nil }
        if generation != policyGeneration { return nil }
        if !(expiresAt > now) { return nil }
        if prefix < 1 || prefix > 16 { return nil }
        if delay < 1 || delay > 1000 { return nil }
        if hashes.isEmpty { return nil }
        return UltraTcpFirstFlight(target: target, candidateHashes: hashes, prefixBytes: Int(prefix), delayMillis: Int(delay))
    }
}

/// One-shot provider material (port of `UltraLease`). Never persist it; call `wipe()` after delivery.
public struct UltraLease: Equatable {
    public let leaseId: String
    public let forwardId: Int64
    public let sessionTicket: String
    public let policyGeneration: String
    public let expiresAt: Date
    public let transport: String
    public let rateLimitMbps: Int64
    public let quotaRemainingBytes: Int64
    public let selectionRef: String
    public let targetDigest: String
    public let nonceHash: String
    public let endpointIp: String
    public let endpointPort: Int
    public var user: [UInt8]
    public var password: [UInt8]
    public let directConnectivity: ProtectedConnectivitySet
    public let selectionTier: String
    public let candidateKeyHash: String
    public let leaseHash: String

    public mutating func wipe() {
        for index in user.indices { user[index] = 0 }
        for index in password.indices { password[index] = 0 }
    }

    public func isUsable(now: Date = Date()) -> Bool {
        if leaseId.trimmingCharacters(in: .whitespaces).isEmpty { return false }
        if forwardId <= 0 { return false }
        if !Hashing.isLowerHex64(policyGeneration) { return false }
        if !(expiresAt > now) { return false }
        if !UltraConstants.allowedTransports.contains(transport) { return false }
        if IPLiteral.normalize(endpointIp) == nil { return false }
        if endpointPort < 1 || endpointPort > 65535 { return false }
        if user.isEmpty || user.count > 255 { return false }
        if password.isEmpty || password.count > 255 { return false }
        if !UltraLease.isSelectionRef(selectionRef) { return false }
        if !UltraConstants.allowedSelectionTiers.contains(selectionTier) { return false }
        if !candidateKeyHash.isEmpty && !Hashing.isLowerHex64(candidateKeyHash) { return false }
        return true
    }

    /// Control-socket payload (same keys as Android `toControlPayload`). Contains secrets.
    public func controlPayload() -> [String: Any] {
        var payload: [String: Any] = [
            "protocol": UltraConstants.policyProtocol,
            "forward_id": forwardId,
            "session_ticket": sessionTicket,
            "policy_generation": policyGeneration,
            "lease_id": leaseId,
            "expires_at": ISOInstant.format(expiresAt),
            "transport": transport,
            "rate_limit_mbps": rateLimitMbps,
            "quota_remaining_bytes": quotaRemainingBytes,
            "target_digest": targetDigest,
            "selection_ref": selectionRef,
            "selection_tier": selectionTier,
            "nonce_hash": nonceHash,
            "endpoint": ["ip": endpointIp, "port": endpointPort] as [String: Any],
            "credential": [
                "user": String(decoding: user, as: UTF8.self),
                "password": String(decoding: password, as: UTF8.self),
            ],
        ]
        if !candidateKeyHash.trimmingCharacters(in: .whitespaces).isEmpty {
            payload["candidate_key_hash"] = candidateKeyHash
        }
        return payload
    }

    static func isSelectionRef(_ value: String) -> Bool {
        return value.range(of: "^[A-Za-z0-9_-]{16,128}$", options: .regularExpression) != nil
    }

    /// Validates an (already opened) lease object against its policy, like Android `UltraLease.from`.
    public static func from(_ raw: [String: Any], policy: UltraPolicy, now: Date = Date()) -> UltraLease? {
        guard let proto = JSONLenient.requiredString(raw["protocol"]),
              let forwardId = JSONLenient.strictInt64(raw["forward_id"]),
              let sessionTicket = JSONLenient.requiredString(raw["session_ticket"]),
              let policyGeneration = JSONLenient.requiredString(raw["policy_generation"]),
              let leaseId = JSONLenient.requiredString(raw["lease_id"]),
              let expiresRaw = JSONLenient.requiredString(raw["expires_at"]), let expiresAt = ISOInstant.parse(expiresRaw),
              let transportRaw = JSONLenient.requiredString(raw["transport"]),
              let rateLimit = JSONLenient.strictInt64(raw["rate_limit_mbps"]),
              let quota = JSONLenient.strictInt64(raw["quota_remaining_bytes"]),
              let selectionRef = JSONLenient.requiredString(raw["selection_ref"]),
              let targetDigest = JSONLenient.requiredString(raw["target_digest"]),
              let nonceHash = JSONLenient.requiredString(raw["nonce_hash"]),
              let endpoint = raw["endpoint"] as? [String: Any],
              let endpointIpRaw = JSONLenient.requiredString(endpoint["ip"]),
              let endpointIp = normalizedIpLiteral(endpointIpRaw),
              let endpointPort64 = JSONLenient.strictInt64(endpoint["port"]),
              let credential = raw["credential"] as? [String: Any],
              let userText = JSONLenient.requiredString(credential["user"]),
              let passwordText = JSONLenient.requiredString(credential["password"])
        else { return nil }
        let transport = transportRaw.lowercased()
        let selectionTier = JSONLenient.requiredString(raw["selection_tier"])?.lowercased() ?? "unknown"
        let candidateKeyHash = JSONLenient.requiredString(raw["candidate_key_hash"])?.lowercased() ?? ""
        let user = Array(userText.utf8)
        let password = Array(passwordText.utf8)
        let endpointPort = Int(clamping: endpointPort64)
        guard let direct = directConnectivity(raw["direct_ip_cidrs"], endpointIp: endpointIp) else { return nil }
        let leaseIdLength = leaseId.count
        if proto != UltraConstants.policyProtocol { return nil }
        if forwardId != policy.forwardId { return nil }
        if sessionTicket != policy.sessionTicket { return nil }
        if policyGeneration != policy.policyGeneration { return nil }
        if leaseIdLength < 16 || leaseIdLength > 128 { return nil }
        if !(expiresAt > now) { return nil }
        if expiresAt > policy.expiresAt.addingTimeInterval(1) { return nil }
        if transport != policy.transport { return nil }
        if rateLimit < 0 || rateLimit > policy.rateLimitMbps { return nil }
        if quota < 1 || quota > policy.quotaRemainingBytes { return nil }
        if !isSelectionRef(selectionRef) { return nil }
        if !UltraConstants.allowedSelectionTiers.contains(selectionTier) { return nil }
        if !candidateKeyHash.isEmpty && !Hashing.isLowerHex64(candidateKeyHash) { return nil }
        if targetDigest != policy.targetDigest { return nil }
        if !Hashing.isLowerHex64(nonceHash) { return nil }
        if endpointPort < 1 || endpointPort > 65535 { return nil }
        if user.isEmpty || user.count > 255 { return nil }
        if password.isEmpty || password.count > 255 { return nil }
        let leaseHash = JSONLenient.requiredString(raw["lease_hash"])?.lowercased() ?? Hashing.sha256Hex(leaseId)
        return UltraLease(
            leaseId: leaseId,
            forwardId: forwardId,
            sessionTicket: sessionTicket,
            policyGeneration: policyGeneration,
            expiresAt: expiresAt,
            transport: transport,
            rateLimitMbps: rateLimit,
            quotaRemainingBytes: quota,
            selectionRef: selectionRef,
            targetDigest: targetDigest,
            nonceHash: nonceHash,
            endpointIp: endpointIp,
            endpointPort: endpointPort,
            user: user,
            password: password,
            directConnectivity: direct,
            selectionTier: selectionTier,
            candidateKeyHash: candidateKeyHash,
            leaseHash: leaseHash
        )
    }

    static func directConnectivity(_ rawCidrs: Any?, endpointIp: String) -> ProtectedConnectivitySet? {
        var cidrs: [String] = []
        if let rawCidrs = rawCidrs, !(rawCidrs is NSNull) {
            guard let array = rawCidrs as? [Any] else {
                return ProtectedConnectivitySet.fromEndpoints([endpointIp])
            }
            for item in array {
                guard let cidr = JSONLenient.requiredString(item) else { return nil }
                if ProtectedConnectivitySet.of(cidrs: [cidr]).ipCidrs.isEmpty { return nil }
                cidrs.append(cidr)
            }
        }
        return ProtectedConnectivitySet.fromEndpoints([endpointIp]).merge(ProtectedConnectivitySet.of(cidrs: cidrs))
    }

    static func normalizedIpLiteral(_ value: String) -> String? {
        var text = value.trimmingCharacters(in: .whitespacesAndNewlines)
        if text.hasPrefix("[") && text.hasSuffix("]") && text.count >= 2 {
            text = String(text.dropFirst().dropLast())
        }
        if text.isEmpty || (!text.contains(".") && !text.contains(":")) { return nil }
        if text.range(of: "^[0-9a-fA-F:.]+$", options: .regularExpression) == nil { return nil }
        return IPLiteral.normalize(text)?.lowercased()
    }
}
