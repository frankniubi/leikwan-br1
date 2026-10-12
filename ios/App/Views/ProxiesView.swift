import LeikwanIOSCore
import SwiftUI

/// Forwards and nodes as selectable card grids (FlClash proxies page).
struct ProxiesView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var latency: LatencyStore
    @EnvironmentObject private var navigator: Navigator

    private let columns = [GridItem(.adaptive(minimum: 160), spacing: Metrics.gutter)]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Metrics.gutter) {
                    if model.isNativeOutboundAvailable {
                        NativeOutboundSection()
                    }
                    SectionTitle("转发", trailing: hiddenBadge)
                    LazyVGrid(columns: columns, spacing: Metrics.gutter) {
                        ForwardCard(
                            title: "不使用转发",
                            subtitle: "直接连接节点",
                            badge: "直连",
                            selected: model.selectedForwardID == nil
                        ) {
                            select(forward: nil)
                        }
                        ForEach(model.catalog?.supported ?? [], id: \.forwardId) { forward in
                            ForwardCard(
                                title: forward.serviceName.isEmpty ? "转发 #\(forward.forwardId)" : forward.serviceName,
                                subtitle: "#\(forward.forwardId)",
                                badge: forward.kindLabel,
                                selected: model.selectedForwardID == forward.forwardId
                            ) {
                                select(forward: forward.forwardId)
                            }
                        }
                    }

                    SectionTitle("节点", trailing: AnyView(
                        Button {
                            Task { await latency.testAll(model.nodes) }
                        } label: {
                            Label("测速", systemImage: "bolt.fill")
                                .font(.subheadline.weight(.semibold))
                        }
                        .disabled(model.nodes.isEmpty || !latency.testing.isEmpty)
                    ))
                    if model.nodes.isEmpty {
                        EmptyNodesCard {
                            navigator.section = .profiles
                        }
                    } else {
                        LazyVGrid(columns: columns, spacing: Metrics.gutter) {
                            ForEach(model.nodes) { node in
                                NodeCard(node: node, selected: model.selectedNodeID == node.id) {
                                    select(node: node.id)
                                }
                            }
                        }
                    }
                }
                .padding(Metrics.pagePadding)
                .padding(.bottom, 88)
            }
            .refreshable { await model.refreshCatalog() }
            .pageBackground()
            .navigationTitle("线路")
            .overlay(alignment: .bottomTrailing) {
                StartButton()
                    .padding(Metrics.pagePadding + 4)
            }
        }
    }

    private var hiddenBadge: AnyView? {
        guard let hidden = model.catalog?.unsupportedHiddenCount, hidden > 0 else { return nil }
        return AnyView(Pill(text: "已隐藏 \(hidden) 条不支持的转发"))
    }

    private func select(forward id: Int64?) {
        guard model.selectedForwardID != id else { return }
        model.selectedForwardID = id
        Task { await model.applyToRunningTunnel() }
    }

    private func select(node id: Int64) {
        guard model.selectedNodeID != id else { return }
        model.selectedNodeID = id
        Task { await model.applyToRunningTunnel() }
    }
}

private struct ForwardCard: View {
    let title: String
    let subtitle: String
    let badge: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Pill(
                        text: badge,
                        fill: selected ? Palette.primary : Palette.secondaryContainer,
                        foreground: selected ? Palette.onPrimary : Palette.onSecondaryContainer
                    )
                    Spacer()
                    if selected {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(Palette.primary)
                    }
                }
                Text(title)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(selected ? Palette.onPrimaryContainer : Palette.onSurface)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                Text(subtitle)
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 96, alignment: .topLeading)
            .card(selected: selected)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.15), value: selected)
    }
}

private struct NodeCard: View {
    @EnvironmentObject private var latency: LatencyStore
    let node: ProxyNode
    let selected: Bool
    let action: () -> Void

    var body: some View {
        let result = latency.result(for: node)
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                Text(node.displayName)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(selected ? Palette.onPrimaryContainer : Palette.onSurface)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
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
            .card(selected: selected)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.15), value: selected)
    }
}

private struct EmptyNodesCard: View {
    let action: () -> Void

    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: "tray")
                .font(.largeTitle)
                .foregroundStyle(Palette.onSurfaceVariant)
            Text("还没有节点")
                .font(.headline)
            Text("在「订阅」里导入 ss:// 或 socks5:// 链接、订阅地址，或手动添加。")
                .font(.footnote)
                .foregroundStyle(Palette.onSurfaceVariant)
                .multilineTextAlignment(.center)
            Button("去添加", action: action)
                .buttonStyle(.borderedProminent)
        }
        .frame(maxWidth: .infinity)
        .padding(24)
        .card()
    }
}
