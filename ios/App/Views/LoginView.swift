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
        NavigationStack {
            Form {
                Section {
                    VStack(spacing: 8) {
                        Image(systemName: "bolt.horizontal.circle.fill")
                            .font(.system(size: 52))
                            .foregroundStyle(Color.accentColor)
                        Text("LeiKwan")
                            .font(.largeTitle.weight(.semibold))
                        Text("使用面板里的客户端 ID 和 Agent Token 登录")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 8)
                    .listRowBackground(Color.clear)
                }

                Section("账号") {
                    TextField("客户端 ID", text: $clientID)
                        .keyboardType(.numberPad)
                        .focused($focused, equals: .clientID)
                    SecureField("Agent Token", text: $token)
                        .textContentType(.password)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                        .focused($focused, equals: .token)
                    Toggle("保存信息", isOn: $remember)
                }

                Section {
                    Picker("面板线路", selection: $endpoint) {
                        Text("Cloudflare（默认）").tag(PanelEndpoint.cloudflare)
                        Text("阿里云 CDN").tag(PanelEndpoint.aliyunCDN)
                    }
                } footer: {
                    Text("Cloudflare 无法连接时可切换到阿里云 CDN；请求失败时也会自动尝试另一条线路。")
                }

                if let error {
                    Section {
                        Label(error, systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                            .font(.subheadline)
                    }
                }

                Section {
                    Button {
                        Task { await signIn() }
                    } label: {
                        HStack {
                            Spacer()
                            if working {
                                ProgressView()
                            } else {
                                Text("登录").fontWeight(.semibold)
                            }
                            Spacer()
                        }
                    }
                    .disabled(working || clientID.isEmpty || token.isEmpty)
                }
            }
            .navigationBarTitleDisplayMode(.inline)
        }
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
