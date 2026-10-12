import LeikwanIOSCore
import SwiftUI

/// Client ID + Agent Token sign-in, the same credentials the Windows client uses.
struct LoginView: View {
    @EnvironmentObject private var model: AppModel
    @State private var clientID = ""
    @State private var token = ""
    @State private var endpoint = PanelEndpoint.defaultEndpoint
    @State private var remember = true
    @State private var working = false
    @State private var error: String?
    @FocusState private var focused: Field?

    private enum Field {
        case clientID
        case token
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                VStack(spacing: 10) {
                    Image(systemName: "bolt.horizontal.fill")
                        .font(.system(size: 34, weight: .semibold))
                        .foregroundStyle(Palette.onPrimaryContainer)
                        .frame(width: 76, height: 76)
                        .background(Palette.primaryContainer, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                    Text("LeiKwan")
                        .font(.largeTitle.weight(.bold))
                        .foregroundStyle(Palette.onSurface)
                    Text("使用面板里的客户端 ID 和 Agent Token 登录")
                        .font(.subheadline)
                        .foregroundStyle(Palette.onSurfaceVariant)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, 48)

                VStack(spacing: 12) {
                    InputField(title: "客户端 ID", systemImage: "person.text.rectangle") {
                        TextField("例如 1024", text: $clientID)
                            .keyboardType(.numberPad)
                            .focused($focused, equals: .clientID)
                    }
                    InputField(title: "Agent Token", systemImage: "key.horizontal") {
                        SecureField("面板 → 客户端 → Agent Token", text: $token)
                            .textContentType(.password)
                            .autocorrectionDisabled()
                            .textInputAutocapitalization(.never)
                            .focused($focused, equals: .token)
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        Text("面板线路")
                            .font(.caption.weight(.medium))
                            .foregroundStyle(Palette.onSurfaceVariant)
                        Picker("面板线路", selection: $endpoint) {
                            Text("Cloudflare").tag(PanelEndpoint.cloudflare)
                            Text("阿里云 CDN").tag(PanelEndpoint.aliyunCDN)
                        }
                        .pickerStyle(.segmented)
                        Text("一条线路连不上时会自动尝试另一条。")
                            .font(.caption2)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                    .padding(14)
                    .card()

                    Toggle("保存信息", isOn: $remember)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .card()
                }

                if let error {
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill")
                        Text(error)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .font(.subheadline)
                    .foregroundStyle(Palette.onErrorContainer)
                    .padding(14)
                    .card(fill: Palette.errorContainer)
                }

                Button {
                    Task { await signIn() }
                } label: {
                    HStack {
                        if working {
                            ProgressView().tint(Palette.onPrimary)
                        } else {
                            Text("登录").font(.headline)
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .foregroundStyle(Palette.onPrimary)
                    .background(Palette.primary, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(working || clientID.isEmpty || token.isEmpty)
                .opacity(clientID.isEmpty || token.isEmpty ? 0.5 : 1)
            }
            .padding(.horizontal, 20)
            .frame(maxWidth: 480)
            .frame(maxWidth: .infinity)
        }
        .scrollDismissesKeyboard(.interactively)
        .pageBackground()
    }

    private func signIn() async {
        focused = nil
        working = true
        error = nil
        defer { working = false }
        guard let id = Int64(clientID.trimmingCharacters(in: .whitespaces)) else {
            error = "客户端 ID 必须是数字"
            return
        }
        do {
            try await model.signIn(clientID: id, token: token, endpoint: endpoint, remember: remember)
        } catch {
            self.error = AppModel.describe(error)
        }
    }
}

private struct InputField<Content: View>: View {
    let title: String
    let systemImage: String
    @ViewBuilder let content: Content

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: systemImage)
                .foregroundStyle(Palette.onSurfaceVariant)
                .frame(width: 22)
            VStack(alignment: .leading, spacing: 3) {
                Text(title)
                    .font(.caption.weight(.medium))
                    .foregroundStyle(Palette.onSurfaceVariant)
                content
                    .font(.body)
            }
        }
        .padding(14)
        .card()
    }
}
