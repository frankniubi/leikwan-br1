import Foundation
import LeikwanIOSCore
import UserNotifications

/// Surge modules (`.sgmodule`): install, update, arguments, and running their scripts.
///
/// A module's `[Rule]` lines join the route ahead of the user's custom rules; its scripts
/// are downloaded here, stored in the app group, and run by the packet tunnel while the VPN
/// is up (the tunnel itself never downloads code).
extension AppModel {
    var moduleStore: SurgeModuleStore {
        SurgeModuleStore(directory: AppGroup.moduleDirectory)
    }

    private var scriptResultStore: ScriptResultStore {
        ScriptResultStore(url: AppGroup.scriptResultsURL)
    }

    func loadModules() {
        moduleLibrary = moduleStore.load()
        reloadScriptRuns()
    }

    func reloadScriptRuns() {
        let latest = scriptResultStore.all()
        if latest != scriptRuns { scriptRuns = latest }
    }

    /// `[Rule]` lines of the enabled modules, in module order.
    var moduleCustomRules: [CustomRoutingRule] {
        moduleStore.customRules(moduleLibrary)
    }

    var moduleScripts: [ResolvedScript] {
        moduleStore.resolvedScripts(moduleLibrary)
    }

    var modulePanels: [ResolvedPanel] {
        moduleStore.resolvedPanels(moduleLibrary)
    }

    /// The scripts as the tunnel's launch plan carries them.
    func scriptLaunches() -> [ScriptLaunch] {
        moduleScripts.map(Self.launch)
    }

    private static func launch(_ script: ResolvedScript) -> ScriptLaunch {
        ScriptLaunch(
            key: script.key,
            moduleName: script.moduleName,
            name: script.name,
            kind: script.kind.rawValue,
            cronExpression: script.cronExpression,
            eventName: script.eventName,
            sourcePath: script.sourcePath,
            argument: script.argument,
            timeoutSeconds: script.timeoutSeconds
        )
    }

    // MARK: Install / update

    /// Downloads a module for the install preview. Nothing is stored yet.
    func fetchModuleText(_ urlString: String) async throws -> String {
        let trimmed = urlString.trimmingCharacters(in: .whitespacesAndNewlines)
        guard SurgeModule.isHTTPSURL(trimmed), let url = URL(string: trimmed) else {
            throw AppError(L("The module URL must start with https://"))
        }
        let data = try await Self.download(url, limit: SurgeModule.maxBytes)
        let text = String(decoding: data, as: UTF8.self)
        try Self.checkLooksLikeModule(text)
        return text
    }

    static func checkLooksLikeModule(_ text: String) throws {
        if text.utf8.count > SurgeModule.maxBytes {
            throw AppError(L("The module is larger than 512 KB"))
        }
        let module = SurgeModule.parse(text)
        if module.isEmpty && module.unsupported.isEmpty {
            throw AppError(L("This does not look like a Surge module (no [Rule], [Script] or [Panel] section)"))
        }
    }

    /// Stores a module, downloads its scripts and rule lists, and turns it on.
    @discardableResult
    func installModule(text: String, sourceURL: String?, argumentValues: [String: String]) async throws -> String {
        try Self.checkLooksLikeModule(text)
        let module = SurgeModule.parse(text, argumentValues: argumentValues)
        var record = InstalledModule(
            id: SurgeModuleStore.newID(),
            sourceURL: sourceURL,
            name: Self.displayName(module, sourceURL: sourceURL),
            argumentValues: argumentValues
        )
        try moduleStore.writeModuleText(text, id: record.id)
        let failures = await fetchResources(for: &record, module: module, refresh: true)
        moduleLibrary.modules.append(record)
        modulesChanged()
        return Self.summary(record: record, module: module, failures: failures)
    }

