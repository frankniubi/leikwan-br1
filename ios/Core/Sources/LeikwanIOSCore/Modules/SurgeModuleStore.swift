import Foundation

/// One installed module: its index entry. The module text is `<id>/module.sgmodule` in the
/// store's directory, next to the script files it references.
public struct InstalledModule: Codable, Equatable, Identifiable {
    public var id: String
    public var sourceURL: String?
    public var name: String
    public var enabled: Bool
    /// The user's values for the module's `#!arguments`.
    public var argumentValues: [String: String]
    public var installedAt: Date
    public var updatedAt: Date
    /// `script-path` URL -> file name inside the module's directory.
    public var scriptFiles: [String: String]
    /// Rule list URL -> `ImportedRuleListStore` id.
    public var ruleLists: [String: String]

    public init(
        id: String,
        sourceURL: String?,
        name: String,
        enabled: Bool = true,
        argumentValues: [String: String] = [:],
        installedAt: Date = Date(),
        updatedAt: Date = Date(),
        scriptFiles: [String: String] = [:],
        ruleLists: [String: String] = [:]
    ) {
        self.id = id
        self.sourceURL = sourceURL
        self.name = name
        self.enabled = enabled
        self.argumentValues = argumentValues
        self.installedAt = installedAt
        self.updatedAt = updatedAt
        self.scriptFiles = scriptFiles
        self.ruleLists = ruleLists
    }
}

public struct ModuleLibrary: Codable, Equatable {
    /// Master switch for every module script (rules are not affected).
    public var scriptsEnabled: Bool
    public var modules: [InstalledModule]

    public init(scriptsEnabled: Bool = true, modules: [InstalledModule] = []) {
        self.scriptsEnabled = scriptsEnabled
        self.modules = modules
    }
}

/// A module script ready to run: its source is on disk.
public struct ResolvedScript: Equatable, Identifiable {
    /// `<module id>/<script name>`.
    public let key: String
    public let moduleID: String
    public let moduleName: String
    public let name: String
    public let kind: SurgeModule.Script.Kind
    public let cronExpression: String?
    public let eventName: String?
    public let sourcePath: String
    public let argument: String?
    public let timeoutSeconds: Int

    public var id: String { key }
}

public struct ResolvedPanel: Equatable, Identifiable {
    /// `<module id>/<panel name>`.
    public let key: String
    public let moduleID: String
    public let moduleName: String
    public let name: String
    /// Key of the script the panel shows, when the module has it and its source is on disk.
    public let scriptKey: String?
    public let title: String
    public let content: String
    public let updateInterval: Int?

    public var id: String { key }
}

/// File-backed module library. Lives in the app group: the packet tunnel reads the script
/// files by path, and never downloads code itself.
public struct SurgeModuleStore {
    public let directory: URL

    public init(directory: URL) {
        self.directory = directory
    }

    /// `m` + 12 lowercase hex; doubles as the module's directory name.
    public static func isValidID(_ id: String) -> Bool {
        id.count == 13 && id.hasPrefix("m") && id.dropFirst().allSatisfy { $0.isHexDigit && !$0.isUppercase }
    }

    public static func newID() -> String {
        var bytes = [UInt8](repeating: 0, count: 6)
        for i in bytes.indices { bytes[i] = UInt8.random(in: 0...255) }
        return "m" + bytes.map { String(format: "%02x", $0) }.joined()
    }

    public static func scriptKey(moduleID: String, name: String) -> String { "\(moduleID)/\(name)" }

    // MARK: Index

    public func load() -> ModuleLibrary {
        guard let data = try? Data(contentsOf: indexURL) else { return ModuleLibrary() }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .secondsSince1970
        return (try? decoder.decode(ModuleLibrary.self, from: data)) ?? ModuleLibrary()
    }

    public func save(_ library: ModuleLibrary) throws {
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .secondsSince1970
        encoder.outputFormatting = [.sortedKeys]
        try encoder.encode(library).write(to: indexURL, options: .atomic)
    }

    // MARK: Files

    public func moduleDirectory(_ id: String) -> URL { directory.appendingPathComponent(id, isDirectory: true) }

    public func moduleText(_ id: String) -> String? {
        guard Self.isValidID(id), let data = try? Data(contentsOf: textURL(id)) else { return nil }
        return String(decoding: data, as: UTF8.self)
    }

    public func writeModuleText(_ text: String, id: String) throws {
        guard Self.isValidID(id) else { throw CocoaError(.fileWriteInvalidFileName) }
        try FileManager.default.createDirectory(at: moduleDirectory(id), withIntermediateDirectories: true)
        try Data(text.utf8).write(to: textURL(id), options: .atomic)
    }

