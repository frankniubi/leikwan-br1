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
