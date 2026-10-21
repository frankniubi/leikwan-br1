import LeikwanIOSCore
import SwiftUI

/// Sign-in: the saved profiles (server address + Client ID + Agent Token) and an editor for a
/// new or changed one. The server address is whatever the user types; nothing is built in.
struct LoginView: View {
    @EnvironmentObject private var model: AppModel
    @State private var editing: ProfileDraft?
    @State private var working: UUID?
    @State private var error: String?
    @State private var deleting: PanelProfile?

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                header

                if let error {
                    ErrorCard(text: error)
                }

                if model.profiles.profiles.isEmpty {
                    ProfileForm(draft: ProfileDraft(), isNew: true, onDone: nil)
                } else {
                    savedProfiles
                    Button {
                        error = nil
                        editing = ProfileDraft()
                    } label: {
                        Label("New profile", systemImage: "plus")
                            .font(.headline)
                            .frame(maxWidth: .infinity, minHeight: 48)
                            .foregroundStyle(Palette.onPrimaryContainer)
                            .background(Palette.primaryContainer, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 32)
            .frame(maxWidth: 480)
            .frame(maxWidth: .infinity)
        }
        .scrollDismissesKeyboard(.interactively)
        .pageBackground()
        .sheet(item: $editing) { draft in
            NavigationStack {
                ScrollView {
                    ProfileForm(draft: draft, isNew: draft.profileID == nil) { editing = nil }
                        .padding(20)
                        .frame(maxWidth: 480)
                        .frame(maxWidth: .infinity)
                }
                .scrollDismissesKeyboard(.interactively)
                .pageBackground()
                .navigationTitle(draft.profileID == nil ? "New profile" : "Edit profile")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { editing = nil }
                    }
                }
            }
        }
        .confirmationDialog(
            "Deleting the profile also deletes its Agent Token from this device.",
            isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }),
            titleVisibility: .visible,
            presenting: deleting
        ) { profile in
            Button("Delete \u{201C}\(profile.displayName)\u{201D}", role: .destructive) {
                model.deleteProfile(profile.id)
            }
        }
    }

    private var header: some View {
        VStack(spacing: 10) {
            Image("BrandMark")
                .resizable()
                .interpolation(.high)
                .frame(width: 84, height: 84)
                .clipShape(RoundedRectangle(cornerRadius: 19, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: 19, style: .continuous)
                        .strokeBorder(Palette.outlineVariant.opacity(0.6), lineWidth: 1)
                )
                .shadow(color: .black.opacity(0.12), radius: 10, y: 4)
                .accessibilityHidden(true)
            Text("Bridge")
                .font(.largeTitle.weight(.bold))
                .foregroundStyle(Palette.onSurface)
            Text("Sign in with your server address, Client ID and Agent Token")
                .font(.subheadline)
                .foregroundStyle(Palette.onSurfaceVariant)
                .multilineTextAlignment(.center)
        }
        .padding(.top, 48)
    }

    private var savedProfiles: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Saved profiles")
                .font(.caption.weight(.medium))
                .foregroundStyle(Palette.onSurfaceVariant)
                .padding(.horizontal, 4)
            ForEach(model.profiles.profiles) { profile in
                ProfileCard(
                    profile: profile,
                    hasToken: model.hasSavedToken(profile),
                    working: working == profile.id,
                    onSignIn: { Task { await signIn(profile) } },
                    onEdit: {
                        error = nil
                        editing = ProfileDraft(profile)
                    },
                    onDelete: { deleting = profile }
                )
                .disabled(working != nil)
            }
        }
    }

    private func signIn(_ profile: PanelProfile) async {
        guard model.hasSavedToken(profile) else {
            editing = ProfileDraft(profile)
            return
        }
        working = profile.id
        error = nil
        defer { working = nil }
        do {
            try await model.signIn(profileID: profile.id)
        } catch {
            self.error = AppModel.describe(error)
        }
    }
}

/// Editable fields of a profile; `profileID` nil for a new one.
struct ProfileDraft: Identifiable {
    let id = UUID()
    var profileID: UUID?
    var name = ""
    var server = ""
    var backupServer = ""
    var clientID = ""
    var token = ""
    var rememberToken = true

    init() {}

    init(_ profile: PanelProfile) {
        profileID = profile.id
        name = profile.name
        server = profile.server.urlString
        backupServer = profile.backupServer?.urlString ?? ""
        clientID = String(profile.clientId)
        rememberToken = profile.rememberToken
    }

    /// The profile to sign in with, or the reason the fields are not usable.
    func validated() -> Result<PanelProfile, AppError> {
        let serverEndpoint: PanelEndpoint
        switch PanelEndpoint.parse(server) {
        case .success(let endpoint): serverEndpoint = endpoint
        case .failure(let reason): return .failure(AppError("Server address: " + Self.describe(reason)))
        }
        var backup: PanelEndpoint?
        if !backupServer.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            switch PanelEndpoint.parse(backupServer) {
            case .success(let endpoint): backup = endpoint
            case .failure(let reason): return .failure(AppError("Backup address: " + Self.describe(reason)))
            }
        }
        guard let clientId = Int64(clientID.trimmingCharacters(in: .whitespaces)), clientId > 0 else {
            return .failure(AppError("Client ID must be a positive number"))
        }
        return .success(PanelProfile(
            id: profileID ?? UUID(),
            name: name.trimmingCharacters(in: .whitespacesAndNewlines),
            server: serverEndpoint,
            backupServer: backup,
            clientId: clientId,
            rememberToken: rememberToken
        ))
    }

    static func describe(_ error: PanelEndpoint.ParseError) -> String {
        switch error {
        case .empty: return "required"
        case .insecure: return "must use https"
        case .hasCredentials: return "must not contain a user name or password"
        case .invalid: return "not a valid address"
        }
    }
}

