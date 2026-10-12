import SwiftUI

@main
struct LeiKwanApp: App {
    @StateObject private var model = AppModel()
    @StateObject private var navigator = Navigator()
    @StateObject private var latency = LatencyStore()
    @StateObject private var probe = NetworkProbe()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .environmentObject(model.tunnel)
                .environmentObject(navigator)
                .environmentObject(latency)
                .environmentObject(probe)
                .tint(Palette.primary)
                .task {
                    await model.bootstrap()
                }
        }
    }
}
