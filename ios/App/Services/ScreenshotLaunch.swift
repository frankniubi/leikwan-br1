#if DEBUG
import Foundation
import LeikwanIOSCore

/// Debug builds only. `scripts/duo-posture-screenshots.sh` launches the app with
/// `-bridge_ci_demo YES` to reach the dashboard without taps. The App Review demo account signs
/// in locally, so nothing is sent to a server. Release builds do not contain this.
enum ScreenshotLaunch {
    static let demoKey = "bridge_ci_demo"

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
