import SwiftUI

/// What the app sends and why. App Store Guideline 5.4 requires a VPN app to make this
/// declaration on an app screen before the service is used, so `RootView` shows it before the
/// sign-in screen until the user acknowledges it; Tools keeps a read-only copy.
struct PrivacyDisclosureView: View {
    /// Set once the user has acknowledged the disclosure. Read by `RootView` through @AppStorage.
    static let acknowledgmentKey = "privacy_disclosure_v1"
    static let policyURL = URL(string: "https://v3.leikwanhost.com/privacy.html")!

    /// nil shows the read-only copy (no acknowledgement button).
    var onAccept: (() -> Void)? = nil

    @Environment(\.dismiss) private var dismiss

    init(onAccept: (() -> Void)? = nil) {
        self.onAccept = onAccept
    }

    private struct Point {
        let title: String
        let detail: String
    }

    private var points: [Point] {
        [
            Point(
                title: L("Server address, Client ID and Agent Token"),
                detail: L("Used to sign in and to authenticate every request to the server you entered.")
            ),
            Point(
                title: L("A device identifier"),
                detail: L("A stable value derived from this installation. The server uses it to recognize this device and apply its device limit.")
            ),
            Point(
                title: L("Device model and iOS version"),
                detail: L("Lets the server pick compatible settings and show this device in its own device list.")
            ),
            Point(
                title: L("Traffic volume"),
                detail: L("Bytes uploaded and downloaded per forward, so the server can account for usage and limits.")
            ),
            Point(
                title: L("Connection details"),
                detail: L("The line selected, timestamps and error codes, used to connect and to troubleshoot.")
            ),
            Point(
                title: L("Tunnel logs"),
                detail: L("Kept on this device. They leave it only if you copy or share them.")
            ),
        ]
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Text(L("What this app sends"))
                        .font(.title.weight(.bold))
                        .foregroundStyle(Palette.onSurface)

                    Text(L("Bridge connects this device to a server you choose. Before the first connection, this is exactly what leaves this device and why."))
                        .font(.subheadline)
                        .foregroundStyle(Palette.onSurfaceVariant)

                    VStack(alignment: .leading, spacing: 12) {
                        ForEach(points, id: \.title) { point in
                            VStack(alignment: .leading, spacing: 4) {
                                Text(point.title)
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(Palette.onSurface)
                                Text(point.detail)
                                    .font(.footnote)
                                    .foregroundStyle(Palette.onSurfaceVariant)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(12)
                            .card()
                        }
                    }

                    section(
                        title: L("Never collected"),
                        text: L("Browsing history, DNS queries, message content, contacts, location, photos and the advertising identifier (IDFA) are never collected. The app contains no analytics, advertising or crash-reporting SDK.")
                    )

                    section(
                        title: L("Who receives it"),
                        text: L("Only the server address saved in your profile. The two preset addresses belong to the developer; if you enter your own server, nothing is sent to the developer's servers. This data is not sold, used, or disclosed to third parties for any purpose.")
                    )

                    Link(destination: Self.policyURL) {
                        HStack(spacing: 8) {
                            Image(systemName: "hand.raised")
                            Text(L("Privacy Policy"))
                            Spacer()
                            Image(systemName: "arrow.up.right")
                                .font(.caption)
                                .foregroundStyle(Palette.onSurfaceVariant)
                        }
                        .font(.subheadline.weight(.medium))
                        .foregroundStyle(Palette.primary)
                        .padding(14)
                        .frame(maxWidth: .infinity)
                        .card()
                    }
                }
                .padding(20)
                .frame(maxWidth: 560)
                .frame(maxWidth: .infinity)
            }
            .pageBackground()
            .navigationTitle(L("Privacy"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if onAccept == nil {
                    ToolbarItem(placement: .cancellationAction) {
                        Button(L("Done")) { dismiss() }
                    }
                }
            }
            .safeAreaInset(edge: .bottom) {
                if let onAccept {
                    Button {
                        onAccept()
                        dismiss()
                    } label: {
                        Text(L("I understand"))
                            .font(.headline)
                            .frame(maxWidth: .infinity, minHeight: 52)
                            .foregroundStyle(Palette.onPrimary)
                            .background(Palette.primary, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .padding(.horizontal, 20)
                    .padding(.bottom, 12)
                    .background(Palette.background.opacity(0.95))
                }
            }
        }
        // A required declaration: leaving it unacknowledged must return to the screen.
        .interactiveDismissDisabled(onAccept != nil)
    }

    private func section(title: String, text: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(Palette.onSurface)
            Text(text)
                .font(.footnote)
                .foregroundStyle(Palette.onSurfaceVariant)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(14)
        .card()
    }
}
