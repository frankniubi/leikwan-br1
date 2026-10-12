import Charts
import LeikwanIOSCore
import SwiftUI

/// FlClash-style dashboard: a grid of tonal cards with a floating start button.
struct DashboardView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController
    @EnvironmentObject private var probe: NetworkProbe

    private let columns = [GridItem(.adaptive(minimum: 160), spacing: Metrics.gutter)]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Metrics.gutter) {
                    if let alert = alertText {
                        AlertCard(text: alert)
                    }
                    NetworkSpeedCard()
                    LazyVGrid(columns: columns, spacing: Metrics.gutter) {
                        OutboundModeCard()
                        TrafficUsageCard()
                        CurrentRouteCard()
                        NetworkDetectionCard()
                        RunTimeCard()
                        ConnectionsCard()
                    }
                }
                .padding(Metrics.pagePadding)
                .padding(.bottom, 88)
            }
            .refreshable {
                await model.refreshCatalog()
                await probe.detect()
            }
            .pageBackground()
            .navigationTitle("仪表盘")
            .overlay(alignment: .bottomTrailing) {
                StartButton()
                    .padding(Metrics.pagePadding + 4)
            }
        }
        .task(id: tunnel.status) {
            // Re-detect the exit IP whenever the tunnel comes up or goes down.
            if tunnel.status == .connected || tunnel.status == .disconnected || tunnel.status == .invalid {
                try? await Task.sleep(nanoseconds: 800_000_000)
                await probe.detect()
            }
        }
    }

    private var alertText: String? {
        if let alert = tunnel.snapshot.alert {
            return Self.describeAlert(alert)
        }
        if !tunnel.isConnected, let error = tunnel.lastError {
            return error
        }
        return model.catalogError
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

private struct AlertCard: View {
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
            Text(text)
                .font(.subheadline)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .foregroundStyle(Palette.onErrorContainer)
        .padding(14)
        .card(fill: Palette.errorContainer)
    }
}

// MARK: Cards

private struct NetworkSpeedCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            CardHeader("网络速度", systemImage: "speedometer", trailing: AnyView(
                HStack(spacing: 12) {
                    RateLabel(symbol: "arrow.up", value: tunnel.snapshot.uplink, color: Palette.upload)
                    RateLabel(symbol: "arrow.down", value: tunnel.snapshot.downlink, color: Palette.download)
                }
            ))
            Chart {
                ForEach(paddedHistory) { sample in
                    AreaMark(x: .value("时间", sample.index), y: .value("下载", Double(sample.downlink)))
                        .foregroundStyle(Palette.download.opacity(0.18))
                        .interpolationMethod(.monotone)
                    LineMark(x: .value("时间", sample.index), y: .value("下载", Double(sample.downlink)), series: .value("方向", "下载"))
                        .foregroundStyle(Palette.download)
                        .interpolationMethod(.monotone)
                    LineMark(x: .value("时间", sample.index), y: .value("上传", Double(sample.uplink)), series: .value("方向", "上传"))
                        .foregroundStyle(Palette.upload)
                        .interpolationMethod(.monotone)
                }
            }
            .chartXAxis(.hidden)
            .chartYAxis {
                AxisMarks(position: .leading, values: .automatic(desiredCount: 3)) { value in
                    AxisGridLine().foregroundStyle(Palette.outlineVariant.opacity(0.5))
                    AxisValueLabel {
                        if let bytes = value.as(Double.self) {
                            Text(ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .binary))
                                .font(.caption2)
                                .foregroundStyle(Palette.onSurfaceVariant)
                        }
                    }
                }
            }
            .chartYScale(domain: 0 ... max(1024.0, peak * 1.2))
            .frame(height: 120)
        }
        .padding(16)
        .card()
    }

    private struct Point: Identifiable {
        let index: Int
        let uplink: Int64
        let downlink: Int64
        var id: Int { index }
    }

    /// Always 60 points so the chart scrolls in from the right.
    private var paddedHistory: [Point] {
        let samples = tunnel.speedHistory
        let missing = max(0, 60 - samples.count)
        var points: [Point] = (0 ..< missing).map { Point(index: $0, uplink: 0, downlink: 0) }
        for (offset, sample) in samples.enumerated() {
            points.append(Point(index: missing + offset, uplink: sample.uplink, downlink: sample.downlink))
        }
        return points
    }

    private var peak: Double {
        Double(tunnel.speedHistory.map { max($0.uplink, $0.downlink) }.max() ?? 0)
    }
}

