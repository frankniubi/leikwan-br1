import Foundation

/// The App Review demo account. Signing in with its Client ID and token starts a local demo:
/// no panel request is made, no Network Extension is started, and the two forwards below are
/// shown. The token is a fixed string that no server issues, so no real credential ships in the
/// app. The forwards use RFC 5737 documentation addresses and reach nothing.
public enum DemoAccount {
    public static let clientId: Int64 = 58
    public static let agentToken = "bridge-review-demo"

    /// True when the Client ID and token are the demo account's.
    public static func matches(clientId: Int64, token: String) -> Bool {
        return clientId == DemoAccount.clientId
            && token.trimmingCharacters(in: .whitespacesAndNewlines) == agentToken
    }

    /// Forwards in the shape `configs/pull` returns them, so the same filter builds the catalog.
    public static func rawForwards() -> [Any] {
        return [
            demoForward(id: 1001, name: "New York Office", target: "203.0.113.10:443", listenPort: 32001),
            demoForward(id: 1002, name: "Oregon Branch", target: "198.51.100.20:443", listenPort: 32002),
        ]
    }

    public static func catalog(selectedForwardId: Int64?) -> IOSForwardCatalog {
        return IOSForwardFilter.buildCatalog(rawForwards: rawForwards(), selectedForwardId: selectedForwardId)
    }

    private static func demoForward(id: Int64, name: String, target: String, listenPort: Int) -> [String: Any] {
        return [
            "forward_id": id,
            "service_name": name,
            "backend_mode": "ultra",
            "mode": "ultra",
            "transport": "tcp+udp",
            "listen_port": listenPort,
            "service_status": "active",
            "target": target,
            "forward_combination_direction": "Demo",
        ]
    }
}
