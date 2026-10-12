import LeikwanIOSCore
import SwiftUI
import UIKit

struct NodesView: View {
    @EnvironmentObject private var model: AppModel
    @State private var editing: ProxyNode?
    @State private var showingSubscriptions = false
    @State private var working = false

    var body: some View {
        NavigationStack {
            List {
                if model.nodes.isEmpty {
                    Section {
                        VStack(spacing: 10) {
                            Image(systemName: "tray")
                                .font(.largeTitle)
                                .foregroundStyle(.secondary)
                            Text("还没有节点")
                                .font(.headline)
                            Text("从剪贴板导入 ss:// 或 socks5:// 链接、订阅地址，或手动添加。")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                                .multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 24)
                    }
                }
                ForEach(groupedNodes, id: \.title) { group in
                    Section(group.title) {
                        ForEach(group.nodes) { node in
                            NodeRow(node: node, selected: node.id == model.selectedNodeID)
                                .contentShape(Rectangle())
                                .onTapGesture { model.selectedNodeID = node.id }
                                .swipeActions {
                                    Button(role: .destructive) {
                                        model.deleteNodes([node.id])
                                    } label: {
                                        Label("删除", systemImage: "trash")
                                    }
                                    Button {
                                        editing = node
                                    } label: {
                                        Label("编辑", systemImage: "pencil")
                                    }
                                    .tint(.blue)
                                }
                                .contextMenu {
                                    Button("编辑") { editing = node }
                                    Button("复制分享链接") { UIPasteboard.general.string = ProxyShareLink.make(node) }
                                    Button("删除", role: .destructive) { model.deleteNodes([node.id]) }
                                }
                        }
                    }
                }
            }
            .navigationTitle("节点")
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button {
                        showingSubscriptions = true
                    } label: {
                        Label("订阅", systemImage: "arrow.triangle.2.circlepath")
                    }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
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
                    } label: {
                        if working {
                            ProgressView()
                        } else {
                            Image(systemName: "plus.circle.fill")
                        }
                    }
                }
            }
            .sheet(item: $editing) { node in
                NodeEditor(node: node) { saved in
                    model.saveNode(saved)
                }
            }
            .sheet(isPresented: $showingSubscriptions) {
                SubscriptionsView()
            }
        }
    }

    private var groupedNodes: [(title: String, nodes: [ProxyNode])] {
        var groups: [(title: String, nodes: [ProxyNode])] = []
        let manual = model.nodes.filter { $0.subscriptionId == nil }
        if !manual.isEmpty {
            groups.append(("本地节点", manual))
        }
        for subscription in model.subscriptions {
            let items = model.nodes.filter { $0.subscriptionId == subscription.id }
            if !items.isEmpty {
                groups.append((subscription.name, items))
            }
        }
        return groups
    }

    private func importClipboard() async {
        guard let text = UIPasteboard.general.string, !text.isEmpty else {
            model.notice = "剪贴板是空的"
            return
        }
        working = true
        model.notice = await model.importText(text)
        working = false
    }
}

private struct NodeRow: View {
    let node: ProxyNode
    let selected: Bool

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: selected ? "checkmark.circle.fill" : "circle")
                .foregroundStyle(selected ? Color.accentColor : Color.secondary)
                .font(.title3)
            VStack(alignment: .leading, spacing: 3) {
                Text(node.name.isEmpty ? "\(node.server):\(node.port)" : node.name)
                    .font(.body)
                    .lineLimit(1)
                HStack(spacing: 6) {
                    Text(node.type == .shadowsocks ? "SS" : "SOCKS5")
                        .font(.caption2.weight(.semibold))
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(Color.accentColor.opacity(0.15), in: Capsule())
                    Text("\(node.server):\(node.port)")
                        .font(.caption.monospaced())
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer()
            LatencyBadge(host: node.server, port: node.port)
        }
        .padding(.vertical, 2)
    }
}

/// TCP connect latency to the node (measured from the app, outside the tunnel).
private struct LatencyBadge: View {
    let host: String
    let port: Int
    @State private var latency: Int?
    @State private var failed = false

    var body: some View {
        Group {
            if let latency {
                Text("\(latency) ms")
                    .foregroundStyle(latency < 150 ? .green : (latency < 400 ? .orange : .red))
            } else if failed {
                Text("超时").foregroundStyle(.red)
            } else {
                Text("—").foregroundStyle(.secondary)
            }
        }
        .font(.caption.monospacedDigit())
        .task(id: "\(host):\(port)") {
            if let value = await TCPPing.measure(host: host, port: port) {
                latency = value
            } else {
                failed = true
            }
        }
    }
}

