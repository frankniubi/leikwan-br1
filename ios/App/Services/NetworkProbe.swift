import Foundation
import LeikwanIOSCore

/// Public exit IP and country, as seen through the tunnel when it is up.
struct ExitIPInfo: Equatable {
    let ip: String
    let countryCode: String?

    var flag: String {
        guard let code = countryCode?.uppercased(), code.count == 2 else { return "🌐" }
        var scalars = String.UnicodeScalarView()
        for scalar in code.unicodeScalars {
            guard let flagScalar = UnicodeScalar(127_397 + scalar.value) else { return "🌐" }
            scalars.append(flagScalar)
        }
        return String(scalars)
    }
}

@MainActor
final class NetworkProbe: ObservableObject {
    @Published private(set) var exitIP: ExitIPInfo?
    @Published private(set) var detecting = false
    @Published private(set) var failed = false

    func detect() async {
        detecting = true
        failed = false
        defer { detecting = false }
        var info = await Self.cloudflareTrace()
        if info == nil {
            info = await Self.ipSB()
        }
        if let info {
            exitIP = info
        } else {
            exitIP = nil
            failed = true
        }
    }

    private static func session() -> URLSession {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 6
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        return URLSession(configuration: configuration)
    }

    /// `ip=` and `loc=` lines of Cloudflare's trace endpoint.
    private static func cloudflareTrace() async -> ExitIPInfo? {
        guard let url = URL(string: "https://www.cloudflare.com/cdn-cgi/trace"),
              let (data, _) = try? await session().data(from: url)
        else {
            return nil
        }
        var fields: [String: String] = [:]
        for line in String(decoding: data, as: UTF8.self).split(separator: "\n") {
            let parts = line.split(separator: "=", maxSplits: 1)
            if parts.count == 2 {
                fields[String(parts[0])] = String(parts[1])
            }
        }
        guard let ip = fields["ip"], !ip.isEmpty else { return nil }
        return ExitIPInfo(ip: ip, countryCode: fields["loc"])
    }

    private static func ipSB() async -> ExitIPInfo? {
        guard let url = URL(string: "https://api.ip.sb/geoip"),
              let (data, _) = try? await session().data(from: url),
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let ip = object["ip"] as? String
        else {
            return nil
        }
        return ExitIPInfo(ip: ip, countryCode: object["country_code"] as? String)
    }
}

/// TCP connect latency per node, shared by every card that shows it.
@MainActor
final class LatencyStore: ObservableObject {
    struct Result: Equatable {
        var milliseconds: Int?
        var failed: Bool
    }

    @Published private(set) var results: [Int64: Result] = [:]
    @Published private(set) var testing: Set<Int64> = []

    func result(for node: ProxyNode) -> Result? {
        results[node.id]
    }

    func test(_ node: ProxyNode) async {
        guard !testing.contains(node.id) else { return }
        testing.insert(node.id)
        let value = await TCPPing.measure(host: node.server, port: node.port)
        results[node.id] = Result(milliseconds: value, failed: value == nil)
        testing.remove(node.id)
    }

    func testAll(_ nodes: [ProxyNode]) async {
        await withTaskGroup(of: Void.self) { group in
            for node in nodes {
                group.addTask { await self.test(node) }
            }
        }
    }
}

/// Which top-level section is showing (lets dashboard cards jump to a tab).
enum AppSection: String, CaseIterable, Identifiable {
    case dashboard
    case proxies
    case profiles
    case tools

    var id: String { rawValue }

    var title: String {
        switch self {
        case .dashboard: return "仪表盘"
        case .proxies: return "线路"
        case .profiles: return "订阅"
        case .tools: return "工具"
        }
    }

    var systemImage: String {
        switch self {
        case .dashboard: return "square.grid.2x2"
        case .proxies: return "point.3.connected.trianglepath.dotted"
        case .profiles: return "tray.full"
        case .tools: return "wrench.and.screwdriver"
        }
    }
}

@MainActor
final class Navigator: ObservableObject {
    @Published var section: AppSection = .dashboard
}
