import Foundation
@testable import LeikwanIOSCore

enum Fixtures {
    static let now = Date(timeIntervalSince1970: 1_800_000_000) // 2027-01-15T08:00:00Z
    /// A profile's server address and its backup (test-only names).
    static let panel = try! PanelEndpoint.parse("panel.example.com").get()
    static let backupPanel = try! PanelEndpoint.parse("https://backup.example.net/").get()
    /// Direct-route registry of a profile with both addresses.
    static let safePaths = SafePathRegistry.iosBaseline(panelHosts: [panel.host, backupPanel.host])
    static let generation = String(repeating: "a", count: 64)
    static let target = "1.2.3.4:443"
    /// SHA-256("1.2.3.4:443") computed with Python hashlib.
    static let targetDigest = "156f7e0f149ff30c2af45bad2949f3aba0efe61a95e765bcd02f3b76776563a4"

    static func iso(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter.string(from: date)
    }

    static func json(_ text: String) -> [String: Any] {
        guard let obj = JSONLenient.parseObject(text) else {
            fatalError("fixture JSON invalid: \(text)")
        }
        return obj
    }

    static func parseJSON(_ text: String) -> [String: Any] {
        return json(text)
    }

    /// Bond plain_tcp_mux forward used by the argv golden tests.
    static func bondForwardJSON(transport: String = "tcp", ingressMode: String = "plain_tcp_mux", quic: Bool = false) -> [String: Any] {
        return [
            "forward_id": 12,
            "service_name": "Tokyo Bond",
            "mode": "bond",
            "backend_mode": "current_mode",
            "client_mode": "bond",
            "transport": transport,
            "listen_port": 31000,
            "service_status": "active",
            "sni": "",
            "path_prefix": "/r",
            "target": "10.0.0.1:443",
            "endpoints": [
                [
                    "endpoint_id": 101, "host": "1.2.3.4", "port": 443, "node_id": 7,
                    "ingress_auth_token": "tok1", "supports_quic_leg": quic,
                ] as [String: Any],
                [
                    "endpoint_id": 102, "host": "edge.example.com", "port": 8443,
                    "ingress_auth_token": "tok2", "cdn_host": "cdn.example.com",
                ] as [String: Any],
            ],
            "bonding": [
                "ingress_mode": ingressMode,
                "route_key": "rk",
                "session_token": "st",
            ] as [String: Any],
        ]
    }

    static func ultraAccess(forwardId: Int64 = 12, expiresIn: TimeInterval = 600) -> [String: Any] {
        return [
            "protocol": "leikwan-ultra-policy-v2",
            "forward_id": forwardId,
            "session_ticket": "ticket-123",
            "policy_generation": generation,
            "issued_at": iso(now.addingTimeInterval(-10)),
            "renew_after": iso(now.addingTimeInterval(100)),
            "expires_at": iso(now.addingTimeInterval(expiresIn)),
            "service_state": "active",
            "transport": "TCP",
            "rate_limit_mbps": 200,
            "quota_remaining_bytes": "5000",
            "target_digest": targetDigest,
        ]
    }

    static func ultraForward(access: [String: Any]? = ultraAccess()) -> ForwardContract {
        var raw: [String: Any] = [
            "forward_id": 12,
            "service_name": "Ultra HK",
            "backend_mode": "ultra",
            "mode": "ultra",
            "transport": "tcp",
            "listen_port": 32000,
            "service_status": "active",
            "target": target,
        ]
        if let access = access { raw["ultra_access"] = access }
        return ForwardContractParser.parse(raw)!
    }

    static func leaseJSON() -> [String: Any] {
        return [
            "protocol": "leikwan-ultra-policy-v2",
            "forward_id": 12,
            "session_ticket": "ticket-123",
            "policy_generation": generation,
            "lease_id": "lease-0123456789abcdef",
            "expires_at": iso(now.addingTimeInterval(300)),
            "transport": "tcp",
            "rate_limit_mbps": 100,
            "quota_remaining_bytes": 1000,
            "selection_ref": "sel_0123456789abcdef",
            "selection_tier": "paid",
            "target_digest": targetDigest,
            "nonce_hash": String(repeating: "b", count: 64),
            "endpoint": ["ip": "203.0.113.5", "port": 9000] as [String: Any],
            "credential": ["user": "u", "password": "p"],
            "direct_ip_cidrs": ["198.51.100.0/24"],
        ]
    }
}
