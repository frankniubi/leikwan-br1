#if DEBUG
import Foundation
import LeikwanIOSCore

/// Debug builds only. `scripts/duo-posture-screenshots.sh` launches the app with
/// `-bridge_ci_demo YES` to reach the dashboard without taps, and `-bridge_ci_section <name>` to
/// open another tab. The App Review demo account signs in locally, so nothing is sent to a server.
/// Release builds do not contain this.
enum ScreenshotLaunch {
    static let demoKey = "bridge_ci_demo"
    static let sectionKey = "bridge_ci_section"

    /// Opens the tab named by `-bridge_ci_section` (dashboard, proxies, rules, tools).
    @MainActor
    static func selectSectionIfRequested(_ navigator: Navigator) {
        guard let name = UserDefaults.standard.string(forKey: sectionKey),
              let section = AppSection(rawValue: name) else { return }
        navigator.section = section
    }

    @MainActor
    static func signInToDemoIfRequested(_ model: AppModel) async {
        guard UserDefaults.standard.bool(forKey: demoKey), model.profile == nil else { return }
        var draft = ProfileDraft()
        draft.server = "https://api-leikwanbridge.nbwly.com"
        draft.clientID = String(DemoAccount.clientId)
        draft.token = DemoAccount.agentToken
        switch draft.validated() {
        case .success(let profile):
            do {
                try await model.signIn(profile, token: draft.token)
            } catch {
                print("screenshot demo sign-in failed: \(error)")
            }
        case .failure(let error):
            print("screenshot demo profile invalid: \(error)")
        }
    }
}
#endif
