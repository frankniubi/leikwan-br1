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

    /// The target the panel configured for this forward (destination ip:port).
    var targetText: String {
        let target = contract.target.trimmingCharacters(in: .whitespacesAndNewlines)
        return target.isEmpty ? "—" : target
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
