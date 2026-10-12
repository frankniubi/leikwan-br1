import LeikwanIOSCore
import SwiftUI
import UIKit

struct SettingsView: View {
    @EnvironmentObject private var model: AppModel
    @State private var confirmingSignOut = false

    var body: some View {
        NavigationStack {
            Form {
                Section("账号") {
                    LabeledContent("客户端 ID", value: model.login.map { String($0.clientID) } ?? "—")
                    LabeledContent("面板线路", value: endpointName)
                    LabeledContent("设备 ID") {
                        Text(deviceID)
                            .font(.caption.monospaced())
                            .textSelection(.enabled)
                    }
                    Button("刷新转发列表") {
                        Task { await model.refreshCatalog() }
                    }
                }

                if let catalog = model.catalog, !catalog.hidden.isEmpty {
                    Section {
                        ForEach(Array(catalog.hidden.enumerated()), id: \.offset) { _, hidden in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(hidden.serviceName.isEmpty ? "转发 #\(hidden.forwardId.map(String.init) ?? "?")" : hidden.serviceName)
                                Text(hidden.reason.message)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    } header: {
                        Text("iOS 暂不支持的转发")
                    } footer: {
                        Text("iOS 支持 Ultra 转发，以及 plain_tcp_mux 入口的 Bond 转发。")
                    }
                }

                Section("网络") {
                    Toggle("IPv6", isOn: $model.routing.enableIPv6)
                    LabeledContent("远程 DNS") {
                        TextField("1.1.1.1", text: $model.routing.dnsServer)
                            .multilineTextAlignment(.trailing)
                            .keyboardType(.numbersAndPunctuation)
                            .autocorrectionDisabled()
                            .textInputAutocapitalization(.never)
                    }
                }

                Section("诊断") {
                    NavigationLink("隧道日志") {
                        LogsView()
                    }
                }

                Section {
                    Button("退出登录", role: .destructive) {
                        confirmingSignOut = true
                    }
                }

                Section {
                    LabeledContent("版本", value: model.appVersion)
                }
            }
            .navigationTitle("设置")
            .confirmationDialog("退出登录会断开当前连接。", isPresented: $confirmingSignOut, titleVisibility: .visible) {
                Button("退出登录", role: .destructive) { model.signOut() }
            }
        }
    }

    private var endpointName: String {
        switch PanelEndpoint.fromStorage(model.login?.endpoint) {
        case .cloudflare: return "Cloudflare"
        case .aliyunCDN: return "阿里云 CDN"
        }
    }

    private var deviceID: String {
        guard let login = model.login else { return "—" }
        return IOSDeviceIdentity.deviceId(clientId: login.clientID, installId: Keychain.installID())
    }
}

struct LogsView: View {
    @EnvironmentObject private var tunnel: TunnelController
    @State private var lines: [String] = []

    var body: some View {
        ScrollViewReader { proxy in
            List(Array(lines.enumerated()), id: \.offset) { item in
                Text(item.element)
                    .font(.caption2.monospaced())
                    .textSelection(.enabled)
                    .id(item.offset)
            }
            .listStyle(.plain)
            .overlay {
                if lines.isEmpty {
                    Text(tunnel.isConnected ? "暂无日志" : "连接后可查看隧道日志")
                        .foregroundStyle(.secondary)
                }
            }
            .onChange(of: lines.count) { count in
                if count > 0 { proxy.scrollTo(count - 1, anchor: .bottom) }
            }
        }
        .navigationTitle("隧道日志")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            Button {
                UIPasteboard.general.string = lines.joined(separator: "\n")
            } label: {
                Image(systemName: "doc.on.doc")
            }
            .disabled(lines.isEmpty)
        }
        .task {
            while !Task.isCancelled {
                lines = await tunnel.fetchLogs()
                try? await Task.sleep(nanoseconds: 2_000_000_000)
            }
        }
    }
}