/// New / edit profile form; signs in on submit.
private struct ProfileForm: View {
    @EnvironmentObject private var model: AppModel
    @State private var draft: ProfileDraft
    let isNew: Bool
    /// Called after a successful sign-in (closes the sheet); nil when shown inline.
    let onDone: (() -> Void)?
    @State private var working = false
    @State private var error: String?
    @FocusState private var focused: Field?

    private enum Field {
        case name, server, backup, clientID, token
    }

    init(draft: ProfileDraft, isNew: Bool, onDone: (() -> Void)?) {
        _draft = State(initialValue: draft)
        self.isNew = isNew
        self.onDone = onDone
    }

    private var hasSavedToken: Bool {
        guard let id = draft.profileID, let profile = model.profiles.profile(id: id) else { return false }
        return model.hasSavedToken(profile)
    }

    private var canSubmit: Bool {
        !draft.server.trimmingCharacters(in: .whitespaces).isEmpty
            && !draft.clientID.isEmpty
            && (!draft.token.isEmpty || hasSavedToken)
    }

    var body: some View {
        VStack(spacing: 12) {
            InputField(title: "Profile name (optional)", systemImage: "tag") {
                TextField("e.g. Home / Office", text: $draft.name)
                    .focused($focused, equals: .name)
            }
            InputField(title: "Server address", systemImage: "server.rack") {
                TextField("https://", text: $draft.server)
                    .keyboardType(.URL)
                    .textContentType(.URL)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .focused($focused, equals: .server)
            }
            InputField(title: "Client ID", systemImage: "person.text.rectangle") {
                TextField("e.g. 1024", text: $draft.clientID)
                    .keyboardType(.numberPad)
                    .focused($focused, equals: .clientID)
            }
            InputField(title: "Agent Token", systemImage: "key.horizontal") {
                SecureField(hasSavedToken ? "Saved; leave blank to keep it" : "Agent Token", text: $draft.token)
                    .textContentType(.password)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .focused($focused, equals: .token)
            }
            InputField(title: "Backup server address (optional)", systemImage: "arrow.triangle.branch") {
                TextField("Tried when the main address is unreachable", text: $draft.backupServer)
                    .keyboardType(.URL)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .focused($focused, equals: .backup)
            }

            Toggle("Save Agent Token on this device", isOn: $draft.rememberToken)
                .padding(.horizontal, 14)
                .padding(.vertical, 12)
                .card()
            Text("The server address, Client ID and profile name are always saved on this device; the Agent Token is kept in the system Keychain. With the switch off, the token is deleted when you sign out.")
                .font(.caption2)
                .foregroundStyle(Palette.onSurfaceVariant)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 4)

            if let error {
                ErrorCard(text: error)
            }

            Button {
                Task { await submit() }
            } label: {
                HStack {
                    if working {
                        ProgressView().tint(Palette.onPrimary)
                    } else {
                        Text("Save and sign in").font(.headline)
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 52)
                .foregroundStyle(Palette.onPrimary)
                .background(Palette.primary, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(working || !canSubmit)
            .opacity(canSubmit ? 1 : 0.5)
        }
    }

    private func submit() async {
        focused = nil
        error = nil
        let profile: PanelProfile
        switch draft.validated() {
        case .success(let value): profile = value
        case .failure(let reason):
            error = reason.message
            return
        }
        working = true
        defer { working = false }
        do {
            try await model.signIn(profile, token: draft.token)
            onDone?()
        } catch {
            self.error = AppModel.describe(error)
        }
    }
}

private struct ProfileCard: View {
    let profile: PanelProfile
    let hasToken: Bool
    let working: Bool
    let onSignIn: () -> Void
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            Button(action: onSignIn) {
                HStack(spacing: 12) {
                    Image(systemName: "person.crop.circle")
                        .font(.title2)
                        .foregroundStyle(Palette.onPrimaryContainer)
                    VStack(alignment: .leading, spacing: 3) {
                        Text(profile.displayName)
                            .font(.body.weight(.semibold))
                            .foregroundStyle(Palette.onSurface)
                            .lineLimit(1)
                        Text("\(profile.server.host) · Client \(profile.clientId)")
                            .font(.caption)
                            .foregroundStyle(Palette.onSurfaceVariant)
                            .lineLimit(1)
                        if !hasToken {
                            Text("No token saved; tap to enter it")
                                .font(.caption2)
                                .foregroundStyle(Palette.fair)
                        }
                    }
                    Spacer(minLength: 0)
                    if working {
                        ProgressView()
                    } else {
                        Image(systemName: "chevron.right")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(Palette.onSurfaceVariant)
                    }
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)

            Menu {
                Button(action: onEdit) { Label("Edit", systemImage: "pencil") }
                Button(role: .destructive, action: onDelete) { Label("Delete", systemImage: "trash") }
            } label: {
                Image(systemName: "ellipsis.circle")
                    .font(.title3)
                    .foregroundStyle(Palette.onSurfaceVariant)
                    .frame(width: 36, height: 36)
            }
            .accessibilityLabel("More")
        }
        .padding(14)
        .card()
    }
}

private struct ErrorCard: View {
    let text: String

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "exclamationmark.triangle.fill")
            Text(text)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .font(.subheadline)
        .foregroundStyle(Palette.onErrorContainer)
        .padding(14)
        .card(fill: Palette.errorContainer)
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
