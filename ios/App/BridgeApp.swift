import SwiftUI

@main
struct BridgeApp: App {
    @StateObject private var model = AppModel()
    @StateObject private var navigator = Navigator()
    @StateObject private var latency = LatencyStore()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .environmentObject(model.tunnel)
                .environmentObject(navigator)
                .environmentObject(latency)
                .tint(Palette.primary)
                .task {
                    await model.bootstrap()
                }
        }
    }
}
