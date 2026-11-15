import Foundation
import LeikwanIOSCore

/// Routing choices the user makes on the Rules tab.
struct RoutingPreferences: Codable, Equatable {
    var mode: RoutingGlobalMode = .rule
    var bypassChina: Bool = true
    /// App preset key -> outcome; absent keys are off.
    var appPresets: [String: UserTrafficOutcome] = [:]
    /// ISO country code -> outcome.
    var countries: [String: UserTrafficOutcome] = [:]
    var customRules: [CustomRoutingRule] = []
    var customBypass: String = ""
    var enableIPv6: Bool = false
    var dnsServer: String = "1.1.1.1"
}

/// Small JSON-file persistence in the app's private Application Support.
enum JSONStore {
    static func load<T: Decodable>(_ type: T.Type, name: String) -> T? {
        guard let data = try? Data(contentsOf: url(name)) else { return nil }
        return try? JSONDecoder().decode(type, from: data)
    }

    static func save<T: Encodable>(_ value: T, name: String) {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        guard let data = try? encoder.encode(value) else { return }
        try? data.write(to: url(name), options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }

    /// Durable diagnostics must not silently become a fresh upload when storage fails.
    static func loadChecked<T: Decodable>(_ type: T.Type, name: String, maxBytes: Int) throws -> T? {
        let fileURL = url(name)
        guard FileManager.default.fileExists(atPath: fileURL.path) else { return nil }
        let file = try FileHandle(forReadingFrom: fileURL)
        defer { try? file.close() }
        let data = try file.read(upToCount: maxBytes + 1) ?? Data()
        guard data.count <= maxBytes else { throw DebugLogUploadError.invalidPendingBundle }
        return try JSONDecoder().decode(type, from: data)
    }

    static func saveChecked<T: Encodable>(_ value: T, name: String) throws {
        var fileURL = url(name)
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys, .withoutEscapingSlashes]
        let data = try encoder.encode(value)
        try data.write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        // Diagnostic snapshots are private, short-lived device data, not a backup payload.
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try fileURL.setResourceValues(values)
    }

    private static func url(_ name: String) -> URL {
        let directory = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Bridge", isDirectory: true)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        return directory.appendingPathComponent(name + ".json")
    }
}

struct Subscription: Codable, Equatable, Identifiable {
    var id: Int64
    var name: String
    var url: String
    var lastUpdated: Date?
}
