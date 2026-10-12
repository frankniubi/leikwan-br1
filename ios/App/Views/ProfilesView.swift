import LeikwanIOSCore
import SwiftUI
import UIKit

/// Subscriptions and local nodes as profile cards (FlClash profiles page).
struct ProfilesView: View {
    @EnvironmentObject private var model: AppModel
    @State private var editing: ProxyNode?
    @State private var browsing: NodeGroup?
    @State private var addingSubscription = false
    @State private var importing = false
    @State private var refreshingID: Int64?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Metrics.gutter) {
                    ProfileCard(
                        title: "本地节点",
                        subtitle: "手动添加和剪贴板导入",
                        icon: "iphone",
                        count: localNodes.count,
                        updated: nil,
                        refreshing: false,
                        onOpen: { browsing = .local },
                        onRefresh: nil,
                        onDelete: nil
                    )
                    ForEach(model.subscriptions) { subscription in
                        ProfileCard(
                            title: subscription.name,
                            subtitle: URL(string: subscription.url)?.host ?? subscription.url,
                            icon: "link",
                            count: model.nodes.filter { $0.subscriptionId == subscription.id }.count,
                            updated: subscription.lastUpdated,
                            refreshing: refreshingID == subscription.id,
                            onOpen: { browsing = .subscription(subscription.id) },
                            onRefresh: { Task { await refresh(subscription) } },
                            onDelete: { model.deleteSubscription(subscription) }
                        )
                    }
                }
                .padding(Metrics.pagePadding)
                .padding(.bottom, 88)
            }
            .pageBackground()
            .navigationTitle("订阅")
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Menu {
                        Button {
                            Task { await importClipboard() }
                        } label: {
                            Label("从剪贴板导入", systemImage: "doc.on.clipboard")
                        }
                        Button {
                            addingSubscription = true
                        } label: {
                            Label("添加订阅", systemImage: "link.badge.plus")
                        }
                        Divider()
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
                    } label: {
                        if importing {
                            ProgressView()
                        } else {
                            Image(systemName: "plus")
                                .font(.headline)
                        }
                    }
                }
            }
            .overlay(alignment: .bottomTrailing) {
                StartButton()
                    .padding(Metrics.pagePadding + 4)
            }
            .sheet(item: $editing) { node in
                NodeEditor(node: node) { saved in
                    model.saveNode(saved)
                }
            }
            .sheet(item: $browsing) { group in
                NodeListSheet(group: group) { node in
                    browsing = nil
                    editing = node
                }
            }
            .sheet(isPresented: $addingSubscription) {
                AddSubscriptionSheet()
                    .presentationDetents([.height(260)])
            }
        }
    }

    private var localNodes: [ProxyNode] {
        model.nodes.filter { $0.subscriptionId == nil }
    }

    private func refresh(_ subscription: Subscription) async {
        refreshingID = subscription.id
        defer { refreshingID = nil }
        do {
            let count = try await model.refreshSubscription(subscription)
            model.notice = "\(subscription.name)：已更新 \(count) 个节点"
        } catch {
            model.notice = AppModel.describe(error)
        }
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

enum NodeGroup: Identifiable, Hashable {
    case local
    case subscription(Int64)

    var id: String {
        switch self {
        case .local: return "local"
        case let .subscription(id): return "sub-\(id)"
        }
    }
}

private struct ProfileCard: View {
    let title: String
    let subtitle: String
    let icon: String
    let count: Int
    let updated: Date?
    let refreshing: Bool
    let onOpen: () -> Void
    let onRefresh: (() -> Void)?
    let onDelete: (() -> Void)?

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: icon)
                .font(.title3.weight(.semibold))
                .foregroundStyle(Palette.onPrimaryContainer)
                .frame(width: 44, height: 44)
                .background(Palette.primaryContainer, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 4) {
                Text(title)
                    .font(.headline)
                    .foregroundStyle(Palette.onSurface)
                    .lineLimit(1)
                Text(subtitle)
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(1)
                HStack(spacing: 6) {
                    Pill(text: "\(count) 个节点")
                    if let updated {
                        Text(updated.formatted(.relative(presentation: .named)))
                            .font(.caption2)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                }
            }
            Spacer(minLength: 4)
            if refreshing {
                ProgressView()
            } else if onRefresh != nil || onDelete != nil {
                Menu {
                    if let onRefresh {
                        Button(action: onRefresh) {
                            Label("更新", systemImage: "arrow.clockwise")
                        }
                    }
                    if let onDelete {
                        Button(role: .destructive, action: onDelete) {
                            Label("删除订阅", systemImage: "trash")
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis")
                        .font(.headline)
                        .foregroundStyle(Palette.onSurfaceVariant)
                        .frame(width: 36, height: 36)
                }
            } else {
                Image(systemName: "chevron.right")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
        }
        .padding(14)
        .card()
        .contentShape(Rectangle())
        // A child Menu keeps its own taps; the rest of the card opens the list.
        .onTapGesture(perform: onOpen)
    }
}

/// Nodes of one profile, with edit / share / delete.
private struct NodeListSheet: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var latency: LatencyStore
    @Environment(\.dismiss) private var dismiss
    let group: NodeGroup
    let onEdit: (ProxyNode) -> Void

    var body: some View {
        NavigationStack {
            List {
                ForEach(nodes) { node in
                    HStack(spacing: 12) {
                        Image(systemName: node.id == model.selectedNodeID ? "checkmark.circle.fill" : "circle")
                            .foregroundStyle(node.id == model.selectedNodeID ? Palette.primary : Palette.onSurfaceVariant)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(node.displayName)
                                .lineLimit(1)
                            Text("\(node.typeLabel) · \(node.server):\(node.port)")
                                .font(.caption.monospaced())
                                .foregroundStyle(Palette.onSurfaceVariant)
                                .lineLimit(1)
                        }
                        Spacer()
                        let result = latency.result(for: node)
                        Text(LatencyStyle.text(result?.milliseconds, failed: result?.failed ?? false))
                            .font(.caption.monospacedDigit())
                            .foregroundStyle(LatencyStyle.color(result?.milliseconds))
                    }
                    .contentShape(Rectangle())
                    .onTapGesture { model.selectedNodeID = node.id }
                    .swipeActions {
                        Button(role: .destructive) {
                            model.deleteNodes([node.id])
                        } label: {
                            Label("删除", systemImage: "trash")
                        }
                        if group == .local {
                            Button {
                                onEdit(node)
                            } label: {
                                Label("编辑", systemImage: "pencil")
                            }
                            .tint(Palette.primary)
                        }
                    }
                    .contextMenu {
                        if group == .local {
                            Button("编辑") { onEdit(node) }
                        }
                        Button("复制分享链接") { UIPasteboard.general.string = ProxyShareLink.make(node) }
                        Button("删除", role: .destructive) { model.deleteNodes([node.id]) }
                    }
                    .listRowBackground(Palette.surfaceContainer)
                }
            }
            .scrollContentBackground(.hidden)
            .pageBackground()
            .overlay {
                if nodes.isEmpty {
                    Text("没有节点")
                        .foregroundStyle(Palette.onSurfaceVariant)
                }
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button {
                        Task { await latency.testAll(nodes) }
                    } label: {
                        Label("测速", systemImage: "bolt.fill")
                    }
                    .disabled(nodes.isEmpty)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("完成") { dismiss() }
                }
            }
        }
    }

    private var nodes: [ProxyNode] {
        switch group {
        case .local: return model.nodes.filter { $0.subscriptionId == nil }
        case let .subscription(id): return model.nodes.filter { $0.subscriptionId == id }
        }
    }

    private var title: String {
        switch group {
        case .local: return "本地节点"
        case let .subscription(id): return model.subscriptions.first { $0.id == id }?.name ?? "订阅"
        }
    }
}

private struct AddSubscriptionSheet: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var url = ""
    @State private var working = false

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 14) {
                Text("订阅地址")
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurfaceVariant)
                TextField("https://…", text: $url)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .keyboardType(.URL)
                    .padding(12)
                    .card(fill: Palette.surfaceContainerHigh)
                Text("支持 Base64 订阅和逐行 ss:// / socks5:// 链接。")
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
                Spacer()
            }
            .padding(Metrics.pagePadding)
            .pageBackground()
            .navigationTitle("添加订阅")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    if working {
                        ProgressView()
                    } else {
                        Button("添加") { Task { await add() } }
                            .disabled(!isValid)
                    }
                }
            }
        }
    }

    private var isValid: Bool {
        let value = url.trimmingCharacters(in: .whitespacesAndNewlines)
        return value.hasPrefix("https://") || value.hasPrefix("http://")
    }

    private func add() async {
        working = true
        defer { working = false }
        do {
            let count = try await model.addSubscription(url: url.trimmingCharacters(in: .whitespacesAndNewlines))
            model.notice = "已导入 \(count) 个节点"
            dismiss()
        } catch {
            model.notice = AppModel.describe(error)
        }
    }
}
