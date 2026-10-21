import LeikwanIOSCore
import SwiftUI

struct NodeEditor: View {
    @Environment(\.dismiss) private var dismiss
    @State private var node: ProxyNode
    @State private var portText: String
    let onSave: (ProxyNode) -> Void

    private static let ssMethods = [
        "aes-128-gcm", "aes-256-gcm", "chacha20-ietf-poly1305", "xchacha20-ietf-poly1305",
        "2022-blake3-aes-128-gcm", "2022-blake3-aes-256-gcm", "2022-blake3-chacha20-poly1305", "none",
    ]

    init(node: ProxyNode, onSave: @escaping (ProxyNode) -> Void) {
        _node = State(initialValue: node)
        _portText = State(initialValue: node.port > 0 ? String(node.port) : "")
        self.onSave = onSave
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Basics") {
                    TextField("Name (optional)", text: $node.name)
                    TextField("Server address", text: $node.server)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    TextField("Port", text: $portText)
                        .keyboardType(.numberPad)
                }
                switch node.settings {
                case let .shadowsocks(settings):
                    Section("Shadowsocks") {
                        Picker("Cipher", selection: ssBinding(settings, \.method)) {
                            ForEach(Self.ssMethods, id: \.self) { Text($0).tag($0) }
                        }
                        SecureField("Password", text: ssBinding(settings, \.password))
                        TextField("Plugin (obfs-local / v2ray-plugin, optional)", text: ssOptionalBinding(settings, \.plugin))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        TextField("Plugin options (optional)", text: ssOptionalBinding(settings, \.pluginOpts))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                    }
                case let .socks(settings):
                    Section("SOCKS5") {
                        TextField("User name (optional)", text: socksBinding(settings, \.username))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        SecureField("Password (optional)", text: socksBinding(settings, \.password))
                    }
                }
            }
            .navigationTitle(node.id == 0 ? "Add node" : "Edit node")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        var saved = node
                        saved.port = Int(portText) ?? 0
                        saved.server = saved.server.trimmingCharacters(in: .whitespaces)
                        onSave(saved)
                        dismiss()
                    }
                    .disabled(!isValid)
                }
            }
        }
    }

    private var isValid: Bool {
        guard let port = Int(portText), (1 ... 65535).contains(port), !node.server.trimmingCharacters(in: .whitespaces).isEmpty else {
            return false
        }
        if case let .shadowsocks(settings) = node.settings {
            return settings.method == "none" || !settings.password.isEmpty
        }
        return true
    }

    private func ssBinding(_ settings: ShadowsocksSettings, _ keyPath: WritableKeyPath<ShadowsocksSettings, String>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value
                node.settings = .shadowsocks(updated)
            }
        )
    }

    private func ssOptionalBinding(_ settings: ShadowsocksSettings, _ keyPath: WritableKeyPath<ShadowsocksSettings, String?>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] ?? "" },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value.isEmpty ? nil : value
                node.settings = .shadowsocks(updated)
            }
        )
    }

    private func socksBinding(_ settings: SocksSettings, _ keyPath: WritableKeyPath<SocksSettings, String?>) -> Binding<String> {
        Binding(
            get: { settings[keyPath: keyPath] ?? "" },
            set: { value in
                var updated = settings
                updated[keyPath: keyPath] = value.isEmpty ? nil : value
                node.settings = .socks(updated)
            }
        )
    }
}