struct NodeEditor: View {
    @Environment(\.dismiss) private var dismiss
    @State private var node: ProxyNode
    @State private var portText: String
    let onSave: (ProxyNode) -> Void

    private static let ssMethods = [
        "aes-128-gcm", "aes-256-gcm", "chacha20-ietf-poly1305", "xchacha20-ietf-poly1305",
        "2022-blake3-aes-128-gcm", "2022-blake3-aes-256-gcm", "2022-blake3-chacha20-poly1305", "none",
    ]

    init(node: ProxyNode, onSave: @escaping (ProxyNode) -> Void) {
        _node = State(initialValue: node)
        _portText = State(initialValue: node.port > 0 ? String(node.port) : "")
        self.onSave = onSave
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("基本") {
                    TextField("名称（可选）", text: $node.name)
                    TextField("服务器地址", text: $node.server)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    TextField("端口", text: $portText)
                        .keyboardType(.numberPad)
                }
                switch node.settings {
                case let .shadowsocks(settings):
                    Section("Shadowsocks") {
                        Picker("加密方式", selection: ssBinding(settings, \.method)) {
                            ForEach(Self.ssMethods, id: \.self) { Text($0).tag($0) }
                        }
                        SecureField("密码", text: ssBinding(settings, \.password))
                        TextField("插件（obfs-local / v2ray-plugin，可选）", text: ssOptionalBinding(settings, \.plugin))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        TextField("插件参数（可选）", text: ssOptionalBinding(settings, \.pluginOpts))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                    }
                case let .socks(settings):
                    Section("SOCKS5") {
                        TextField("用户名（可选）", text: socksBinding(settings, \.username))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        SecureField("密码（可选）", text: socksBinding(settings, \.password))
                    }
                }
            }
            .navigationTitle(node.id == 0 ? "添加节点" : "编辑节点")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("保存") {
                        var saved = node
                        saved.port = Int(portText) ?? 0
                        saved.server = saved.server.trimmingCharacters(in: .whitespaces)
                        onSave(saved)
                        dismiss()
                    }
                    .disabled(!isValid)
                }
            }
        }
    }

    private var isValid: Bool {
        guard let port = Int(portText), (1 ... 65535).contains(port), !node.server.trimmingCharacters(in: .whitespaces).isEmpty else {
            return false
        }
        if case let .shadowsocks(settings) = node.settings {
            return settings.method == "none" || !settings.password.isEmpty
        }
        return true
    }

    private func ssBinding(_ settings: ShadowsocksSettings, _ keyPath: WritableKeyPath<ShadowsocksSettings, String>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value
                node.settings = .shadowsocks(updated)
            }
        )
    }

    private func ssOptionalBinding(_ settings: ShadowsocksSettings, _ keyPath: WritableKeyPath<ShadowsocksSettings, String?>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] ?? "" },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value.isEmpty ? nil : value
                node.settings = .shadowsocks(updated)
            }
        )
    }

    private func socksBinding(_ settings: SocksSettings, _ keyPath: WritableKeyPath<SocksSettings, String?>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] ?? "" },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value.isEmpty ? nil : value
                node.settings = .socks(updated)
            }
        )
    }
}

private struct SubscriptionsView: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var newURL = ""
    @State private var busyID: Int64?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack {
                        TextField("订阅地址 https://…", text: $newURL)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .keyboardType(.URL)
                        Button("添加") {
                            let url = newURL.trimmingCharacters(in: .whitespacesAndNewlines)
                            newURL = ""
                            Task { await run { _ = try await model.addSubscription(url: url) } }
                        }
                        .disabled(!(newURL.hasPrefix("http://") || newURL.hasPrefix("https://")))
                    }
                }
                Section("已添加") {
                    ForEach(model.subscriptions) { subscription in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(subscription.name)
                                Text(subscription.lastUpdated.map { "更新于 \($0.formatted(date: .abbreviated, time: .shortened))" } ?? "尚未更新")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            if busyID == subscription.id {
                                ProgressView()
                            } else {
                                Button {
                                    Task {
                                        busyID = subscription.id
                                        await run { _ = try await model.refreshSubscription(subscription) }
                                        busyID = nil
                                    }
                                } label: {
                                    Image(systemName: "arrow.clockwise")
                                }
                                .buttonStyle(.borderless)
                            }
                        }
                        .swipeActions {
                            Button(role: .destructive) {
                                model.deleteSubscription(subscription)
                            } label: {
                                Label("删除", systemImage: "trash")
                            }
                        }
                    }
                }
            }
            .navigationTitle("订阅")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("完成") { dismiss() }
                }
            }
        }
    }

    private func run(_ action: () async throws -> Void) async {
        do {
            try await action()
        } catch {
            model.notice = AppModel.describe(error)
        }
    }
}
