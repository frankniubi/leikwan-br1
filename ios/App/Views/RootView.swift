import SwiftUI

struct RootView: View {
    @EnvironmentObject private var model: AppModel
    /// Guideline 5.4 wants the data declaration on screen before the service is used; the
    /// cover sits above the sign-in screen until the user acknowledges it.
    @AppStorage(PrivacyDisclosureView.acknowledgmentKey) private var privacyAcknowledged = false

    var body: some View {
        Group {
            // The panel requires a newer build: nothing else is reachable (the tunnel keeps running).
            if model.updateRequired {
                UpdateRequiredView()
            } else if model.profile == nil {
                LoginView()
            } else {
                MainShell()
            }
        }
        .overlay(alignment: .top) {
            if let notice = model.notice {
                NoticeBanner(text: notice) { model.notice = nil }
                    .padding(.horizontal, Metrics.pagePadding)
                    .padding(.top, 4)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.2), value: model.notice)
        .fullScreenCover(isPresented: Binding(get: { !privacyAcknowledged }, set: { _ in })) {
            PrivacyDisclosureView { privacyAcknowledged = true }
        }
    }
}

/// Bottom navigation on iPhone, sidebar on iPad (FlClash: bar on mobile,
/// rail on wide screens).
private struct MainShell: View {
    @EnvironmentObject private var navigator: Navigator
    @Environment(\.horizontalSizeClass) private var sizeClass

    var body: some View {
        if sizeClass == .regular {
            NavigationSplitView {
                List(AppSection.allCases, selection: sectionSelection) { section in
                    Label(section.title, systemImage: section.systemImage)
                        .tag(section)
                }
                .navigationTitle("Bridge")
                .scrollContentBackground(.hidden)
                .background(Palette.surfaceContainerLow)
            } detail: {
                SectionScreen(section: navigator.section)
            }
        } else {
            TabView(selection: $navigator.section) {
                ForEach(AppSection.allCases) { section in
                    SectionScreen(section: section)
                        .tabItem { Label(section.title, systemImage: section.systemImage) }
                        .tag(section)
                }
            }
        }
    }

    private var sectionSelection: Binding<AppSection?> {
        Binding(
            get: { navigator.section },
            set: { if let value = $0 { navigator.section = value } }
        )
    }
}

private struct SectionScreen: View {
    let section: AppSection

    var body: some View {
        switch section {
        case .dashboard: DashboardView()
        case .proxies: ProxiesView()
        case .rules: NavigationStack { RulesView() }
        case .tools: ToolsView()
        }
    }
}

/// FlClash-style floating start button: a round play button when stopped,
/// an extended pill with the running time when connected.
struct StartButton: View {
    @EnvironmentObject private var model: AppModel
    @EnvironmentObject private var tunnel: TunnelController

    var body: some View {
        Button {
            Task { await model.toggleConnection() }
        } label: {
            HStack(spacing: 10) {
                if tunnel.isBusy {
                    ProgressView()
                        .tint(Palette.onPrimaryContainer)
                } else {
                    Image(systemName: tunnel.isConnected ? "pause.fill" : "play.fill")
                        .font(.title3.weight(.bold))
                }
                if tunnel.isConnected {
                    RunTimeText(since: tunnel.connectedSince)
                        .font(.headline.monospacedDigit())
                }
            }
            .foregroundStyle(Palette.onPrimaryContainer)
            .padding(.horizontal, tunnel.isConnected ? 22 : 0)
            .frame(minWidth: 60, minHeight: 60)
            .background(Palette.primaryContainer, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.18), radius: 8, y: 4)
        }
        .buttonStyle(.plain)
        .animation(.spring(response: 0.35, dampingFraction: 0.8), value: tunnel.isConnected)
        .accessibilityLabel(tunnel.isConnected ? L("Disconnect") : L("Connect"))
    }
}

struct RunTimeText: View {
    let since: Date?

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { context in
            Text(Self.format(since.map { context.date.timeIntervalSince($0) } ?? 0))
        }
    }

    static func format(_ interval: TimeInterval) -> String {
        let seconds = max(0, Int(interval))
        return String(format: "%02d:%02d:%02d", seconds / 3600, (seconds / 60) % 60, seconds % 60)
    }
}

struct NoticeBanner: View {
    let text: String
    let dismiss: () -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "info.circle.fill")
            Text(text)
                .font(.subheadline)
                .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: dismiss) {
                Image(systemName: "xmark")
                    .font(.caption.weight(.bold))
            }
            .accessibilityLabel(L("Close"))
        }
        .foregroundStyle(Palette.onSecondaryContainer)
        .padding(14)
        .background(Palette.secondaryContainer, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .shadow(color: .black.opacity(0.12), radius: 8, y: 3)
        .task {
            try? await Task.sleep(nanoseconds: 5_000_000_000)
            dismiss()
        }
    }
}

enum ByteFormat {
    static func rate(_ bytesPerSecond: Int64) -> String {
        ByteCountFormatter.string(fromByteCount: bytesPerSecond, countStyle: .binary) + "/s"
    }

    static func total(_ bytes: Int64) -> String {
        ByteCountFormatter.string(fromByteCount: bytes, countStyle: .binary)
    }
}
