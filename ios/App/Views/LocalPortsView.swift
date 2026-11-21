import LeikwanIOSCore
import SwiftUI

struct ConnectionModePicker: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var ports: LocalPortController
    var body: some View {
        Picker("Connection mode", selection: Binding(get: { model.localPortMode }, set: { enabled in
            Task { await model.setLocalPortMode(enabled) }
        })) {
            Text("VPN").tag(false)
            Text("Local Port · foreground only").tag(true)
        }
        .pickerStyle(.segmented)
        .disabled(ports.isCleaningUp || model.changingConnectionMode)
    }
}

struct LocalPortsView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var ports: LocalPortController
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Label("Local Port · foreground only", systemImage: "network")
                .font(.headline)
            Text("Fixed-target Ultra TCP (and TCP+UDP) ports on 127.0.0.1. UDP-only and Bond forwards are not supported in this version. No system VPN or SOCKS proxy is created. Keep this app in the foreground: switching apps pauses/stops the ports, leases and heartbeat. This is not a persistent background helper for another proxy app.")
                .font(.subheadline)
            Text("All Local Port runtime, panel HTTPS and DNS connections require the chosen physical interface. There is no VPN/default-route fallback. iOS includeAllNetworks, Always-On VPN, MDM or filtering policy may still block access; this mode cannot override system policy. Other app features (imports, tools, scripts) are not part of this mode.")
                .font(.caption)
                .foregroundStyle(.secondary)
            Picker("Physical network", selection: $ports.network) {
                ForEach(LocalPortController.PhysicalNetwork.allCases, id: \.self) { network in
                    Text(network.title).tag(network)
                }
            }
            .pickerStyle(.segmented)
            .disabled(ports.isRunning || ports.isCleaningUp)
            HStack {
                Text("Physical DNS (IPv4)")
                TextField("223.5.5.5", text: $ports.dnsServer)
                    .keyboardType(.decimalPad)
                    .textInputAutocapitalization(.never)
                    .multilineTextAlignment(.trailing)
            }
            .disabled(ports.isRunning || ports.isCleaningUp)
            Text(statusText).font(.subheadline.weight(.semibold))
            if let message = ports.message { Text(message).font(.caption).foregroundStyle(.orange) }
            ForEach(model.catalog?.supported ?? [], id: \.forwardId) { forward in
                VStack(alignment: .leading, spacing: 6) {
                    let reason = LocalPortSupport.unavailableReason(forward)
                    Toggle(isOn: Binding(get: { model.localPortForwardIDs.contains(forward.forwardId) }, set: { enabled in
                        if enabled { model.localPortForwardIDs.insert(forward.forwardId) }
                        else { model.localPortForwardIDs.remove(forward.forwardId) }
                    })) {
                        Text(forward.directionTitle).font(.subheadline.weight(.semibold))
                    }
                    .disabled(reason != nil || ports.isRunning || ports.isCleaningUp)
                    Text("127.0.0.1:\(forward.contract.listenPort) → \(forward.targetText)")
                        .font(.caption.monospaced()).textSelection(.enabled)
                    Text(reason ?? ports.portStates[forward.forwardId] ?? "Stopped · TCP/UDP fixed target")
                        .font(.caption).foregroundStyle(.secondary)
                    if let endpoint = ports.endpoints[forward.forwardId] {
                        Text("Relay: \(endpoint)").font(.caption2.monospaced())
                    }
                }
                .padding(12).card()
            }
            if let count = model.catalog?.hidden.count, count > 0 {
                Text("\(count) other forward(s) are unsupported on iOS. No automatic VPN fallback.")
                    .font(.caption).foregroundStyle(.secondary)
            }
        }
        .padding(16)
        .card()
    }

    private var statusText: String {
        if ports.isCleaningUp { return "Stopping local runtimes…" }
        let network = ports.interfaceName.map { " · \(ports.network.title) (\($0))" } ?? ""
        switch ports.state {
        case .stopped: return "Stopped" + network
        case .starting: return "Starting / waiting for authorization and listeners" + network
        case .listening: return "Listening · authorized" + network
        case .paused: return "Paused in background · start again in foreground"
        case .unavailable: return "Unavailable · no fallback"
        }
    }
}
