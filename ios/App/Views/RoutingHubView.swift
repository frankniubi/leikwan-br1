import LeikwanIOSCore
import SwiftUI
import UIKit

/// The Rules tab: routing rules, scripts and modules as three cards, then the modules'
/// panels. Modelled on Surge's "Modify" page and cut down to what a packet-forwarding client
/// does: there is no HTTP rewrite and no TLS interception here.
struct RoutingHubView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        let panels = model.modulePanels
        let scripts = model.moduleScripts
        ScrollView {
            VStack(spacing: Metrics.gutter) {
                FeatureCard(
                    title: L("Routing rules"),
                    systemImage: "arrow.triangle.branch",
                    detail: routingDetail,
                    colors: [HubColor.hex(0x3949AB), HubColor.hex(0x5E6AD2)]
                ) {
                    ModeMenu()
                } actions: {
                    NavigationLink {
                        RulesView()
                    } label: {
                        CardChip(text: L("Rules"))
                    }
                }

                FeatureCard(
                    title: L("Scripts"),
                    systemImage: "chevron.left.forwardslash.chevron.right",
                    detail: L("JavaScript from your modules: on a schedule, when the network changes, or from a panel. Runs while the VPN is connected."),
                    colors: [HubColor.hex(0xAD1457), HubColor.hex(0xD6336C)]
                ) {
                    Toggle(L("Run scripts"), isOn: Binding(
                        get: { model.moduleLibrary.scriptsEnabled },
                        set: { model.setScriptsEnabled($0) }
                    ))
                    .labelsHidden()
                    .tint(HubColor.hex(0x34C759))
                } actions: {
                    NavigationLink {
                        ScriptsView()
                    } label: {
                        CardChip(text: scripts.isEmpty ? L("Scripts") : L("Scripts (%@)", String(scripts.count)))
                    }
                }

                FeatureCard(
                    title: L("Modules"),
                    systemImage: "puzzlepiece.extension.fill",
                    detail: L("A module is a set of rules, scripts and panels you install from a URL. Its rules apply before your own."),
                    colors: [HubColor.hex(0x6A1B9A), HubColor.hex(0x9C4DCC)]
                ) {
                    EmptyView()
                } actions: {
                    NavigationLink {
                        ModulesView()
                    } label: {
                        CardChip(text: model.moduleLibrary.modules.isEmpty ? L("Modules") : L("Modules (%@)", String(model.moduleLibrary.modules.count)))
                    }
                }

                if !panels.isEmpty {
                    SectionTitle(L("Panels"))
                    ForEach(panels) { panel in
                        PanelCard(panel: panel, script: scripts.first { $0.key == panel.scriptKey })
                    }
                }

                Text(L("HTTP rewrite, MITM and request/response scripts are not available: Bridge forwards packets and never decrypts your traffic."))
                    .font(.footnote)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 4)
            }
            .padding(Metrics.pagePadding)
            .readableColumn(maxWidth: 640)
        }
        .pageBackground()
        .navigationTitle(L("Rules"))
        .task {
            // Panels with an update interval refresh while this page is on screen.
            while !Task.isCancelled {
                model.reloadScriptRuns()
                await model.refreshDuePanels()
                try? await Task.sleep(nanoseconds: 30 * NSEC_PER_SEC)
            }
        }
    }

    private var routingDetail: String {
        switch model.routing.mode {
        case .rule:
            let moduleRules = model.moduleCustomRules.count
            let own = model.routing.customRules.count
            return moduleRules > 0
                ? L("Rule mode: %@ module rule(s), then %@ of your own, then apps, countries and the default.", String(moduleRules), String(own))
                : L("Rule mode: %@ custom rule(s), then apps, countries and the default.", String(own))
        case .global:
            return L("Global mode: everything uses the proxy. Rules are off.")
        case .direct:
            return L("Direct mode: everything goes direct. Rules are off.")
        }
    }
}

enum HubColor {
    static func hex(_ rgb: UInt32) -> Color {
        Color(
            red: Double((rgb >> 16) & 0xFF) / 255,
            green: Double((rgb >> 8) & 0xFF) / 255,
            blue: Double(rgb & 0xFF) / 255
        )
    }

    /// `#RRGGBB` as scripts write it (`icon-color`); nil for anything else.
    static func parse(_ text: String?) -> Color? {
        guard let text, text.hasPrefix("#"), text.count == 7, let rgb = UInt32(text.dropFirst(), radix: 16) else { return nil }
        return hex(rgb)
    }
}

