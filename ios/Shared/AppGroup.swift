import Foundation

/// File locations shared by the app and the packet tunnel.
///
/// Unix socket paths are limited to 104 bytes on Darwin, and the app group
/// container path alone is ~83, so everything that hosts a socket lives
/// directly under a one-letter directory of the container.
enum AppGroup {
    static let fallbackIdentifier = "group.org.forfoxsakesantuary.foxbridgeinternal"

    static var identifier: String {
        (Bundle.main.object(forInfoDictionaryKey: "BridgeAppGroup") as? String)
            .flatMap { $0.isEmpty ? nil : $0 } ?? fallbackIdentifier
    }

    /// The app group container, or a private directory when the build carries
    /// no app group entitlement (unsigned CI builds, simulator).
    static var containerURL: URL {
        if let url = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: identifier) {
            return url
        }
        let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        return support.appendingPathComponent("Bridge", isDirectory: true)
    }

    /// libbox base path; holds command.sock.
    static var sharedDirectory: URL { ensure(containerURL) }

    /// Private directory for runtime control sockets and status files.
    static var runtimeDirectory: URL { ensure(containerURL.appendingPathComponent("r", isDirectory: true)) }

    /// The bundled rule-sets, copied here by the tunnel (`RuleSetInstaller`); sing-box reads
    /// them as `local` rule-sets from this path.
    static var ruleSetDirectory: URL { ensure(containerURL.appendingPathComponent("rule-sets", isDirectory: true)) }

    static var cacheDirectory: URL {
        ensure(containerURL.appendingPathComponent("Library/Caches", isDirectory: true))
    }

    static var workingDirectory: URL { ensure(cacheDirectory.appendingPathComponent("Working", isDirectory: true)) }

    static var tempDirectory: URL { ensure(cacheDirectory.appendingPathComponent("Temp", isDirectory: true)) }

    /// Last launch options, so the system can restart the tunnel (on-demand,
    /// reboot) without the app supplying them again.
    static var launchSnapshotURL: URL { sharedDirectory.appendingPathComponent("launch.json") }

    static var tunnelLogURL: URL { cacheDirectory.appendingPathComponent("tunnel.log") }

    @discardableResult
    private static func ensure(_ url: URL) -> URL {
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }
}
