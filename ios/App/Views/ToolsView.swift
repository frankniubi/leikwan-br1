import LeikwanIOSCore
import SwiftUI

/// Settings and diagnostics (FlClash tools page).
struct ToolsView: View {
    @EnvironmentObject private var model: AppModel
    @State private var confirmingSignOut = false
    @State private var showingPrivacy = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    NavigationLink {
                        LogsView()
                    } label: {
                        ToolRow(title: L("Tunnel logs"), detail: L("Runtime and sing-box logs"), systemImage: "doc.text.magnifyingglass")
                    }
                } header: {
                    Text(L("Tools"))
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    Toggle(isOn: $model.routing.enableIPv6) {
                        ToolRow(title: "IPv6", detail: L("Use IPv6 inside the tunnel"), systemImage: "6.circle")
                    }
                    HStack {
                        ToolRow(title: L("Remote DNS"), detail: nil, systemImage: "server.rack")
                        TextField("1.1.1.1", text: $model.routing.dnsServer)
                            .multilineTextAlignment(.trailing)
                            .keyboardType(.numbersAndPunctuation)
                            .autocorrectionDisabled()
                            .textInputAutocapitalization(.never)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                } header: {
                    Text(L("Network"))
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    InfoRow(title: L("Profile"), value: model.profile?.displayName ?? "—")
                    InfoRow(title: L("Server"), value: model.profile?.server.host ?? "—", monospaced: true)
                    InfoRow(title: L("Client ID"), value: model.profile.map { String($0.clientId) } ?? "—")
                    InfoRow(title: L("Device ID"), value: deviceID, monospaced: true)
                    if model.isDemo {
                        InfoRow(title: L("Mode"), value: L("Demo (no server connection)"))
                    }
                    Button {
                        Task {
                            await model.refreshCatalog()
                            model.notice = model.catalogError ?? L("Forward list refreshed")
                        }
                    } label: {
                        ToolRow(title: L("Refresh forward list"), detail: nil, systemImage: "arrow.clockwise")
                    }
                    .foregroundStyle(Palette.onSurface)
                    if let catalog = model.catalog, !catalog.hidden.isEmpty {
                        NavigationLink {
                            HiddenForwardsView(hidden: catalog.hidden)
                        } label: {
                            ToolRow(title: L("Forwards not supported on iOS"), detail: "\(catalog.hidden.count)", systemImage: "eye.slash")
                        }
                    }
                } header: {
                    Text(L("Account"))
                }
                .listRowBackground(Palette.surfaceContainer)

                Section {
                    Button {
                        showingPrivacy = true
                    } label: {
                        ToolRow(title: L("What this app sends"), detail: nil, systemImage: "shield.lefthalf.filled")
                    }
                    .foregroundStyle(Palette.onSurface)
                    Link(destination: PrivacyDisclosureView.policyURL) {
                        ToolRow(title: L("Privacy Policy"), detail: nil, systemImage: "hand.raised")
                    }
                    InfoRow(title: L("Version"), value: model.appVersion)
                    Button(role: .destructive) {
                        confirmingSignOut = true
                    } label: {
                        ToolRow(title: L("Sign out"), detail: L("Back to the profile list to switch profiles"), systemImage: "rectangle.portrait.and.arrow.right", tint: Palette.bad)
                    }
                } header: {
                    Text(L("About"))
                }
                .listRowBackground(Palette.surfaceContainer)
            }
            .scrollContentBackground(.hidden)
            .pageBackground()
            .navigationTitle(L("Tools"))
            .confirmationDialog(L("Signing out disconnects the current connection."), isPresented: $confirmingSignOut, titleVisibility: .visible) {
                Button(L("Sign out"), role: .destructive) { model.signOut() }
            }
            .sheet(isPresented: $showingPrivacy) {
                PrivacyDisclosureView()
            }
        }
    }

    private var deviceID: String {
        guard let profile = model.profile else { return "—" }
        return IOSDeviceIdentity.deviceId(clientId: profile.clientId, installId: Keychain.installID())
    }
}

private struct ToolRow: View {
    let title: String
    let detail: String?
    let systemImage: String
    var tint: Color = Palette.onPrimaryContainer

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: systemImage)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(tint)
                .frame(width: 32, height: 32)
                .background(Palette.primaryContainer.opacity(0.7), in: RoundedRectangle(cornerRadius: 9, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .foregroundStyle(tint == Palette.onPrimaryContainer ? Palette.onSurface : tint)
                if let detail {
                    Text(detail)
                        .font(.caption)
                        .foregroundStyle(Palette.onSurfaceVariant)
                }
            }
        }
        .padding(.vertical, 2)
    }
}

private struct InfoRow: View {
    let title: String
    let value: String
    var monospaced = false

    var body: some View {
        HStack {
            Text(title)
                .foregroundStyle(Palette.onSurface)
            Spacer()
            Text(value)
                .font(monospaced ? .caption.monospaced() : .body)
                .foregroundStyle(Palette.onSurfaceVariant)
                .lineLimit(1)
                .minimumScaleFactor(0.6)
                .textSelection(.enabled)
        }
    }
}

private struct HiddenForwardsView: View {
    let hidden: [IOSHiddenForward]

    var body: some View {
        List {
            Section {
                ForEach(Array(hidden.enumerated()), id: \.offset) { _, item in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(item.serviceName.isEmpty ? L("Forward #%@", String(describing: item.forwardId.map(String.init) ?? "?")) : item.serviceName)
                            .foregroundStyle(Palette.onSurface)
                        Text(item.reason.message)
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                    .listRowBackground(Palette.surfaceContainer)
                }
            } footer: {
                Text(L("iOS supports Ultra forwards and Bond forwards with plain_tcp_mux ingress."))
            }
        }
        .scrollContentBackground(.hidden)
        .pageBackground()
        .navigationTitle(L("Unsupported forwards"))
        .navigationBarTitleDisplayMode(.inline)
    }
}
