import LeikwanIOSCore
import SwiftUI
import UIKit

/// 线路: forward ↔ node bindings (all enabled bindings run together), and the
/// cloud-synced node library (FlClash proxies page look).
struct ProxiesView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var latency: LatencyStore
    @State private var editing: ProxyNode?
    @State private var importing = false

    private let columns = [GridItem(.adaptive(minimum: 160), spacing: Metrics.gutter)]
    private let bindingColumns = [GridItem(.adaptive(minimum: 300), spacing: Metrics.gutter)]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Metrics.gutter) {
                    if model.isNativeOutboundAvailable {
                        NativeOutboundSection()
                    }

                    SectionTitle("Bindings", trailing: bindingsBadge)
                    if model.isNativeOutboundActive {
                        NativeActiveBanner()
                    } else {
                        Text("Each forward is bound to one node, and every enabled binding runs at the same time. Under Rules you can send an app through a specific binding; everything else uses the default binding.")
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                    LazyVGrid(columns: bindingColumns, spacing: Metrics.gutter) {
                        ForEach(model.bindingRows) { row in
                            BindingCard(row: row, suspended: model.isNativeOutboundActive)
                        }
                    }

                    SectionTitle("Nodes", trailing: AnyView(nodeActions))
                    CloudStatusBar()
                    if model.nodes.isEmpty && model.unsupportedCloudNodes.isEmpty {
                        EmptyNodesCard()
                    } else {
                        LazyVGrid(columns: columns, spacing: Metrics.gutter) {
                            ForEach(model.nodes) { node in
                                NodeCard(node: node) { editing = node }
                            }
                            ForEach(model.unsupportedCloudNodes, id: \.id) { dto in
                                UnsupportedNodeCard(dto: dto)
                            }
                        }
                    }
                }
                .padding(Metrics.pagePadding)
                .padding(.bottom, 88)
            }
            .refreshable {
                await model.refreshCatalog()
                await model.refreshCloudNodes()
            }
            .pageBackground()
            .navigationTitle("Lines")
            .overlay(alignment: .bottomTrailing) {
                StartButton()
                    .padding(Metrics.pagePadding + 4)
            }
            .sheet(item: $editing) { node in
                NodeEditor(node: node) { saved in
                    model.saveNode(saved)
                }
            }
        }
    }

    private var bindingsBadge: AnyView? {
        if model.isNativeOutboundActive {
            return AnyView(Pill(text: "Paused", fill: Palette.warningContainer, foreground: Palette.onWarningContainer))
        }
        guard let hidden = model.catalog?.unsupportedHiddenCount, hidden > 0 else { return nil }
        return AnyView(Pill(text: "\(hidden) unsupported forward(s) hidden"))
    }

    private var nodeActions: some View {
        HStack(spacing: 14) {
            Button {
                Task { await latency.testAll(model.nodes) }
            } label: {
                Image(systemName: "bolt.fill")
            }
            .disabled(model.nodes.isEmpty || !latency.testing.isEmpty)
            .accessibilityLabel("Test latency")

            Menu {
                Button {
                    Task { await importClipboard() }
                } label: {
                    Label("Import from clipboard", systemImage: "doc.on.clipboard")
                }
                Button {
                    editing = ProxyNode(name: "", server: "", port: 8388, settings: .shadowsocks(ShadowsocksSettings(method: "aes-128-gcm", password: "")))
                } label: {
                    Label("Add Shadowsocks", systemImage: "plus")
                }
                Button {
                    editing = ProxyNode(name: "", server: "", port: 1080, settings: .socks(SocksSettings()))
                } label: {
                    Label("Add SOCKS5", systemImage: "plus")
                }
                if model.isCloudAvailable && !model.localOnlyNodes.isEmpty {
                    Divider()
                    Button {
                        Task {
                            if let result = await model.uploadAllLocalNodesToCloud() {
                                model.notice = result.summary
                            }
                        }
                    } label: {
                        Label("Upload all device nodes to the cloud", systemImage: "icloud.and.arrow.up")
                    }
                }
            } label: {
                if importing {
                    ProgressView()
                } else {
                    Image(systemName: "plus.circle.fill")
                }
            }
            .accessibilityLabel("Add node")
        }
        .font(.headline)
    }

    private func importClipboard() async {
        guard let text = UIPasteboard.general.string, !text.isEmpty else {
            model.notice = "The clipboard is empty"
            return
        }
        importing = true
        model.notice = await model.importText(text)
        importing = false
    }
}

// MARK: Bindings