private struct RateLabel: View {
    let symbol: String
    let value: Int64
    let color: Color

    var body: some View {
        HStack(spacing: 3) {
            Image(systemName: symbol)
                .font(.caption2.weight(.bold))
                .foregroundStyle(color)
            Text(ByteFormat.rate(value))
                .font(.caption.monospacedDigit())
                .foregroundStyle(Palette.onSurface)
        }
    }
}

private struct OutboundModeCard: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            CardHeader("出站模式", systemImage: "arrow.triangle.branch")
            VStack(spacing: 4) {
                ForEach(RoutingGlobalMode.allCases, id: \.self) { mode in
                    ModeRow(mode: mode, selected: model.routing.mode == mode) {
                        guard model.routing.mode != mode else { return }
                        model.routing.mode = mode
                        Task { await model.applyToRunningTunnel() }
                    }
                }
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }
}

private struct ModeRow: View {
    let mode: RoutingGlobalMode
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 8) {
                Image(systemName: icon)
                    .font(.footnote.weight(.semibold))
                    .frame(width: 18)
                Text(title)
                    .font(.subheadline.weight(selected ? .semibold : .regular))
                Spacer(minLength: 0)
            }
            .foregroundStyle(selected ? colors.foreground : Palette.onSurfaceVariant)
            .padding(.horizontal, 10)
            .padding(.vertical, 7)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(selected ? colors.container : Color.clear)
            )
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: selected)
    }

    private var title: String {
        switch mode {
        case .rule: return "规则"
        case .global: return "全局"
        case .direct: return "直连"
        }
    }

    private var icon: String {
        switch mode {
        case .rule: return "list.bullet.rectangle"
        case .global: return "globe"
        case .direct: return "arrow.right"
        }
    }

    private var colors: (container: Color, foreground: Color) {
        switch mode {
        case .rule: return (Palette.primaryContainer, Palette.onPrimaryContainer)
        case .global: return (Palette.warningContainer, Palette.onWarningContainer)
        case .direct: return (Palette.successContainer, Palette.onSuccessContainer)
        }
    }
}

private struct TrafficUsageCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        let up = tunnel.snapshot.uplinkTotal
        let down = tunnel.snapshot.downlinkTotal
        VStack(alignment: .leading, spacing: 10) {
            CardHeader("流量统计", systemImage: "chart.pie")
            HStack(spacing: 12) {
                Donut(up: Double(up), down: Double(down))
                    .frame(width: 64, height: 64)
                VStack(alignment: .leading, spacing: 8) {
                    LegendRow(color: Palette.upload, title: "上传", value: ByteFormat.total(up))
                    LegendRow(color: Palette.download, title: "下载", value: ByteFormat.total(down))
                }
            }
            .frame(maxHeight: .infinity)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }
}

private struct Donut: View {
    let up: Double
    let down: Double

    var body: some View {
        let total = up + down
        let upShare = total > 0 ? up / total : 0
        ZStack {
            Circle()
                .stroke(Palette.surfaceContainerHighest, lineWidth: 10)
            if total > 0 {
                Circle()
                    .trim(from: 0, to: upShare)
                    .stroke(Palette.upload, style: StrokeStyle(lineWidth: 10, lineCap: .butt))
                Circle()
                    .trim(from: upShare, to: 1)
                    .stroke(Palette.download, style: StrokeStyle(lineWidth: 10, lineCap: .butt))
            }
        }
        .rotationEffect(.degrees(-90))
    }
}

private struct LegendRow: View {
    let color: Color
    let title: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 1) {
            HStack(spacing: 5) {
                Circle().fill(color).frame(width: 7, height: 7)
                Text(title)
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            Text(value)
                .font(.footnote.weight(.semibold).monospacedDigit())
                .foregroundStyle(Palette.onSurface)
                .lineLimit(1)
                .minimumScaleFactor(0.7)
        }
    }
}

