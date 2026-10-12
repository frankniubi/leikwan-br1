import LeikwanIOSCore
import SwiftUI

/// 原生出口: the panel's own exit through an Ultra forward, no user node.
struct NativeOutboundSection: View {
    @EnvironmentObject private var model: AppModel

    private let columns = [GridItem(.adaptive(minimum: 160), spacing: Metrics.gutter)]

    var body: some View {
        VStack(alignment: .leading, spacing: Metrics.gutter) {
            SectionTitle("原生出口")
            VStack(alignment: .leading, spacing: 14) {
                Toggle(isOn: enabledBinding) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("使用原生出口")
                            .font(.headline)
                            .foregroundStyle(Palette.onSurface)
                        Text("由面板提供出口，无需自带节点")
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                }

                if model.isNativeOutboundActive {
                    if model.nativeOutboundCarriers.count > 1 {
                        VStack(alignment: .leading, spacing: 8) {
                            FieldLabel("出口转发")
                            LazyVGrid(columns: columns, spacing: 8) {
                                ForEach(model.nativeOutboundCarriers, id: \.forwardId) { carrier in
                                    CarrierChip(
                                        title: model.nativeOutboundTitle(for: carrier),
                                        selected: model.nativeOutboundSelection?.forwardId == carrier.forwardId
                                    ) {
                                        model.selectNativeOutboundForward(carrier.forwardId)
                                        apply()
                                    }
                                }
                            }
                        }
                    }

                    if model.nativeOutboundRouteScopes.count > 1 {
                        VStack(alignment: .leading, spacing: 8) {
                            FieldLabel("流量范围")
                            Picker("流量范围", selection: scopeBinding) {
                                ForEach(model.nativeOutboundRouteScopes, id: \.self) { scope in
                                    Text(scope.displayName).tag(scope)
                                }
                            }
                            .pickerStyle(.segmented)
                            Text(scopeExplanation)
                                .font(.caption)
                                .foregroundStyle(Palette.onSurfaceVariant)
                        }
                    }

                    if model.nativeOutboundSelection?.routeScope == .global {
                        Toggle("绕过中国大陆", isOn: bypassChinaBinding)
                            .font(.subheadline)
                    }

                    if model.nativeOutboundUnrestrictedAllowed {
                        Toggle(isOn: unrestrictedBinding) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(NativeRoutePolicy.unrestricted.displayName)
                                    .font(.subheadline)
                                Text("关闭时使用标准路由")
                                    .font(.caption)
                                    .foregroundStyle(Palette.onSurfaceVariant)
                            }
                        }
                    }

                    Label("原生出口开启时，下方的转发和节点选择不生效。", systemImage: "info.circle")
                        .font(.caption)
                        .foregroundStyle(Palette.onPrimaryContainer)
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Palette.primaryContainer.opacity(0.6), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
            }
            .padding(14)
            .card(selected: model.isNativeOutboundActive)
        }
    }

    private var scopeExplanation: String {
        switch model.nativeOutboundSelection?.routeScope {
        case .cnOnly: return "只有访问中国大陆的流量走原生出口，其余直连。"
        case .intlOnly: return "只有访问中国大陆以外的流量走原生出口，其余直连。"
        case .global: return "全部流量走原生出口。"
        case nil: return ""
        }
    }

    private func apply() {
        Task { await model.applyToRunningTunnel() }
    }

    private var enabledBinding: Binding<Bool> {
        Binding(
            get: { model.isNativeOutboundActive },
            set: { value in
                model.setNativeOutboundEnabled(value)
                if value { apply() }
            }
        )
    }

    private var scopeBinding: Binding<NativeRouteScope> {
        Binding(
            get: { model.nativeOutboundSelection?.routeScope ?? .global },
            set: { scope in
                model.setNativeOutboundRouteScope(scope)
                apply()
            }
        )
    }

    private var bypassChinaBinding: Binding<Bool> {
        Binding(
            get: { model.nativeOutboundSelection?.bypassChina ?? false },
            set: { value in
                model.setNativeOutboundBypassChina(value)
                apply()
            }
        )
    }

    private var unrestrictedBinding: Binding<Bool> {
        Binding(
            get: { model.nativeOutboundSelection?.routePolicy == .unrestricted },
            set: { value in
                model.setNativeOutboundRoutePolicy(value ? .unrestricted : .restricted)
                apply()
            }
        )
    }
}

private struct FieldLabel: View {
    let text: String

    init(_ text: String) {
        self.text = text
    }

    var body: some View {
        Text(text)
            .font(.caption.weight(.medium))
            .foregroundStyle(Palette.onSurfaceVariant)
    }
}

private struct CarrierChip: View {
    let title: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if selected {
                    Image(systemName: "checkmark")
                        .font(.caption.weight(.bold))
                }
                Text(title)
                    .font(.subheadline)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
            }
            .foregroundStyle(selected ? Palette.onPrimary : Palette.onSurface)
            .padding(.horizontal, 12)
            .padding(.vertical, 9)
            .frame(maxWidth: .infinity)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(selected ? Palette.primary : Palette.surfaceContainerHigh)
            )
        }
        .buttonStyle(.plain)
    }
}
