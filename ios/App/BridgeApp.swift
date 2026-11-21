import SwiftUI

@main
struct BridgeApp: App {
    @StateObject private var model = AppModel()
    @StateObject private var navigator = Navigator()
    @StateObject private var latency = LatencyStore()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .environmentObject(model.tunnel)
                .environmentObject(model.localPorts)
                .environmentObject(navigator)
                .environmentObject(latency)
                .tint(Palette.primary)
                .task {
                    await model.bootstrap()
                    #if DEBUG
                    await ScreenshotLaunch.signInToDemoIfRequested(model)
                    ScreenshotLaunch.selectSectionIfRequested(navigator)
                    #endif
                }
        }
        .onChange(of: scenePhase) { phase in
            // The dashboard's one-second status poll only matters on screen; in the
            // background the tunnel controller drops to a 5 s cadence (or stops, once
            // iOS suspends the app). iOS 16 two-parameter form.
            model.tunnel.setAppVisible(phase == .active)
            // Ordinary app listeners cannot be a persistent cross-app background helper.
            // Inactive (e.g. Control Center) is transient; background explicitly stops.
            if phase == .background { model.localPorts.setAppVisible(false) }
            if phase == .active {
                model.localPorts.setAppVisible(true)
                Task {
                    await model.refreshCatalog()
                }
            }
        }
    }
}
