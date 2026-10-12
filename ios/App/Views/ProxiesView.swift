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

                    SectionTitle("绑定", trailing: hiddenBadge)
                    Text("每个转发绑定一个节点，所有启用的绑定同时运行；在「规则」里可以指定某个 App 走哪个绑定，其余走默认绑定。")
                        .font(.caption)
                        .foregroundStyle(Palette.onSurfaceVariant)
                    LazyVGrid(columns: bindingColumns, spacing: Metrics.gutter) {
                        ForEach(model.bindingRows) { row in
                            BindingCard(row: row)
                        }
                    }

                    SectionTitle("节点", trailing: AnyView(nodeActions))
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
            .navigationTitle("线路")
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

    private var hiddenBadge: AnyView? {
        guard let hidden = model.catalog?.unsupportedHiddenCount, hidden > 0 else { return nil }
        return AnyView(Pill(text: "已隐藏 \(hidden) 条不支持的转发"))
    }

    private var nodeActions: some View {
        HStack(spacing: 14) {
            Button {
                Task { await latency.testAll(model.nodes) }
            } label: {
                Image(systemName: "bolt.fill")
            }
            .disabled(model.nodes.isEmpty || !latency.testing.isEmpty)
            .accessibilityLabel("测速")

            Menu {
                Button {
                    Task { await importClipboard() }
                } label: {
                    Label("从剪贴板导入", systemImage: "doc.on.clipboard")
                }
                Button {
                    editing = ProxyNode(name: "", server: "", port: 8388, settings: .shadowsocks(ShadowsocksSettings(method: "aes-128-gcm", password: "")))
                } label: {
                    Label("添加 Shadowsocks", systemImage: "plus")
                }
                Button {
                    editing = ProxyNode(name: "", server: "", port: 1080, settings: .socks(SocksSettings()))
                } label: {
                    Label("添加 SOCKS5", systemImage: "plus")
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
                        Label("本机节点全部上传到云端", systemImage: "icloud.and.arrow.up")
                    }
                }
            } label: {
                if importing {
                    ProgressView()
                } else {
                    Image(systemName: "plus.circle.fill")
                }
            }
            .accessibilityLabel("添加节点")
        }
        .font(.headline)
    }

    private func importClipboard() async {
        guard let text = UIPasteboard.general.string, !text.isEmpty else {
            model.notice = "剪贴板是空的"
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

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                if let forward = row.forward {
                    Pill(
                        text: "#\(forward.forwardId) · \(forward.kindLabel)",
                        fill: row.isEnabled ? Palette.primary : Palette.secondaryContainer,
                        foreground: row.isEnabled ? Palette.onPrimary : Palette.onSecondaryContainer
                    )
                } else {
                    Pill(text: "直连")
                }
                if row.isDefault {
                    Pill(text: "默认", fill: Palette.successContainer, foreground: Palette.onSuccessContainer)
                }
                Spacer()
                Toggle("启用", isOn: enabledBinding)
                    .labelsHidden()
                    .disabled(row.node == nil && row.forward?.kind != .ultra)
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
                if let forward = row.forward {
                    Menu {
                        Button("不绑定节点") {
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
                if !row.isDefault && row.isEnabled {
                    Button("设为默认") {
                        model.setDefaultBinding(row.id)
                    }
                    .font(.caption.weight(.semibold))
                }
            }

            if let reason = row.skipReason {
                Label(reason, systemImage: "exclamationmark.triangle.fill")
                    .font(.caption)
                    .foregroundStyle(Palette.onWarningContainer)
                    .padding(8)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Palette.warningContainer, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
            }
        }
        .padding(14)
        .card(selected: row.isDefault && row.isEnabled)
        .opacity(row.isEnabled ? 1 : 0.75)
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
                Text("\(unsupported.name)（iOS 暂不支持 \(unsupported.nodeProtocol)）")
                    .font(.subheadline)
                    .foregroundStyle(Palette.bad)
                    .lineLimit(1)
            } else {
                Text(row.forward?.kind == .ultra ? "未绑定节点（直通转发）" : "选择节点")
                    .font(.subheadline)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            if row.forward != nil {
                Image(systemName: "chevron.up.chevron.down")
                    .font(.caption2)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
        }
    }

    private var enabledBinding: Binding<Bool> {
        Binding(
            get: { row.isEnabled },
            set: { model.setBindingEnabled(row.id, enabled: $0) }
        )
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
                .accessibilityLabel("从云端刷新")
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
        if model.cloudStatus.isSyncing { return "同步中…" }
        if model.cloudStatus.lastError != nil { return "云端同步失败" }
        return "云端节点 \(model.cloudNodes.count) 个 · 本机 \(model.localOnlyNodes.count) 个"
    }

    private var detail: String {
        if let error = model.cloudStatus.lastError { return error }
        if let date = model.cloudLastSyncedAt {
            return "上次同步 " + date.formatted(date: .omitted, time: .shortened) + " · 节点和绑定与其他设备同步"
        }
        return model.isCloudAvailable ? "节点和绑定与其他设备同步" : "登录后自动同步"
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
                    .accessibilityLabel(node.isHosted ? "云端节点" : "本机节点")
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
                Label("编辑", systemImage: "pencil")
            }
            if !node.isHosted && model.canUploadToCloud(node) {
                Button {
                    Task { await model.uploadNodeToCloud(node.id) }
                } label: {
                    Label("上传到云端", systemImage: "icloud.and.arrow.up")
                }
            }
            Button {
                model.addDirectBinding(nodeID: node.id)
            } label: {
                Label("直连此节点（加入绑定）", systemImage: "arrow.right")
            }
            Button {
                UIPasteboard.general.string = ProxyShareLink.format(node) ?? ""
            } label: {
                Label("复制分享链接", systemImage: "link")
            }
            Divider()
            Button(role: .destructive) {
                model.deleteNodes([node.id])
            } label: {
                Label(node.isHosted ? "从云端删除" : "删除", systemImage: "trash")
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
            Text("\(dto.nodeProtocol.uppercased()) · iOS 暂不支持")
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
            Text("还没有节点")
                .font(.headline)
            Text("点右上角 + 从剪贴板导入 ss:// 或 socks5:// 链接，或手动添加。其他设备上传到云端的节点会自动出现在这里。")
                .font(.footnote)
                .foregroundStyle(Palette.onSurfaceVariant)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(24)
        .card()
    }
}
