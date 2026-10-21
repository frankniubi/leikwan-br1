import LeikwanIOSCore
import SwiftUI

/// 原生出口: the panel's own exit through an Ultra forward, no user node. Each carrier forward
/// is listed as its own card and switched on directly; these forwards never take a node, so
/// they are not in the bindings list below (Windows treats them as native outbound rules too).
struct NativeOutboundSection: View {
    @EnvironmentObject private var model: AppModel

    private let columns = [GridItem(.adaptive(minimum: 300), spacing: Metrics.gutter)]

    var body: some View {
        VStack(alignment: .leading, spacing: Metrics.gutter) {
            SectionTitle(L("Native outbound"))
            Text(L("The server provides the exit, so no node of your own is needed; turn on the exit you want. While native outbound is on, all traffic uses it and the bindings below do not run."))
                .font(.caption)
                .foregroundStyle(Palette.onSurfaceVariant)
            LazyVGrid(columns: columns, spacing: Metrics.gutter) {
                ForEach(model.nativeOutboundCarriers, id: \.forwardId) { carrier in
                    NativeCarrierCard(carrier: carrier, onChange: apply)
                }
            }

            if model.isNativeOutboundActive && hasOptions {
                VStack(alignment: .leading, spacing: 14) {
                    if model.nativeOutboundRouteScopes.count > 1 {
                        VStack(alignment: .leading, spacing: 8) {
                            FieldLabel(L("Traffic scope"))
                            Picker(L("Traffic scope"), selection: scopeBinding) {
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
                        Toggle(L("Bypass mainland China"), isOn: bypassChinaBinding)
                            .font(.subheadline)
                    }

                    if model.nativeOutboundUnrestrictedAllowed {
                        Toggle(isOn: unrestrictedBinding) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(NativeRoutePolicy.unrestricted.displayName)
                                    .font(.subheadline)
                                Text(L("Standard routing when off"))
                                    .font(.caption)
                                    .foregroundStyle(Palette.onSurfaceVariant)
                            }
                        }
                    }
                }
                .padding(14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .card()
            }
        }
    }

    /// The selected carrier has settings beyond on/off.
    private var hasOptions: Bool {
        return model.nativeOutboundRouteScopes.count > 1
            || model.nativeOutboundSelection?.routeScope == .global
            || model.nativeOutboundUnrestrictedAllowed
    }

    private var scopeExplanation: String {
        switch model.nativeOutboundSelection?.routeScope {
        case .cnOnly: return L("Only traffic to mainland China uses native outbound; the rest goes direct.")
        case .intlOnly: return L("Only traffic outside mainland China uses native outbound; the rest goes direct.")
        case .global: return L("All traffic uses native outbound.")
        case nil: return ""
        }
    }

    private func apply() {
        Task { await model.applyToRunningTunnel() }
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

/// One carrier forward: forward id, direction and relay entry like a binding card, switched on
/// directly. There is no node to pick; switching on another card moves native outbound to it.
private struct NativeCarrierCard: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController
    let carrier: NativeOutboundCarrier
    let onChange: () -> Void

    var body: some View {
        let forward = model.nativeOutboundForward(for: carrier)
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Pill(
                    text: L("#%@ · Native outbound", String(describing: carrier.forwardId)),
                    fill: isActive ? Palette.primary : Palette.secondaryContainer,
                    foreground: isActive ? Palette.onPrimary : Palette.onSecondaryContainer
                )
                Spacer()
                Toggle(L("Use this native outbound"), isOn: activeBinding)
                    .labelsHidden()
            }

            VStack(alignment: .leading, spacing: 2) {
                Text(forward?.directionTitle ?? model.nativeOutboundTitle(for: carrier))
                    .font(.headline)
                    .foregroundStyle(Palette.onSurface)
                    .lineLimit(1)
                Text(forward.map { model.endpointText(for: $0) } ?? "—")
                    .font(.caption.monospaced())
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
                    .textSelection(.enabled)
            }

            HStack(spacing: 8) {
                Image(systemName: "globe")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(Palette.onSurfaceVariant)
                Text(scopeSummary)
                    .font(.subheadline)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .lineLimit(1)
                Spacer(minLength: 0)
            }
            .padding(10)
            .background(Palette.surfaceContainerHigh, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
        }
        .padding(14)
        .card(selected: isActive)
        .opacity(isActive ? 1 : 0.85)
        .contentShape(Rectangle())
        .onTapGesture {
            if !isActive { activeBinding.wrappedValue = true }
        }
    }

    private var isActive: Bool {
        model.isNativeOutboundActive && model.nativeOutboundSelection?.forwardId == carrier.forwardId
    }

    /// Panel exit, no node: the current scope when active, else what the carrier offers.
    private var scopeSummary: String {
        if isActive, let selection = model.nativeOutboundSelection {
            return L("Server exit · %@", selection.routeScope.displayName)
        }
        return L("Server exit · %@", carrier.routeScopes.map(\.displayName).joined(separator: " / "))
    }

    private var activeBinding: Binding<Bool> {
        Binding(
            get: { isActive },
            set: { value in
                if value {
                    model.selectNativeOutboundForward(carrier.forwardId)
                    model.setNativeOutboundEnabled(true)
                    onChange()
                } else if isActive {
                    model.setNativeOutboundEnabled(false)
                }
            }
        )
    }
}