/// One gradient card: title, a control at the top right, a description, and its actions
/// at the bottom right.
private struct FeatureCard<Accessory: View, Actions: View>: View {
    let title: String
    let systemImage: String
    let detail: String
    let colors: [Color]
    @ViewBuilder var accessory: () -> Accessory
    @ViewBuilder var actions: () -> Actions

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: systemImage)
                    .font(.title3.weight(.semibold))
                    .accessibilityHidden(true)
                Text(title)
                    .font(.title2.weight(.bold))
                Spacer(minLength: 8)
                accessory()
            }
            Text(detail)
                .font(.subheadline)
                .fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                Spacer(minLength: 0)
                actions()
            }
            .padding(.top, 4)
        }
        .foregroundStyle(.white)
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            LinearGradient(colors: colors, startPoint: .topLeading, endPoint: .bottomTrailing),
            in: RoundedRectangle(cornerRadius: 20, style: .continuous)
        )
    }
}

private struct CardChip: View {
    let text: String

    var body: some View {
        Text(text)
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(.white)
            .padding(.horizontal, 14)
            .padding(.vertical, 9)
            .background(Color.black.opacity(0.24), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// Rule / Global / Direct, switchable from the card.
private struct ModeMenu: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        Menu {
            Picker(L("Mode"), selection: Binding(
                get: { model.routing.mode },
                set: { mode in
                    guard model.routing.mode != mode else { return }
                    model.routing.mode = mode
                    Task { await model.applyToRunningTunnel() }
                }
            )) {
                Text(L("Rule")).tag(RoutingGlobalMode.rule)
                Text(L("Global")).tag(RoutingGlobalMode.global)
                Text(L("Direct")).tag(RoutingGlobalMode.direct)
            }
        } label: {
            HStack(spacing: 4) {
                Text(label)
                Image(systemName: "chevron.up.chevron.down")
                    .font(.caption2.weight(.bold))
            }
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(.white)
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .background(Color.black.opacity(0.24), in: Capsule())
        }
        .accessibilityLabel(L("Mode"))
        .accessibilityValue(label)
    }

    private var label: String {
        switch model.routing.mode {
        case .rule: return L("Rule")
        case .global: return L("Global")
        case .direct: return L("Direct")
        }
    }
}

/// A module's `[Panel]` entry: what its script last reported, refreshed on tap.
private struct PanelCard: View {
    @EnvironmentObject private var model: AppModel
    let panel: ResolvedPanel
    let script: ResolvedScript?

    var body: some View {
        let run = panel.scriptKey.flatMap { model.scriptRuns[$0] }
        let fields = run?.panelFields ?? [:]
        let running = panel.scriptKey.map { model.runningScripts.contains($0) } ?? false
        Button {
            guard let script else { return }
            Task { await model.runScript(script, trigger: "button") }
        } label: {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: Self.symbol(fields["icon"]))
                    .font(.title3)
                    .foregroundStyle(HubColor.parse(fields["icon-color"]) ?? Palette.primary)
                    .frame(width: 28)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 4) {
                    Text(fields["title"] ?? panel.title)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(Palette.onSurface)
                    Text(fields["content"] ?? panel.content)
                        .font(.footnote.monospaced())
                        .foregroundStyle(Palette.onSurfaceVariant)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    if let error = run?.error {
                        Text(ScriptText.error(error))
                            .font(.caption)
                            .foregroundStyle(Palette.bad)
                    }
                    Text(footer(run))
                        .font(.caption)
                        .foregroundStyle(Palette.onSurfaceVariant)
                }
                if running {
                    ProgressView()
                } else if script != nil {
                    Image(systemName: "arrow.clockwise")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(Palette.onSurfaceVariant)
                        .accessibilityLabel(L("Refresh"))
                }
            }
            .multilineTextAlignment(.leading)
            .padding(14)
            .card()
        }
        .buttonStyle(.plain)
        .disabled(script == nil || running)
    }

    private func footer(_ run: ScriptRunRecord?) -> String {
        if script == nil {
            return L("%@ · its script is off or was not downloaded", panel.moduleName)
        }
        guard let run else { return L("%@ · not run yet", panel.moduleName) }
        return L("%@ · updated %@", panel.moduleName, ScriptText.time(run.finishedAt))
    }

    /// Scripts name SF Symbols; an unknown name would draw nothing.
    private static func symbol(_ name: String?) -> String {
        guard let name, UIImage(systemName: name) != nil else { return "rectangle.stack" }
        return name
    }
}

/// Wording shared by the panel cards and the script list.
enum ScriptText {
    static func time(_ date: Date) -> String {
        date.formatted(date: Calendar.current.isDateInToday(date) ? .omitted : .abbreviated, time: .shortened)
    }

    static func error(_ code: String) -> String {
        switch code {
        case "timeout": return L("Timed out before the script finished")
        case "memory_low": return L("Skipped: the VPN extension was short of memory")
        case "script_source_missing": return L("The script file is missing. Update the module")
        case "script_too_large": return L("The script is too large to run")
        case "already_running": return L("Already running")
        default: return code
        }
    }

    static func trigger(_ script: ResolvedScript) -> String {
        switch script.kind {
        case .cron: return L("Schedule %@", script.cronExpression ?? "")
        case .event: return L("When the network changes")
        case .generic: return L("From a panel or on demand")
        }
    }
}
