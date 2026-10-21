// swift-tools-version: 5.9
//
// This package intentionally contains only platform-neutral control-plane
// models. Network Extension and app targets require Xcode signing and are
// added after an Apple Developer team is available.
import PackageDescription

let package = Package(
    name: "LeikwanIOSCore",
    defaultLocalization: "en",
    platforms: [
        .iOS(.v16),
        .macOS(.v13)
    ],
    products: [
        .library(name: "LeikwanIOSCore", targets: ["LeikwanIOSCore"])
    ],
    targets: [
        .target(name: "LeikwanIOSCore", resources: [.process("Resources")]),
        .testTarget(name: "LeikwanIOSCoreTests", dependencies: ["LeikwanIOSCore"])
    ]
)
