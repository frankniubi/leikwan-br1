import Foundation

/// One imported Surge/Clash rule list: its index entry. The rules themselves are a sing-box
/// source rule-set file `<id>.json` in the store's directory, applied by a `USER-LIST`
/// custom rule whose value is the id.
public struct ImportedRuleList: Codable, Equatable, Identifiable {
    public var id: String
    public var name: String
    public var sourceURL: String?
    public var ruleCount: Int
    public var skippedCount: Int
    public var finalPolicy: String?
    public var importedAt: Date

    public init(id: String, name: String, sourceURL: String?, ruleCount: Int, skippedCount: Int, finalPolicy: String?, importedAt: Date) {
        self.id = id
        self.name = name
        self.sourceURL = sourceURL
        self.ruleCount = ruleCount
        self.skippedCount = skippedCount
        self.finalPolicy = finalPolicy
        self.importedAt = importedAt
    }
}

/// File-backed store for imported lists. Lives in the app group (the tunnel's sing-box reads
/// the files by path) in its own directory: `RuleSetInstaller` wipes the bundled rule-set
/// directory on every app update, so user lists must never live there.
public struct ImportedRuleListStore {
    public let directory: URL

    public init(directory: URL) {
        self.directory = directory
    }

    /// Rule-set tag of list `id` (also its file name without `.json`).
    public static func tag(for id: String) -> String { "user-list-\(id)" }

    /// Ids double as file names and sing-box tag suffixes: `l` + 12 lowercase hex.
    public static func isValidID(_ id: String) -> Bool {
        id.count == 13 && id.hasPrefix("l") && id.dropFirst().allSatisfy { $0.isHexDigit && !$0.isUppercase }
    }

    public func all() -> [ImportedRuleList] {
        guard let data = try? Data(contentsOf: indexURL) else { return [] }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .secondsSince1970
        return (try? decoder.decode([ImportedRuleList].self, from: data)) ?? []
    }

    public func get(_ id: String) -> ImportedRuleList? { all().first { $0.id == id } }

    public func fileURL(for id: String) -> URL { directory.appendingPathComponent("\(id).json") }

    /// Absolute path sing-box loads, or nil when the list (or its file) is gone.
    public func path(for id: String) -> String? {
        guard Self.isValidID(id) else { return nil }
        let url = fileURL(for: id)
        return FileManager.default.fileExists(atPath: url.path) ? url.path : nil
    }

    /// Writes a new list, or replaces `existingID`'s contents (a refresh).
    @discardableResult
    public func save(
        name: String,
        sourceURL: String?,
        conversion: SurgeListConverter.Conversion,
        now: Date = Date(),
        existingID: String? = nil
    ) throws -> ImportedRuleList {
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let id = existingID.flatMap { Self.isValidID($0) ? $0 : nil } ?? Self.newID()
        try SurgeListConverter.json(conversion).write(to: fileURL(for: id), options: .atomic)
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        let fallback = sourceURL.flatMap { URL(string: $0)?.lastPathComponent }.flatMap { $0.isEmpty ? nil : $0 } ?? id
        let entry = ImportedRuleList(
            id: id,
            name: trimmed.isEmpty ? fallback : trimmed,
            sourceURL: sourceURL,
            ruleCount: conversion.convertedCount,
            skippedCount: conversion.skippedCount,
            finalPolicy: conversion.finalPolicy,
            importedAt: now
        )
        try writeIndex(all().filter { $0.id != id } + [entry])
        return entry
    }

    public func delete(_ id: String) {
        guard Self.isValidID(id) else { return }
        try? FileManager.default.removeItem(at: fileURL(for: id))
        try? writeIndex(all().filter { $0.id != id })
    }

    /// sing-box `route.rule_set` entry for list `id` at `path`.
    public static func singboxEntry(id: String, path: String) -> [String: Any] {
        ["tag": tag(for: id), "type": "local", "format": "source", "path": path]
    }

    private var indexURL: URL { directory.appendingPathComponent("index.json") }

    private func writeIndex(_ entries: [ImportedRuleList]) throws {
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .secondsSince1970
        encoder.outputFormatting = [.sortedKeys]
        try encoder.encode(entries).write(to: indexURL, options: .atomic)
    }

    private static func newID() -> String {
        var bytes = [UInt8](repeating: 0, count: 6)
        for i in bytes.indices { bytes[i] = UInt8.random(in: 0...255) }
        return "l" + bytes.map { String(format: "%02x", $0) }.joined()
    }
}
