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
                Section(L("Basics")) {
                    TextField(L("Name (optional)"), text: $node.name)
                    TextField(L("Server address"), text: $node.server)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    TextField(L("Port"), text: $portText)
                        .keyboardType(.numberPad)
                }
                switch node.settings {
                case let .shadowsocks(settings):
                    Section("Shadowsocks") {
                        Picker(L("Cipher"), selection: ssBinding(settings, \.method)) {
                            ForEach(Self.ssMethods, id: \.self) { Text($0).tag($0) }
                        }
                        SecureField(L("Password"), text: ssBinding(settings, \.password))
                        TextField(L("Plugin (obfs-local / v2ray-plugin, optional)"), text: ssOptionalBinding(settings, \.plugin))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        TextField(L("Plugin options (optional)"), text: ssOptionalBinding(settings, \.pluginOpts))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                    }
                case let .socks(settings):
                    Section("SOCKS5") {
                        TextField(L("User name (optional)"), text: socksBinding(settings, \.username))
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        SecureField(L("Password (optional)"), text: socksBinding(settings, \.password))
                    }
                }
            }
            .navigationTitle(node.id == 0 ? L("Add node") : L("Edit node"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(L("Cancel")) { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(L("Save")) {
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

