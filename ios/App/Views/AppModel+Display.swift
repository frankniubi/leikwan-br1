import LeikwanIOSCore
import SwiftUI

/// Presentation helpers derived from AppModel state (no stored state here).
extension AppModel {
    var routeTitle: String {
        if isNativeOutboundActive, let carrier = selectedNativeOutboundCarrier {
            return nativeOutboundTitle(for: carrier)
        }
        if let row = defaultBindingRow {
            return row.title
        }
        return L("No line selected")
    }

    /// The binding Connect routes untargeted traffic through (nil while native outbound is on).
    var defaultBindingRow: BindingRow? {
        guard !isNativeOutboundActive, let id = resolvedBindings.defaultBinding?.id else { return nil }
        return bindingRows.first { $0.id == id }
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
            return L("Native outbound · %@ · bindings paused", selection.routeScope.displayName)
        }
        guard let row = defaultBindingRow else { return L("Choose a forward and node under Lines") }
        let count = resolvedBindings.active.count
        let kind = row.forward?.kindLabel ?? L("Direct node")
        return count > 1 ? L("Binding exit · %@ · default of %@ bindings", kind, String(count)) : L("Binding exit · %@", kind)
    }
}

extension IOSSupportedForward {
    /// Forward direction from the panel (e.g. "SHA -> TYO"), else its name.
    var directionTitle: String {
        let direction = contract.direction.trimmingCharacters(in: .whitespacesAndNewlines)
        if !direction.isEmpty { return direction }
        return serviceName.isEmpty ? L("Forward #%@", String(describing: forwardId)) : serviceName
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
