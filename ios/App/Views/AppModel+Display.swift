import LeikwanIOSCore
import SwiftUI

/// Presentation helpers derived from AppModel state (no stored state here).
extension AppModel {
    var routeTitle: String {
        if isNativeOutboundActive, let carrier = selectedNativeOutboundCarrier {
            return nativeOutboundTitle(for: carrier)
        }
        if let forward = selectedForward {
            return forward.serviceName.isEmpty ? "转发 #\(forward.forwardId)" : forward.serviceName
        }
        if let node = selectedNode {
            return node.displayName
        }
        return "未选择线路"
    }

    /// Entry "ip:port" for a forward card. Ultra entries come from the lease the
    /// runtime selected (last known value while disconnected).
    func endpointText(for forward: IOSSupportedForward) -> String {
        if forward.kind == .ultra, let endpoint = tunnel.ultraEndpoints[forward.forwardId] {
            return endpoint
        }
        return forward.endpointSummary
    }

    var routeDetail: String? {
        if isNativeOutboundActive, let selection = nativeOutboundSelection {
            return "原生出口 · " + selection.routeScope.displayName
        }
        let node = selectedNode?.displayName
        if let forward = selectedForward {
            return [forward.kindLabel, node].compactMap { $0 }.joined(separator: " · ")
        }
        return node == nil ? "在「线路」里选择转发和节点" : "直连节点"
    }
}

extension IOSSupportedForward {
    /// Forward direction from the panel (e.g. "SHA -> TYO"), else its name.
    var directionTitle: String {
        let direction = contract.direction.trimmingCharacters(in: .whitespacesAndNewlines)
        if !direction.isEmpty { return direction }
        return serviceName.isEmpty ? "转发 #\(forwardId)" : serviceName
    }

    /// Entry endpoint(s) as ip:port; Ultra entries come from leases at connect time.
    var endpointSummary: String {
        let endpoints = contract.endpoints
        guard let first = endpoints.first else {
            return "—"
        }
        let host = first.host.contains(":") ? "[\(first.host)]" : first.host
        let more = endpoints.count > 1 ? "  +\(endpoints.count - 1)" : ""
        return "\(host):\(first.port)\(more)"
    }

    var kindLabel: String {
        switch kind {
        case .ultra: return "Ultra"
        case .bondPlainTcpMux: return "Bond"
        }
    }
}

extension ProxyNode {
    var displayName: String {
        name.isEmpty ? "\(server):\(port)" : name
    }

    var typeLabel: String {
        type == .shadowsocks ? "Shadowsocks" : "SOCKS5"
    }
}
