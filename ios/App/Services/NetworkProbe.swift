import Foundation
import LeikwanIOSCore

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
    case rules
    case tools

    var id: String { rawValue }

    var title: String {
        switch self {
        case .dashboard: return "Dashboard"
        case .proxies: return "Lines"
        case .rules: return "Rules"
        case .tools: return "Tools"
        }
    }

    var systemImage: String {
        switch self {
        case .dashboard: return "square.grid.2x2"
        case .proxies: return "point.3.connected.trianglepath.dotted"
        case .rules: return "list.bullet.rectangle.portrait"
        case .tools: return "wrench.and.screwdriver"
        }
    }
}

@MainActor
final class Navigator: ObservableObject {
    @Published var section: AppSection = .dashboard
}