private struct BindingCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController
    @EnvironmentObject private var latency: LatencyStore
    let row: BindingRow
    /// Native outbound is on: Connect runs no binding, so the card shows off and is read-only.
    var suspended = false

    /// This binding runs on Connect.
    private var isRunning: Bool { row.isEnabled && !suspended }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                if let forward = row.forward {
                    Pill(
                        text: "#\(forward.forwardId) · \(forward.kindLabel)",
                        fill: isRunning ? Palette.primary : Palette.secondaryContainer,
                        foreground: isRunning ? Palette.onPrimary : Palette.onSecondaryContainer
                    )
                } else {
                    Pill(text: "Direct")
                }
                if row.isDefault && !suspended {
                    Pill(text: "Default", fill: Palette.successContainer, foreground: Palette.onSuccessContainer)
                }
                Spacer()
                Toggle("Enabled", isOn: enabledBinding)
                    .labelsHidden()
                    .disabled(suspended || (row.node == nil && row.forward?.kind != .ultra))
            }

            if let forward = row.forward {
                VStack(alignment: .leading, spacing: 2) {
                    Text(forward.directionTitle)
                        .font(.headline)
                        .foregroundStyle(Palette.onSurface)
                        .lineLimit(1)
                    Text(model.endpointText(for: forward))
                        .font(.caption.monospaced())
                        .foregroundStyle(Palette.onSurfaceVariant)
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)
                        .textSelection(.enabled)
                }
            }

            HStack(spacing: 8) {
                Image(systemName: "arrow.turn.down.right")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(Palette.onSurfaceVariant)
                if let forward = row.forward, !suspended {
                    Menu {
                        Button("No node") {
                            Task { await model.setNode(nil, forForward: forward.forwardId) }
                        }
                        Divider()
                        ForEach(model.nodes) { node in
                            Button {
                                Task { await model.setNode(node.id, forForward: forward.forwardId) }
                            } label: {
                                if node.id == row.node?.id {
                                    Label(node.displayName, systemImage: "checkmark")
                                } else {
                                    Text(node.displayName)
                                }
                            }
                        }
                    } label: {
                        nodeLabel
                    }
                } else {
                    nodeLabel
                }
                Spacer(minLength: 4)
                if let node = row.node {
                    let result = latency.result(for: node)
                    Text(LatencyStyle.text(result?.milliseconds, failed: result?.failed ?? false))
                        .font(.caption.monospacedDigit().weight(.semibold))
                        .foregroundStyle(LatencyStyle.color(result?.milliseconds))
                }
            }
            .padding(10)
            .background(Palette.surfaceContainerHigh, in: RoundedRectangle(cornerRadius: 10, style: .continuous))

            HStack(spacing: 8) {
                Text(row.nodeSourceLabel)
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
                Spacer()
                if !row.isDefault && isRunning {
                    Button("Make default") {
                        model.setDefaultBinding(row.id)
                    }
                    .font(.caption.weight(.semibold))
                }
            }

            if let reason = row.skipReason, !suspended {
                Label(reason, systemImage: "exclamationmark.triangle.fill")
                    .font(.caption)
                    .foregroundStyle(Palette.onWarningContainer)
                    .padding(8)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Palette.warningContainer, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
            }
        }
        .padding(14)
        .card(selected: row.isDefault && isRunning)
        .opacity(suspended ? 0.5 : (row.isEnabled ? 1 : 0.75))
        .task(id: row.node?.id) {
            if let node = row.node, latency.result(for: node) == nil {
                await latency.test(node)
            }
        }
    }

    private var nodeLabel: some View {
        HStack(spacing: 4) {
            if let node = row.node {
                Text(node.displayName)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurface)
                    .lineLimit(1)
            } else if let unsupported = row.unsupportedCloudNode {
                Text("\(unsupported.name) (iOS does not support \(unsupported.nodeProtocol) yet)")
                    .font(.subheadline)
                    .foregroundStyle(Palette.bad)
                    .lineLimit(1)
            } else {
                Text(row.forward?.kind == .ultra ? "No node (forward only)" : "Choose node")
                    .font(.subheadline)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            if row.forward != nil && !suspended {
                Image(systemName: "chevron.up.chevron.down")
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
        }
    }

    private var enabledBinding: Binding<Bool> {
        Binding(
            get: { isRunning },
            set: { model.setBindingEnabled(row.id, enabled: $0) }
        )
    }
}

/// Over the bindings while native outbound is on: says which exit is in use and switches back.
private struct NativeActiveBanner: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Label {
                Text("Native outbound \u{201C}\(nativeTitle)\u{201D} is in use, so the bindings below do not run (their settings are kept).")
            } icon: {
                Image(systemName: "pause.circle.fill")
            }
            .font(.subheadline)
            .foregroundStyle(Palette.onWarningContainer)
            Button {
                model.setNativeOutboundEnabled(false)
            } label: {
                Text(tunnel.isConnected || tunnel.isBusy ? "Use bindings instead (disconnects first)" : "Use bindings instead")
                    .font(.subheadline.weight(.semibold))
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.warningContainer, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private var nativeTitle: String {
        guard let carrier = model.selectedNativeOutboundCarrier else { return "Native outbound" }
        let direction = model.nativeOutboundForward(for: carrier)?.directionTitle ?? model.nativeOutboundTitle(for: carrier)
        return "#\(carrier.forwardId) \(direction)"
    }
}