    /// Fetches the module again from its URL (when it has one) and refreshes its scripts and
    /// rule lists. The copy on disk stays in use for anything that fails to download.
    @discardableResult
    func updateModule(_ id: String) async throws -> String {
        guard var record = moduleLibrary.modules.first(where: { $0.id == id }) else {
            throw AppError(L("Module not found"))
        }
        var text = moduleStore.moduleText(id)
        if let source = record.sourceURL {
            let fresh = try await fetchModuleText(source)
            try moduleStore.writeModuleText(fresh, id: id)
            text = fresh
        }
        guard let text else {
            throw AppError(L("The module file is missing. Delete the module and install it again"))
        }
        let module = SurgeModule.parse(text, argumentValues: record.argumentValues)
        let failures = await fetchResources(for: &record, module: module, refresh: true)
        record.name = Self.displayName(module, sourceURL: record.sourceURL)
        record.updatedAt = Date()
        // The list may have changed while downloading: write back by id, keep its switch.
        guard let index = moduleLibrary.modules.firstIndex(where: { $0.id == id }) else {
            throw AppError(L("Module not found"))
        }
        record.enabled = moduleLibrary.modules[index].enabled
        moduleLibrary.modules[index] = record
        modulesChanged()
        return Self.summary(record: record, module: module, failures: failures)
    }

    /// Updates every module that has a source URL; returns one line per failure.
    func updateAllModules() async -> [String] {
        var problems: [String] = []
        for record in moduleLibrary.modules where record.sourceURL != nil {
            do {
                try await updateModule(record.id)
            } catch {
                problems.append("\(record.name): \(Self.describe(error))")
            }
        }
        return problems
    }

    /// New argument values change what the module's lines say (and possibly which files it
    /// needs), so anything now missing is downloaded.
    func setModuleArguments(_ id: String, values: [String: String]) async -> [String] {
        guard var record = moduleLibrary.modules.first(where: { $0.id == id }) else { return [] }
        record.argumentValues = values
        var failures: [String] = []
        if let module = moduleStore.parsed(record) {
            failures = await fetchResources(for: &record, module: module, refresh: false)
        }
        guard let index = moduleLibrary.modules.firstIndex(where: { $0.id == id }) else { return failures }
        record.enabled = moduleLibrary.modules[index].enabled
        moduleLibrary.modules[index] = record
        modulesChanged()
        return failures
    }

    func setModuleEnabled(_ id: String, _ enabled: Bool) {
        guard let index = moduleLibrary.modules.firstIndex(where: { $0.id == id }),
              moduleLibrary.modules[index].enabled != enabled
        else { return }
        moduleLibrary.modules[index].enabled = enabled
        modulesChanged()
    }

    func setScriptsEnabled(_ enabled: Bool) {
        guard moduleLibrary.scriptsEnabled != enabled else { return }
        moduleLibrary.scriptsEnabled = enabled
        modulesChanged()
    }

    func deleteModule(_ id: String) {
        guard let record = moduleLibrary.modules.first(where: { $0.id == id }) else { return }
        for listID in record.ruleLists.values {
            importedRuleLists.delete(listID)
        }
        moduleStore.deleteFiles(id)
        moduleLibrary.modules.removeAll { $0.id == id }
        modulesChanged()
    }

