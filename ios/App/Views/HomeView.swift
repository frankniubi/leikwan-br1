import LeikwanIOSCore
import NetworkExtension
import SwiftUI

struct HomeView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ConnectCard()
                        .listRowInsets(EdgeInsets())
                        .listRowBackground(Color.clear)
                }

                Section {
                    Picker("模式", selection: $model.routing.mode) {
                        Text("规则").tag(RoutingGlobalMode.rule)
                        Text("全局").tag(RoutingGlobalMode.global)
                        Text("直连").tag(RoutingGlobalMode.direct)
                    }
                    .pickerStyle(.segmented)
                    .onChange(of: model.routing.mode) { _ in
                        Task { await model.applyToRunningTunnel() }
                    }
                } header: {
                    Text("分流模式")
                }

                Section {
                    ForwardPickerRow()
                    NodePickerRow()
                } header: {
                    Text("线路")
                } footer: {
                    if let hidden = model.catalog?.unsupportedHiddenCount, hidden > 0 {
                        Text("另有 \(hidden) 条转发使用 iOS 暂不支持的模式，已隐藏。")
                    }
                }

                if tunnel.isConnected {
                    Section("流量") {
                        TrafficRow(title: "上传", rate: tunnel.snapshot.uplink, total: tunnel.snapshot.uplinkTotal, symbol: "arrow.up")
                        TrafficRow(title: "下载", rate: tunnel.snapshot.downlink, total: tunnel.snapshot.downlinkTotal, symbol: "arrow.down")
                        LabeledContent("连接数", value: "\(tunnel.snapshot.connectionsOut)")
                    }
                    if !tunnel.snapshot.runtimes.isEmpty {
                        Section("转发运行时") {
                            ForEach(tunnel.snapshot.runtimes, id: \.name) { runtime in
                                RuntimeRow(runtime: runtime)
                            }
                        }
                    }
                }

                if let error = model.catalogError {
                    Section {
                        Label(error, systemImage: "exclamationmark.triangle")
                            .foregroundStyle(.orange)
                            .font(.footnote)
                    }
                }
            }
            .navigationTitle("LeiKwan")
            .refreshable {
                await model.refreshCatalog()
            }
        }
    }
}

private struct ConnectCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(spacing: 14) {
            Button {
                Task { await model.toggleConnection() }
            } label: {
                ZStack {
                    Circle()
                        .fill(tunnel.isConnected ? Color.green.gradient : Color.accentColor.gradient)
                        .frame(width: 132, height: 132)
                        .shadow(color: (tunnel.isConnected ? Color.green : Color.accentColor).opacity(0.35), radius: 16, y: 6)
                    if tunnel.isBusy {
                        ProgressView()
                            .tint(.white)
                            .scaleEffect(1.4)
                    } else {
                        Image(systemName: "power")
                            .font(.system(size: 46, weight: .semibold))
                            .foregroundStyle(.white)
                    }
                }
            }
            .buttonStyle(.plain)
            .accessibilityLabel(tunnel.isConnected ? "断开" : "连接")

            Text(statusText)
                .font(.headline)
            if let alert = tunnel.snapshot.alert {
                Label(Self.describeAlert(alert), systemImage: "exclamationmark.octagon.fill")
                    .font(.footnote)
                    .foregroundStyle(.red)
            } else if let error = tunnel.lastError, !tunnel.isConnected {
                Text(error)
                    .font(.footnote)
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 20)
    }

    private var statusText: String {
        switch tunnel.status {
        case .connected: return "已连接"
        case .connecting: return "正在连接…"
        case .disconnecting: return "正在断开…"
        case .reasserting: return "正在重连…"
        case .disconnected, .invalid: return "未连接"
        @unknown default: return "未连接"
        }
    }

    static func describeAlert(_ alert: String) -> String {
        if alert.hasPrefix("device_limit_reached") {
            return "在线设备数已达套餐上限，空出名额后会自动恢复"
        }
        switch alert {
        case "device_kicked": return "此设备已被面板踢下线"
        case "device_banned": return "此设备已被面板封禁"
        case "device_untrusted": return "面板不信任此设备"
        default: return alert
        }
    }
}

private struct ForwardPickerRow: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        Picker(selection: $model.selectedForwardID) {
            Text("不使用转发（直连节点）").tag(Int64?.none)
            ForEach(model.catalog?.supported ?? [], id: \.forwardId) { forward in
                Text("\(forward.serviceName) · \(forward.methodDisplay)").tag(Int64?.some(forward.forwardId))
            }
        } label: {
            Label("转发", systemImage: "point.3.connected.trianglepath.dotted")
        }
    }
}

private struct NodePickerRow: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        Picker(selection: $model.selectedNodeID) {
            if model.nodes.isEmpty {
                Text("尚未添加节点").tag(Int64?.none)
            }
            ForEach(model.nodes) { node in
                Text(node.name).tag(Int64?.some(node.id))
            }
        } label: {
            Label("节点", systemImage: "server.rack")
        }
    }
}

private struct TrafficRow: View {
    let title: String
    let rate: Int64
    let total: Int64
    let symbol: String

    var body: some View {
        HStack {
            Label(title, systemImage: symbol)
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                Text(ByteFormat.rate(rate))
                    .font(.body.monospacedDigit())
                Text(ByteFormat.total(total))
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)
            }
        }
    }
}

private struct RuntimeRow: View {
    let runtime: RuntimeStatus

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack {
                Circle()
                    .fill(runtime.running ? Color.green : Color.red)
                    .frame(width: 8, height: 8)
                Text(runtime.name)
                    .font(.subheadline.monospaced())
            }
            if let error = runtime.error {
                Text(error)
                    .font(.caption)
                    .foregroundStyle(.red)
            }
        }
    }
}
