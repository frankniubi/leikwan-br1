import SwiftUI

struct RootView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        Group {
            if model.login == nil {
                LoginView()
            } else {
                TabView {
                    HomeView()
                        .tabItem { Label("连接", systemImage: "bolt.horizontal.circle") }
                    NodesView()
                        .tabItem { Label("节点", systemImage: "server.rack") }
                    RulesView()
                        .tabItem { Label("分流", systemImage: "arrow.triangle.branch") }
                    SettingsView()
                        .tabItem { Label("设置", systemImage: "gearshape") }
                }
            }
        }
        .overlay(alignment: .top) {
            if let notice = model.notice {
                NoticeBanner(text: notice) { model.notice = nil }
                    .padding(.horizontal, 16)
                    .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.2), value: model.notice)
    }
}

struct NoticeBanner: View {
    let text: String
    let dismiss: () -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "info.circle.fill")
                .foregroundStyle(.white)
            Text(text)
                .font(.subheadline)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: dismiss) {
                Image(systemName: "xmark")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(.white.opacity(0.8))
            }
            .accessibilityLabel("关闭")
        }
        .padding(12)
        .background(Color.accentColor.gradient, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .shadow(radius: 6, y: 2)
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
