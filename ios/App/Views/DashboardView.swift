import Charts
import LeikwanIOSCore
import SwiftUI

/// FlClash-style dashboard: a grid of tonal cards with a floating start button.
struct DashboardView: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController
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
                        RulesSummaryCard()
                        TrafficUsageCard()
                        CurrentRouteCard()
                        RunTimeCard()
                        ConnectionsCard()
                    }
                }
                .padding(Metrics.pagePadding)
                .padding(.bottom, 88)
            }
            .refreshable {
                await model.refreshCatalog()
            }
            .pageBackground()
            .navigationTitle(L("Dashboard"))
            .overlay(alignment: .bottomTrailing) {
                StartButton()
                    .padding(Metrics.pagePadding + 4)
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
            return L("Online device limit of the plan reached; this device resumes when a slot frees up")
        }
        switch alert {
        case "device_kicked": return L("This device was signed out by the server")
        case "device_banned": return L("This device was banned by the server")
        case "device_untrusted": return L("The server does not trust this device")
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
            CardHeader(L("Network speed"), systemImage: "speedometer", trailing: AnyView(
                HStack(spacing: 12) {
                    RateLabel(symbol: "arrow.up", value: tunnel.snapshot.uplink, color: Palette.upload)
                    RateLabel(symbol: "arrow.down", value: tunnel.snapshot.downlink, color: Palette.download)
                }
            ))
            Chart {
                ForEach(paddedHistory) { sample in
                    AreaMark(x: .value(L("Time"), sample.index), y: .value(L("Download"), Double(sample.downlink)))
                        .foregroundStyle(Palette.download.opacity(0.18))
                        .interpolationMethod(.monotone)
                    LineMark(x: .value(L("Time"), sample.index), y: .value(L("Download"), Double(sample.downlink)), series: .value("Direction", "Download"))
                        .foregroundStyle(Palette.download)
                        .interpolationMethod(.monotone)
                    LineMark(x: .value(L("Time"), sample.index), y: .value(L("Upload"), Double(sample.uplink)), series: .value("Direction", "Upload"))
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
            CardHeader(L("Outbound mode"), systemImage: "arrow.triangle.branch")
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
        .frame(maxWidth: .infinity, minHeight: 170, alignment: .topLeading)
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
                VStack(alignment: .leading, spacing: 0) {
                    Text(title)
                        .font(.subheadline.weight(selected ? .semibold : .regular))
                    if selected {
                        Text(caption)
                            .font(.caption2)
                            .opacity(0.8)
                            .lineLimit(1)
                            .minimumScaleFactor(0.8)
                    }
                }
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
        case .rule: return L("Rule")
        case .global: return L("Global")
        case .direct: return L("Direct")
        }
    }

    private var caption: String {
        switch mode {
        case .rule: return L("By routing rules; unmatched traffic uses the proxy")
        case .global: return L("Everything through the proxy")
        case .direct: return L("Everything direct")
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

/// What the rule mode currently does, at a glance; opens the rules tab.
private struct RulesSummaryCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var navigator: Navigator

    var body: some View {
        Button {
            navigator.section = .rules
        } label: {
            VStack(alignment: .leading, spacing: 8) {
                CardHeader(L("Routing rules"), systemImage: "list.bullet.rectangle.portrait", trailing: AnyView(
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(Palette.onSurfaceVariant)
                ))
                if model.routing.mode == .rule {
                    SummaryLine(title: L("Mainland China direct"), value: model.routing.bypassChina ? L("On") : L("Off"))
                    SummaryLine(title: L("Apps"), value: "\(model.routing.appPresets.count)")
                    SummaryLine(title: L("Countries / regions"), value: "\(model.routing.countries.count)")
                    SummaryLine(title: L("Custom"), value: "\(model.routing.customRules.count)")
                    Text(L("Everything else uses the proxy"))
                        .font(.caption2)
                        .foregroundStyle(Palette.onSurfaceVariant)
                } else {
                    Spacer(minLength: 0)
                    Text(model.routing.mode == .global ? L("Rules are off in Global mode") : L("Rules are off in Direct mode"))
                        .font(.subheadline)
                        .foregroundStyle(Palette.onSurfaceVariant)
                    Spacer(minLength: 0)
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 170, alignment: .topLeading)
            .card()
        }
        .buttonStyle(.plain)
    }
}

private struct SummaryLine: View {
    let title: String
    let value: String

    var body: some View {
        HStack {
            Text(title)
                .font(.caption)
                .foregroundStyle(Palette.onSurfaceVariant)
            Spacer()
            Text(value)
                .font(.caption.weight(.semibold).monospacedDigit())
                .foregroundStyle(Palette.onSurface)
        }
    }
}

private struct TrafficUsageCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        let up = tunnel.snapshot.uplinkTotal
        let down = tunnel.snapshot.downlinkTotal
        VStack(alignment: .leading, spacing: 10) {
            CardHeader(L("Traffic"), systemImage: "chart.pie")
            HStack(spacing: 12) {
                Donut(up: Double(up), down: Double(down))
                    .frame(width: 64, height: 64)
                VStack(alignment: .leading, spacing: 8) {
                    LegendRow(color: Palette.upload, title: L("Upload"), value: ByteFormat.total(up))
                    LegendRow(color: Palette.download, title: L("Download"), value: ByteFormat.total(down))
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
                CardHeader(L("Current line"), systemImage: "point.3.connected.trianglepath.dotted", trailing: AnyView(
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
                if let node = model.defaultBindingRow?.node {
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
        .task(id: model.defaultBindingRow?.node?.id) {
            if let node = model.defaultBindingRow?.node, latency.result(for: node) == nil {
                await latency.test(node)
            }
        }
    }
}

private struct RunTimeCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader(L("Uptime"), systemImage: "timer")
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
                text: tunnel.isConnected ? L("Connected") : statusText,
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
        case .connecting: return L("Connecting")
        case .disconnecting: return L("Disconnecting")
        case .reasserting: return L("Reconnecting")
        case .connected: return L("Connected")
        default: return L("Not connected")
        }
    }
}

private struct ConnectionsCard: View {
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader(L("Connections"), systemImage: "link")
            Spacer(minLength: 0)
            HStack(alignment: .firstTextBaseline, spacing: 4) {
                Text("\(tunnel.snapshot.connectionsOut)")
                    .font(.title2.weight(.semibold).monospacedDigit())
                    .foregroundStyle(Palette.onSurface)
                Text("open")
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
            HStack(spacing: 6) {
                Image(systemName: "memorychip")
                    .font(.caption2)
                Text(L("Memory %@", tunnel.snapshot.memory > 0 ? ByteFormat.total(tunnel.snapshot.memory) : "—"))
                    .font(.caption.monospacedDigit())
            }
            .foregroundStyle(Palette.onSurfaceVariant)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 150, alignment: .topLeading)
        .card()
    }
}