    /// Saves the library, drops results of scripts that are gone, and hands the new rules
    /// and scripts to a running tunnel.
    private func modulesChanged() {
        do {
            try moduleStore.save(moduleLibrary)
        } catch {
            notice = L("The modules could not be saved: %@", Self.describe(error))
        }
        let scripts = moduleScripts
        scriptResultStore.prune(keeping: Set(scripts.map { $0.key }))
        reloadScriptRuns()
        if !scripts.isEmpty {
            // Scripts report through local notifications ($notification.post).
            UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound]) { _, _ in }
        }
        scheduleTunnelReload()
    }

    /// Downloads the module's scripts and rule lists into `record`. With `refresh` false,
    /// files already on disk are kept as they are. Returns one line per failed download.
    private func fetchResources(for record: inout InstalledModule, module: SurgeModule, refresh: Bool) async -> [String] {
        var failures: [String] = []
        let store = moduleStore
        let lists = importedRuleLists

        var scriptFiles: [String: String] = [:]
        for path in module.scriptURLs {
            let existing = record.scriptFiles[path].flatMap { name in
                store.scriptPath(moduleID: record.id, fileName: name) == nil ? nil : name
            }
            if !refresh, let existing {
                scriptFiles[path] = existing
                continue
            }
            do {
                guard let url = URL(string: path) else { throw AppError(L("Invalid URL")) }
                let data = try await Self.download(url, limit: SurgeScriptRunner.maxSourceBytes)
                scriptFiles[path] = try store.writeScript(data, moduleID: record.id)
            } catch {
                if let existing { scriptFiles[path] = existing }
                failures.append("\(Self.fileLabel(path)): \(Self.describe(error))")
            }
        }
        record.scriptFiles = scriptFiles
        store.pruneScripts(record)

        var ruleLists: [String: String] = [:]
        for list in module.remoteLists {
            let existing = record.ruleLists[list.url].flatMap { id in lists.path(for: id) == nil ? nil : id }
            if !refresh, let existing {
                ruleLists[list.url] = existing
                continue
            }
            do {
                guard let url = URL(string: list.url) else { throw AppError(L("Invalid URL")) }
                let data = try await Self.download(url, limit: SurgeListConverter.maxBytes)
                let text = String(decoding: data, as: UTF8.self)
                let conversion = try (list.domainSet ? SurgeListConverter.parseDomainSet(text) : SurgeListConverter.parse(text))
                if conversion.isEmpty { throw AppError(L("The list has no usable rules")) }
                let saved = try lists.save(
                    name: "\(record.name): \(Self.fileLabel(list.url))",
                    sourceURL: list.url,
                    conversion: conversion,
                    existingID: existing
                )
                ruleLists[list.url] = saved.id
            } catch {
                if let existing { ruleLists[list.url] = existing }
                failures.append("\(Self.fileLabel(list.url)): \(Self.describe(error))")
            }
        }
        // Lists the module no longer refers to.
        for (url, id) in record.ruleLists where ruleLists[url] != id {
            lists.delete(id)
        }
        record.ruleLists = ruleLists
        return failures
    }

    private static func download(_ url: URL, limit: Int) async throws -> Data {
        let request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 30)
        let (data, response) = try await URLSession.shared.data(for: request)
        if let http = response as? HTTPURLResponse, !(200 ..< 300).contains(http.statusCode) {
            throw AppError(L("Download returned HTTP %@", String(http.statusCode)))
        }
        if data.count > limit {
            throw AppError(L("The file is larger than %@ KB", String(limit / 1024)))
        }
        return data
    }

    private static func fileLabel(_ urlString: String) -> String {
        let name = URL(string: urlString)?.lastPathComponent ?? ""
        return name.isEmpty ? urlString : name
    }

    private static func displayName(_ module: SurgeModule, sourceURL: String?) -> String {
        if !module.name.isEmpty { return module.name }
        if let sourceURL { return fileLabel(sourceURL) }
        return L("Module")
    }

    private static func summary(record: InstalledModule, module: SurgeModule, failures: [String]) -> String {
        var text = L("“%@”: %@ rule(s), %@ script(s), %@ panel(s)", record.name, String(module.rules.count), String(module.scripts.count), String(module.panels.count))
        let skipped = module.unsupported.values.reduce(0, +)
        if skipped > 0 {
            text += L("; %@ line(s) are not supported and were left out", String(skipped))
        }
        if !failures.isEmpty {
            text += L("; failed to download: %@", failures.joined(separator: "; "))
        }
        return text
    }

    // MARK: Running scripts

    /// Runs a script now: inside the tunnel while the VPN is up (same process and network
    /// path as its scheduled runs), in the app otherwise.
    @discardableResult
    func runScript(_ script: ResolvedScript, trigger: String) async -> ScriptOutcome {
        guard !runningScripts.contains(script.key) else { return .failure("already_running") }
        runningScripts.insert(script.key)
        defer { runningScripts.remove(script.key) }
        var outcome: ScriptOutcome?
        if tunnel.isConnected {
            // nil when the tunnel's plan does not hold this script yet (a reload is on its way).
            outcome = await tunnel.runScript(key: script.key, trigger: trigger)
        }
        let finished: ScriptOutcome
        if let outcome {
            finished = outcome
        } else {
            finished = await scriptExecutor.run(Self.launch(script), trigger: trigger)
        }
        reloadScriptRuns()
        return finished
    }

    /// Runs the panel scripts whose `update-interval` has passed (or that never ran).
    func refreshDuePanels() async {
        let scripts = moduleScripts
        for panel in modulePanels {
            guard let key = panel.scriptKey, let interval = panel.updateInterval,
                  let script = scripts.first(where: { $0.key == key })
            else { continue }
            if let last = scriptRuns[key], Date().timeIntervalSince(last.finishedAt) < Double(interval) { continue }
            await runScript(script, trigger: "auto-interval")
        }
    }
}
