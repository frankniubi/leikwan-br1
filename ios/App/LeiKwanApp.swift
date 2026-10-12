import SwiftUI

@main
struct LeiKwanApp: App {
    @StateObject private var model = AppModel()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .environmentObject(model.tunnel)
                .task {
                    await model.bootstrap()
                }
        }
    }
}