// MARK: Nodes

private struct CloudStatusBar: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: icon)
                .foregroundStyle(iconColor)
            VStack(alignment: .leading, spacing: 1) {
                Text(title)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurface)
                Text(detail)
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(2)
            }
            Spacer()
            if model.cloudStatus.isSyncing {
                ProgressView()
            } else if model.isCloudAvailable {
                Button {
                    Task { await model.refreshCloudNodes() }
                } label: {
                    Image(systemName: "arrow.clockwise")
                }
                .accessibilityLabel("Refresh from cloud")
            }
        }
        .padding(12)
        .card(fill: model.cloudStatus.lastError == nil ? Palette.surfaceContainer : Palette.errorContainer)
    }

    private var icon: String {
        if model.cloudStatus.isSyncing { return "icloud" }
        if model.cloudStatus.lastError != nil { return "exclamationmark.icloud" }
        return model.isCloudAvailable ? "checkmark.icloud" : "icloud.slash"
    }

    private var iconColor: Color {
        model.cloudStatus.lastError == nil ? Palette.primary : Palette.onErrorContainer
    }

    private var title: String {
        if model.cloudStatus.isSyncing { return "Syncing…" }
        if model.cloudStatus.lastError != nil { return "Cloud sync failed" }
        return "\(model.cloudNodes.count) cloud · \(model.localOnlyNodes.count) on this device"
    }

    private var detail: String {
        if let error = model.cloudStatus.lastError { return error }
        if let date = model.cloudLastSyncedAt {
            return "Last synced " + date.formatted(date: .omitted, time: .shortened) + " · nodes and bindings sync with your other devices"
        }
        return model.isCloudAvailable ? "Nodes and bindings sync with your other devices" : "Syncs after you sign in"
    }
}

private struct NodeCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var latency: LatencyStore
    let node: ProxyNode
    let onEdit: () -> Void

    var body: some View {
        let result = latency.result(for: node)
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                Text(node.displayName)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurface)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                Spacer(minLength: 4)
                Image(systemName: node.isHosted ? "icloud.fill" : "iphone")
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .accessibilityLabel(node.isHosted ? "Cloud node" : "Device node")
            }
            Spacer(minLength: 0)
            HStack {
                Text(node.typeLabel)
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(1)
                Spacer(minLength: 4)
                if latency.testing.contains(node.id) {
                    ProgressView()
                        .controlSize(.mini)
                } else {
                    Button {
                        Task { await latency.test(node) }
                    } label: {
                        Text(LatencyStyle.text(result?.milliseconds, failed: result?.failed ?? false))
                            .font(.caption.monospacedDigit().weight(.semibold))
                            .foregroundStyle(LatencyStyle.color(result?.milliseconds))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 84, alignment: .topLeading)
        .card()
        .contentShape(Rectangle())
        .contextMenu {
            Button {
                onEdit()
            } label: {
                Label("Edit", systemImage: "pencil")
            }
            if !node.isHosted && model.canUploadToCloud(node) {
                Button {
                    Task { await model.uploadNodeToCloud(node.id) }
                } label: {
                    Label("Upload to cloud", systemImage: "icloud.and.arrow.up")
                }
            }
            Button {
                model.addDirectBinding(nodeID: node.id)
            } label: {
                Label("Use this node directly (add binding)", systemImage: "arrow.right")
            }
            Button {
                UIPasteboard.general.string = ProxyShareLink.format(node) ?? ""
            } label: {
                Label("Copy share link", systemImage: "link")
            }
            Divider()
            Button(role: .destructive) {
                model.deleteNodes([node.id])
            } label: {
                Label(node.isHosted ? "Delete from cloud" : "Delete", systemImage: "trash")
            }
        }
        .onTapGesture(perform: onEdit)
    }
}

private struct UnsupportedNodeCard: View {
    let dto: HostedNodeDTO

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                Text(dto.name.isEmpty ? "\(dto.server):\(dto.port)" : dto.name)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(2)
                Spacer(minLength: 4)
                Image(systemName: "icloud.fill")
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            Spacer(minLength: 0)
            Text("\(dto.nodeProtocol.uppercased()) · not supported on iOS yet")
                .font(.caption2)
                .foregroundStyle(Palette.onSurfaceVariant)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 84, alignment: .topLeading)
        .card(fill: Palette.surfaceContainerLow)
        .opacity(0.7)
    }
}

private struct EmptyNodesCard: View {
    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "tray")
                .font(.largeTitle)
                .foregroundStyle(Palette.onSurfaceVariant)
            Text("No nodes yet")
                .font(.headline)
            Text("Tap + to import an ss:// or socks5:// link from the clipboard, or add one by hand. Nodes uploaded to the cloud from your other devices show up here.")
                .font(.footnote)
                .foregroundStyle(Palette.onSurfaceVariant)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(24)
        .card()
    }
}
