import LeikwanIOSCore
import SwiftUI

struct ConnectionModePicker: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var ports: LocalPortController
    var body: some View {
        Picker(L("Connection mode"), selection: Binding(get: { model.localPortMode }, set: { enabled in
            Task { await model.setLocalPortMode(enabled) }
        })) {
            Text("VPN").tag(false)
            Text(L("Ports only")).tag(true)
        }
        .pickerStyle(.segmented)
        .disabled(model.changingConnectionMode)
    }
}

struct LocalPortsView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var ports: LocalPortController

    private var forwards: [IOSSupportedForward] {
        guard let catalog = model.catalog else { return [] }
        return LocalPortSupport.allSupported(in: catalog)
    }
    private var unsupported: [IOSSupportedForward] {
        (model.catalog?.supported ?? []).filter { LocalPortSupport.unavailableReason($0) != nil }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Label(L("All forwards"), systemImage: "network").font(.headline)
                Spacer()
                Text(statusText).font(.subheadline).foregroundStyle(.secondary)
            }
            Text(L("Foreground only; pauses in background"))
                .font(.caption).foregroundStyle(.secondary)
            if ports.state == .unavailable, let message = ports.message {
                Text(L(message)).font(.caption).foregroundStyle(.orange).lineLimit(2)
            }
            if forwards.isEmpty {
                Text(L("No supported forwards")).font(.subheadline).foregroundStyle(.secondary)
            }
            ForEach(forwards, id: \.forwardId) { forward in
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(forward.directionTitle).font(.subheadline.weight(.semibold))
                        // Strings avoid SwiftUI's localized integer grouping (32,001).
                        Text("127.0.0.1:" + String(forward.contract.listenPort))
                            .font(.caption.monospaced()).textSelection(.enabled)
                    }
                    Spacer()
                    Text(portStatus(forward.forwardId)).font(.caption).foregroundStyle(.secondary)
                }
                .padding(12).card()
            }
            DisclosureGroup(L("Advanced settings")) {
                VStack(alignment: .leading, spacing: 12) {
                    Picker(L("Physical network"), selection: $ports.network) {
                        ForEach(LocalPortController.PhysicalNetwork.allCases, id: \.self) { network in
                            Text(network.title).tag(network)
                        }
                    }
                    .pickerStyle(.segmented)
                    .disabled(ports.isRunning || ports.isCleaningUp)
                    HStack {
                        Text("DNS (IPv4)")
                        TextField("223.5.5.5", text: $ports.dnsServer)
                            .keyboardType(.decimalPad)
                            .textInputAutocapitalization(.never)
                            .multilineTextAlignment(.trailing)
                    }
                    .disabled(ports.isRunning || ports.isCleaningUp)
                    Text(L("Configured Ultra TCP/TCP+UDP ports only, on 127.0.0.1. No system VPN or SOCKS proxy. Physical network binding is required; no fallback. iOS VPN/MDM policy may block access. Other app tools and scripts are unavailable in this mode."))
                        .font(.caption).foregroundStyle(.secondary)
                    if let message = ports.message { Text(message).font(.caption).foregroundStyle(.orange) }
                    if let name = ports.interfaceName { Text(ports.network.title + " · " + name).font(.caption) }
                    let count = unsupported.count + (model.catalog?.hidden.count ?? 0)
                    if count > 0 {
                        Text(L("%@ unsupported forward(s)", String(count))).font(.caption.weight(.semibold))
                        ForEach(unsupported, id: \.forwardId) { forward in
                            Text(forward.directionTitle + ": " + L(LocalPortSupport.unavailableReason(forward) ?? ""))
                                .font(.caption).foregroundStyle(.secondary)
                        }
                        ForEach(Array((model.catalog?.hidden ?? []).enumerated()), id: \.offset) { _, forward in
                            Text(forward.serviceName + ": " + L(forward.reason.message))
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                .padding(.top, 8)
            }
            .font(.subheadline)
        }
        .padding(16)
        .card()
    }

    private func portStatus(_ id: Int64) -> String {
        guard let status = ports.portStates[id] else { return L("Not connected") }
        return status == "Listening · authorized" ? L("Listening") : L("Connecting")
    }

    private var statusText: String {
        if ports.isCleaningUp { return L("Stopping") }
        switch ports.state {
        case .stopped: return L("Not connected")
        case .starting: return L("Connecting")
        case .listening: return L("Listening")
        case .paused: return L("Paused")
        case .unavailable: return L("Unavailable")
        }
    }
}