private struct CurrentRouteCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var navigator: Navigator
    @EnvironmentObject private var latency: LatencyStore

    var body: some View {
        Button {
            navigator.section = .proxies
        } label: {
            VStack(alignment: .leading, spacing: 10) {
                CardHeader("当前线路", systemImage: "point.3.connected.trianglepath.dotted", trailing: AnyView(
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(Palette.onSurfaceVariant)
                ))
                VStack(alignment: .leading, spacing: 4) {
                    Text(model.routeTitle)
                        .font(.headline)
                        .foregroundStyle(Palette.onSurface)
                        .lineLimit(2)
                        .minimumScaleFactor(0.8)
                    if let detail = model.routeDetail {
                        Text(detail)
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                            .lineLimit(1)
                    }
                }
                Spacer(minLength: 0)
                if let node = model.selectedNode {
                    let result = latency.result(for: node)
                    Text(LatencyStyle.text(result?.milliseconds, failed: result?.failed ?? false))
                        .font(.caption.monospacedDigit().weight(.semibold))
                        .foregroundStyle(LatencyStyle.color(result?.milliseconds))
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
            .card()
        }
        .buttonStyle(.plain)
        .task(id: model.selectedNodeID) {
            if let node = model.selectedNode, latency.result(for: node) == nil {
                await latency.test(node)
            }
        }
    }
}

private struct NetworkDetectionCard: View {
    @EnvironmentObject private var probe: NetworkProbe

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader("网络检测", systemImage: "network", trailing: AnyView(
                Button {
                    Task { await probe.detect() }
                } label: {
                    Image(systemName: "arrow.clockwise")
                        .font(.caption.weight(.semibold))
                }
                .buttonStyle(.plain)
                .foregroundStyle(Palette.onSurfaceVariant)
                .disabled(probe.detecting)
            ))
            Spacer(minLength: 0)
            if probe.detecting {
                ProgressView()
            } else if let info = probe.exitIP {
                Text(info.flag)
                    .font(.system(size: 30))
                Text(info.ip)
                    .font(.footnote.monospaced().weight(.medium))
                    .foregroundStyle(Palette.onSurface)
                    .lineLimit(1)
                    .minimumScaleFactor(0.6)
                    .textSelection(.enabled)
            } else {
                Text(probe.failed ? "检测失败" : "—")
                    .font(.subheadline)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }
}

private struct RunTimeCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader("运行时长", systemImage: "timer")
            Spacer(minLength: 0)
            if tunnel.isConnected {
                RunTimeText(since: tunnel.connectedSince)
                    .font(.title2.weight(.semibold).monospacedDigit())
                    .foregroundStyle(Palette.onSurface)
            } else {
                Text(statusText)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            Pill(
                text: tunnel.isConnected ? "已连接" : statusText,
                fill: tunnel.isConnected ? Palette.successContainer : Palette.secondaryContainer,
                foreground: tunnel.isConnected ? Palette.onSuccessContainer : Palette.onSecondaryContainer
            )
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }

    private var statusText: String {
        switch tunnel.status {
        case .connecting: return "连接中"
        case .disconnecting: return "断开中"
        case .reasserting: return "重连中"
        case .connected: return "已连接"
        default: return "未连接"
        }
    }
}

private struct ConnectionsCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader("连接", systemImage: "link")
            Spacer(minLength: 0)
            HStack(alignment: .firstTextBaseline, spacing: 4) {
                Text("\(tunnel.snapshot.connectionsOut)")
                    .font(.title2.weight(.semibold).monospacedDigit())
                    .foregroundStyle(Palette.onSurface)
                Text("个")
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            HStack(spacing: 6) {
                Image(systemName: "memorychip")
                    .font(.caption2)
                Text("内存 " + (tunnel.snapshot.memory > 0 ? ByteFormat.total(tunnel.snapshot.memory) : "—"))
                    .font(.caption.monospacedDigit())
            }
            .foregroundStyle(Palette.onSurfaceVariant)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }
}