    /// Stores a downloaded script and returns its file name (content-addressed, so an update
    /// that changes nothing rewrites nothing).
    public func writeScript(_ data: Data, moduleID: String) throws -> String {
        guard Self.isValidID(moduleID) else { throw CocoaError(.fileWriteInvalidFileName) }
        try FileManager.default.createDirectory(at: moduleDirectory(moduleID), withIntermediateDirectories: true)
        let name = String(Hashing.sha256Hex(data).prefix(24)) + ".js"
        try data.write(to: moduleDirectory(moduleID).appendingPathComponent(name), options: .atomic)
        return name
    }

    /// Absolute path of a stored script, or nil when the file is gone.
    public func scriptPath(moduleID: String, fileName: String) -> String? {
        guard Self.isValidID(moduleID), !fileName.contains("/"), fileName.hasSuffix(".js") else { return nil }
        let url = moduleDirectory(moduleID).appendingPathComponent(fileName)
        return FileManager.default.fileExists(atPath: url.path) ? url.path : nil
    }

    /// Removes script files of `record` that no script-path refers to any more.
    public func pruneScripts(_ record: InstalledModule) {
        let keep = Set(record.scriptFiles.values)
        let folder = moduleDirectory(record.id)
        for name in (try? FileManager.default.contentsOfDirectory(atPath: folder.path)) ?? [] where name.hasSuffix(".js") && !keep.contains(name) {
            try? FileManager.default.removeItem(at: folder.appendingPathComponent(name))
        }
    }

    public func deleteFiles(_ id: String) {
        guard Self.isValidID(id) else { return }
        try? FileManager.default.removeItem(at: moduleDirectory(id))
    }

    // MARK: Resolution

    /// The module as it runs: its stored text with the record's argument values applied.
    public func parsed(_ record: InstalledModule) -> SurgeModule? {
        moduleText(record.id).map { SurgeModule.parse($0, argumentValues: record.argumentValues) }
    }

    /// Scripts of the enabled modules whose source is on disk; none when scripts are off.
    public func resolvedScripts(_ library: ModuleLibrary) -> [ResolvedScript] {
        guard library.scriptsEnabled else { return [] }
        var result: [ResolvedScript] = []
        for record in library.modules where record.enabled {
            guard let module = parsed(record) else { continue }
            for script in module.scripts {
                guard let file = record.scriptFiles[script.scriptPath],
                      let path = scriptPath(moduleID: record.id, fileName: file)
                else { continue }
                let key = Self.scriptKey(moduleID: record.id, name: script.name)
                if result.contains(where: { $0.key == key }) { continue }
                result.append(ResolvedScript(
                    key: key,
                    moduleID: record.id,
                    moduleName: record.name,
                    name: script.name,
                    kind: script.kind,
                    cronExpression: script.cronExpression,
                    eventName: script.eventName,
                    sourcePath: path,
                    argument: script.argument,
                    timeoutSeconds: script.timeoutSeconds
                ))
            }
        }
        return result
    }

    /// Panels of the enabled modules.
    public func resolvedPanels(_ library: ModuleLibrary) -> [ResolvedPanel] {
        let scripts = Set(resolvedScripts(library).map { $0.key })
        var result: [ResolvedPanel] = []
        for record in library.modules where record.enabled {
            guard let module = parsed(record) else { continue }
            for panel in module.panels {
                let scriptKey = panel.scriptName.map { Self.scriptKey(moduleID: record.id, name: $0) }
                result.append(ResolvedPanel(
                    key: Self.scriptKey(moduleID: record.id, name: panel.name),
                    moduleID: record.id,
                    moduleName: record.name,
                    name: panel.name,
                    scriptKey: scriptKey.flatMap { scripts.contains($0) ? $0 : nil },
                    title: panel.title,
                    content: panel.content,
                    updateInterval: panel.updateInterval
                ))
            }
        }
        return result
    }

    /// `[Rule]` lines of the enabled modules as custom rules, in module order then line order.
    /// A remote list that was not downloaded is left out.
    public func customRules(_ library: ModuleLibrary) -> [CustomRoutingRule] {
        var result: [CustomRoutingRule] = []
        for record in library.modules where record.enabled {
            guard let module = parsed(record) else { continue }
            for (index, rule) in module.rules.enumerated() {
                let id = "module-\(record.id)-\(index)"
                switch rule.match {
                case let .inline(type, value):
                    result.append(CustomRoutingRule(id: id, type: type, value: value, outcome: rule.outcome))
                case let .remoteList(url, _):
                    if let listID = record.ruleLists[url] {
                        result.append(CustomRoutingRule(id: id, type: .userList, value: listID, outcome: rule.outcome))
                    }
                }
            }
        }
        return result
    }

    private var indexURL: URL { directory.appendingPathComponent("index.json") }

    private func textURL(_ id: String) -> URL { moduleDirectory(id).appendingPathComponent("module.sgmodule") }
}
