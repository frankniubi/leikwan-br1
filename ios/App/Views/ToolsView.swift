import LeikwanIOSCore
import SwiftUI

/// Settings and diagnostics (FlClash tools page).
struct ToolsView: View {
    @EnvironmentObject private var model: AppModel
    @State private var confirmingSignOut = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    NavigationLink {
                        RulesView()
                    } label: {
                        ToolRow(title: "分流规则", detail: "应用分流、国家、自定义规则", systemImage: "arrow.triangle.branch")
                    }
                    NavigationLink {
                        LogsView()
                    } label: {
                        ToolRow(title: "隧道日志", detail: "运行时与 sing-box 日志", systemImage: "doc.text.magnifyingglass")
                    }
                } header: {
                    Text("工具")
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    Toggle(isOn: $model.routing.enableIPv6) {
                        ToolRow(title: "IPv6", detail: "隧道内启用 IPv6", systemImage: "6.circle")
                    }
                    HStack {
                        ToolRow(title: "远程 DNS", detail: nil, systemImage: "server.rack")
                        TextField("1.1.1.1", text: $model.routing.dnsServer)
                            .multilineTextAlignment(.trailing)
                            .keyboardType(.numbersAndPunctuation)
                            .autocorrectionDisabled()
                            .textInputAutocapitalization(.never)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                } header: {
                    Text("网络")
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    InfoRow(title: "客户端 ID", value: model.login.map { String($0.clientID) } ?? "—")
                    InfoRow(title: "面板线路", value: endpointName)
                    InfoRow(title: "设备 ID", value: deviceID, monospaced: true)
                    Button {
                        Task {
                            await model.refreshCatalog()
                            model.notice = model.catalogError ?? "转发列表已刷新"
                        }
                    } label: {
                        ToolRow(title: "刷新转发列表", detail: nil, systemImage: "arrow.clockwise")
                    }
                    .foregroundStyle(Palette.onSurface)
                    if let catalog = model.catalog, !catalog.hidden.isEmpty {
                        NavigationLink {
                            HiddenForwardsView(hidden: catalog.hidden)
                        } label: {
                            ToolRow(title: "iOS 暂不支持的转发", detail: "\(catalog.hidden.count) 条", systemImage: "eye.slash")
                        }
                    }
                } header: {
                    Text("账号")
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    InfoRow(title: "版本", value: model.appVersion)
                    Button(role: .destructive) {
                        confirmingSignOut = true
                    } label: {
                        ToolRow(title: "退出登录", detail: nil, systemImage: "rectangle.portrait.and.arrow.right", tint: Palette.bad)
                    }
                } header: {
                    Text("关于")
                }
                .listRowBackground(Palette.surfaceContainer)
            }
            .scrollContentBackground(.hidden)
            .pageBackground()
            .navigationTitle("工具")
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

private struct ToolRow: View {
    let title: String
    let detail: String?
    let systemImage: String
    var tint: Color = Palette.onPrimaryContainer

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: systemImage)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(tint)
                .frame(width: 32, height: 32)
                .background(Palette.primaryContainer.opacity(0.7), in: RoundedRectangle(cornerRadius: 9, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .foregroundStyle(tint == Palette.onPrimaryContainer ? Palette.onSurface : tint)
                if let detail {
                    Text(detail)
                        .font(.caption)
                        .foregroundStyle(Palette.onSurfaceVariant)
                }
            }
        }
        .padding(.vertical, 2)
    }
}

private struct InfoRow: View {
    let title: String
    let value: String
    var monospaced = false

    var body: some View {
        HStack {
            Text(title)
                .foregroundStyle(Palette.onSurface)
            Spacer()
            Text(value)
                .font(monospaced ? .caption.monospaced() : .body)
                .foregroundStyle(Palette.onSurfaceVariant)
                .lineLimit(1)
                .minimumScaleFactor(0.6)
                .textSelection(.enabled)
        }
    }
}

private struct HiddenForwardsView: View {
    let hidden: [IOSHiddenForward]

    var body: some View {
        List {
            Section {
                ForEach(Array(hidden.enumerated()), id: \.offset) { _, item in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(item.serviceName.isEmpty ? "转发 #\(item.forwardId.map(String.init) ?? "?")" : item.serviceName)
                            .foregroundStyle(Palette.onSurface)
                        Text(item.reason.message)
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                    .listRowBackground(Palette.surfaceContainer)
                }
            } footer: {
                Text("iOS 支持 Ultra 转发，以及 plain_tcp_mux 入口的 Bond 转发。")
            }
        }
        .scrollContentBackground(.hidden)
        .pageBackground()
        .navigationTitle("暂不支持的转发")
        .navigationBarTitleDisplayMode(.inline)
    }
}
