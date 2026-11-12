import LeikwanIOSCore
import SwiftUI
import UIKit

/// Installed modules: switch them on and off, update them, install new ones.
struct ModulesView: View {
    @EnvironmentObject private var model: AppModel
    @State private var installing = false
    @State private var updatingAll = false
    @State private var message: String?

    var body: some View {
        List {
            if model.moduleLibrary.modules.isEmpty {
                Section {
                    VStack(alignment: .leading, spacing: 8) {
                        Text(L("No modules yet"))
                            .font(.headline)
                        Text(L("Install a Surge module (.sgmodule) from its URL. Its rules, scheduled scripts and panels are used; anything that needs HTTP rewrite or MITM is left out and listed."))
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                }
            } else {
                Section {
                    ForEach(model.moduleLibrary.modules) { record in
                        NavigationLink {
                            ModuleDetailView(moduleID: record.id)
                        } label: {
                            ModuleRow(record: record)
                        }
                    }
                    .onDelete { offsets in
                        let ids = offsets.map { model.moduleLibrary.modules[$0].id }
                        ids.forEach(model.deleteModule)
                    }
                } footer: {
                    Text(L("Module rules are matched before your custom rules, in the order shown here."))
                }
            }

            Section {
                Button {
                    installing = true
                } label: {
                    Label(L("Install module"), systemImage: "plus")
                }
                if model.moduleLibrary.modules.contains(where: { $0.sourceURL != nil }) {
                    Button {
                        Task { await updateAll() }
                    } label: {
                        HStack {
                            Label(L("Update all"), systemImage: "arrow.clockwise")
                            if updatingAll {
                                Spacer()
                                ProgressView()
                            }
                        }
                    }
                    .disabled(updatingAll)
                }
                if let message {
                    Text(message)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .scrollContentBackground(.hidden)
        .foldClear()
        .pageBackground()
        .navigationTitle(L("Modules"))
        .navigationBarTitleDisplayMode(.inline)
        .sheet(isPresented: $installing) {
            ModuleInstallSheet { summary in
                message = summary
            }
        }
    }

    private func updateAll() async {
        updatingAll = true
        defer { updatingAll = false }
        let problems = await model.updateAllModules()
        message = problems.isEmpty ? L("All modules are up to date") : problems.joined(separator: "\n")
    }
}

private struct ModuleRow: View {
    @EnvironmentObject private var model: AppModel
    let record: InstalledModule

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 3) {
                Text(record.name)
                    .font(.body.weight(.medium))
                Text(subtitle)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
            Spacer(minLength: 8)
            Toggle(L("Enabled"), isOn: Binding(
                get: { record.enabled },
                set: { model.setModuleEnabled(record.id, $0) }
            ))
            .labelsHidden()
        }
    }

    private var subtitle: String {
        guard let module = model.moduleStore.parsed(record) else { return L("The module file is missing") }
        return L("%@ rule(s) · %@ script(s) · %@ panel(s)", String(module.rules.count), String(module.scripts.count), String(module.panels.count))
    }
}

/// One module: what it contains, its arguments, what was left out.
struct ModuleDetailView: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let moduleID: String
    @State private var arguments: [String: String] = [:]
    @State private var loadedArguments = false
    @State private var busy = false
    @State private var message: String?
    @State private var confirmingDelete = false

    private var record: InstalledModule? {
        model.moduleLibrary.modules.first { $0.id == moduleID }
    }

    var body: some View {
        Form {
            if let record, let module = model.moduleStore.parsed(record) {
                Section {
                    Toggle(L("Enabled"), isOn: Binding(
                        get: { record.enabled },
                        set: { model.setModuleEnabled(record.id, $0) }
                    ))
                    if !module.desc.isEmpty {
                        Text(module.desc)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    if let author = module.author, !author.isEmpty {
                        LabeledContent(L("Author"), value: author)
                    }
                    if let source = record.sourceURL {
                        LabeledContent(L("Source")) {
                            Text(source)
                                .font(.caption.monospaced())
                                .lineLimit(3)
                                .textSelection(.enabled)
                        }
                    }
                    LabeledContent(L("Updated"), value: ScriptText.time(record.updatedAt))
                }

                if !module.arguments.isEmpty {
                    ModuleArgumentsSection(module: module, values: $arguments)
                    Section {
                        Button(L("Save arguments")) {
                            Task { await saveArguments() }
                        }
                        .disabled(busy || arguments == effectiveArguments(record, module))
                    }
                }

                ModuleContentsSections(module: module, record: record)

                Section {
                    if record.sourceURL != nil {
                        Button {
                            Task { await update() }
                        } label: {
                            HStack {
                                Label(L("Update module"), systemImage: "arrow.clockwise")
                                if busy {
                                    Spacer()
                                    ProgressView()
                                }
                            }
                        }
                        .disabled(busy)
                    }
                    Button(role: .destructive) {
                        confirmingDelete = true
                    } label: {
                        Label(L("Delete module"), systemImage: "trash")
                    }
                    if let message {
                        Text(message)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                .onAppear {
                    guard !loadedArguments else { return }
                    loadedArguments = true
                    arguments = effectiveArguments(record, module)
                }
            } else {
                Text(L("The module file is missing. Delete the module and install it again"))
                    .foregroundStyle(.secondary)
            }
        }
        .scrollContentBackground(.hidden)
        .foldClear()
        .pageBackground()
        .navigationTitle(record?.name ?? L("Module"))
        .navigationBarTitleDisplayMode(.inline)
        .confirmationDialog(L("Delete this module? Its rules, scripts and panels stop at once."), isPresented: $confirmingDelete, titleVisibility: .visible) {
            Button(L("Delete module"), role: .destructive) {
                model.deleteModule(moduleID)
                dismiss()
            }
            Button(L("Cancel"), role: .cancel) {}
        }
    }

    /// The saved values, with the module's defaults for arguments never set.
    private func effectiveArguments(_ record: InstalledModule, _ module: SurgeModule) -> [String: String] {
        var values: [String: String] = [:]
        for argument in module.arguments {
            values[argument.name] = record.argumentValues[argument.name] ?? argument.defaultValue
        }
        return values
    }

    private func saveArguments() async {
        busy = true
        defer { busy = false }
        let failures = await model.setModuleArguments(moduleID, values: arguments)
        message = failures.isEmpty ? L("Arguments saved") : L("Arguments saved; failed to download: %@", failures.joined(separator: "; "))
    }

    private func update() async {
        busy = true
        defer { busy = false }
        do {
            message = try await model.updateModule(moduleID)
        } catch {
            message = AppModel.describe(error)
        }
    }
}

/// Text fields for a module's `#!arguments`.
private struct ModuleArgumentsSection: View {
    let module: SurgeModule
    @Binding var values: [String: String]

    var body: some View {
        Section {
            ForEach(module.arguments, id: \.name) { argument in
                VStack(alignment: .leading, spacing: 4) {
                    Text(argument.name)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    TextField(argument.defaultValue.isEmpty ? argument.name : argument.defaultValue, text: Binding(
                        get: { values[argument.name] ?? "" },
                        set: { values[argument.name] = $0 }
                    ), axis: .vertical)
                    .font(.body.monospaced())
                    .lineLimit(1...4)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                }
            }
        } header: {
            Text(L("Arguments"))
        } footer: {
            if let description = module.argumentsDescription, !description.isEmpty {
                Text(description)
            }
        }
    }
}

/// Rules, scripts, panels and the unsupported parts of a module. `record` nil = a preview
/// before install (nothing downloaded yet).
private struct ModuleContentsSections: View {
    let module: SurgeModule
    let record: InstalledModule?

    var body: some View {
        if !module.rules.isEmpty {
            Section(L("Rules (%@)", String(module.rules.count))) {
                ForEach(Array(module.rules.enumerated()), id: \.offset) { _, rule in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(Self.ruleText(rule))
                            .font(.footnote.monospaced())
                        Text(Self.ruleDetail(rule, record: record))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        if !module.scripts.isEmpty {
            Section {
                ForEach(module.scripts, id: \.name) { script in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(script.name)
                            .font(.body)
                        Text(Self.scriptDetail(script))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Text(script.scriptPath)
                            .font(.caption2.monospaced())
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                        if let record, record.scriptFiles[script.scriptPath] == nil {
                            Text(L("Not downloaded. Update the module"))
                                .font(.caption)
                                .foregroundStyle(Palette.bad)
                        }
                    }
                }
            } header: {
                Text(L("Scripts (%@)", String(module.scripts.count)))
            } footer: {
                Text(L("Scripts are code from the addresses above. They can make network requests from this device, keep small values and post notifications. Install modules only from authors you trust."))
            }
        }
        if !module.panels.isEmpty {
            Section(L("Panels (%@)", String(module.panels.count))) {
                ForEach(module.panels, id: \.name) { panel in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(panel.title)
                        if let interval = panel.updateInterval {
                            Text(L("Refreshes every %@ s while the Rules page is open, or on tap", String(interval)))
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
        if !module.unsupported.isEmpty {
            Section {
                ForEach(module.unsupported.keys.sorted(), id: \.self) { key in
                    LabeledContent(key, value: String(module.unsupported[key] ?? 0))
                }
            } header: {
                Text(L("Not supported, left out"))
            } footer: {
                Text(L("Bridge has no HTTP engine and does not decrypt traffic, so rewrites, MITM and request/response scripts cannot run. The number is how many lines were skipped."))
            }
        }
    }

    private static func ruleText(_ rule: SurgeModule.Rule) -> String {
        switch rule.match {
        case let .inline(type, value): return "\(type.rawValue),\(value)"
        case let .remoteList(url, domainSet): return "\(domainSet ? "DOMAIN-SET" : "RULE-SET"),\(url)"
        }
    }

    private static func ruleDetail(_ rule: SurgeModule.Rule, record: InstalledModule?) -> String {
        let outcome = RulesView.outcomeLabel(rule.outcome)
        if case let .remoteList(url, _) = rule.match, let record, record.ruleLists[url] == nil {
            return L("%@ · list not downloaded, rule inactive", outcome)
        }
        return outcome
    }

    private static func scriptDetail(_ script: SurgeModule.Script) -> String {
        let trigger: String
        switch script.kind {
        case .cron: trigger = L("Schedule %@", script.cronExpression ?? "")
        case .event: trigger = L("When the network changes")
        case .generic: trigger = L("From a panel or on demand")
        }
        return L("%@ · time limit %@ s", trigger, String(script.timeoutSeconds))
    }
}

/// Install flow: fetch (or paste) a module, show exactly what it will do, then install.
private struct ModuleInstallSheet: View {
    @EnvironmentObject private var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var url = ""
    @State private var pasted = ""
    @State private var usingPaste = false
    @State private var text: String?
    @State private var sourceURL: String?
    @State private var arguments: [String: String] = [:]
    @State private var busy = false
    @State private var error: String?
    let onInstalled: (String) -> Void

    var body: some View {
        NavigationStack {
            Form {
                if let text {
                    let module = SurgeModule.parse(text, argumentValues: arguments)
                    Section {
                        Text(module.name.isEmpty ? L("Module") : module.name)
                            .font(.headline)
                        if !module.desc.isEmpty {
                            Text(module.desc)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        if let author = module.author, !author.isEmpty {
                            LabeledContent(L("Author"), value: author)
                        }
                    }
                    if !module.arguments.isEmpty {
                        ModuleArgumentsSection(module: module, values: $arguments)
                    }
                    ModuleContentsSections(module: module, record: nil)
                    if module.isEmpty {
                        Section {
                            Text(L("Nothing in this module can run in Bridge."))
                                .foregroundStyle(Palette.bad)
                        }
                    }
                } else {
                    Section {
                        Picker(L("Source"), selection: $usingPaste) {
                            Text(L("URL")).tag(false)
                            Text(L("Paste")).tag(true)
                        }
                        .pickerStyle(.segmented)
                        if usingPaste {
                            TextEditor(text: $pasted)
                                .font(.footnote.monospaced())
                                .frame(minHeight: 160)
                                .autocorrectionDisabled()
                                .textInputAutocapitalization(.never)
                        } else {
                            TextField("https://example.com/name.sgmodule", text: $url, axis: .vertical)
                                .keyboardType(.URL)
                                .autocorrectionDisabled()
                                .textInputAutocapitalization(.never)
                            Button(L("Paste from clipboard")) {
                                url = UIPasteboard.general.string?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
                            }
                        }
                    } footer: {
                        Text(L("You will see what the module contains before anything is installed."))
                    }
                }
                if let error {
                    Section {
                        Text(error)
                            .font(.caption)
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle(text == nil ? L("Install module") : L("Review module"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(L("Cancel")) { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    if busy {
                        ProgressView()
                    } else if let text {
                        Button(L("Install")) {
                            Task { await install(text) }
                        }
                        .disabled(SurgeModule.parse(text).isEmpty)
                    } else {
                        Button(L("Next")) {
                            Task { await load() }
                        }
                        .disabled((usingPaste ? pasted : url).trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    }
                }
            }
        }
    }

    private func load() async {
        busy = true
        defer { busy = false }
        error = nil
        do {
            let loaded: String
            if usingPaste {
                try AppModel.checkLooksLikeModule(pasted)
                loaded = pasted
                sourceURL = nil
            } else {
                loaded = try await model.fetchModuleText(url)
                sourceURL = url.trimmingCharacters(in: .whitespacesAndNewlines)
            }
            var defaults: [String: String] = [:]
            for argument in SurgeModule.parse(loaded).arguments {
                defaults[argument.name] = argument.defaultValue
            }
            arguments = defaults
            text = loaded
        } catch {
            self.error = AppModel.describe(error)
        }
    }

    private func install(_ text: String) async {
        busy = true
        defer { busy = false }
        do {
            let summary = try await model.installModule(text: text, sourceURL: sourceURL, argumentValues: arguments)
            onInstalled(summary)
            dismiss()
        } catch {
            self.error = AppModel.describe(error)
        }
    }
}

/// Every script of the enabled modules, with its last run.
struct ScriptsView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        let scripts = model.moduleScripts
        List {
            Section {
                Toggle(L("Run scripts"), isOn: Binding(
                    get: { model.moduleLibrary.scriptsEnabled },
                    set: { model.setScriptsEnabled($0) }
                ))
            } footer: {
                Text(L("Scheduled and network-change scripts run inside the VPN extension, so they only run while the VPN is connected. Their requests leave on the device's own network, not through the proxy. A run started here while the VPN is off happens in the app."))
            }

            if scripts.isEmpty {
                Section {
                    Text(model.moduleLibrary.scriptsEnabled
                        ? L("No scripts. Scripts come with modules; install one from the Modules page.")
                        : L("Scripts are off."))
                        .foregroundStyle(.secondary)
                }
            } else {
                Section {
                    ForEach(scripts) { script in
                        ScriptRow(script: script)
                    }
                }
            }
        }
        .scrollContentBackground(.hidden)
        .foldClear()
        .pageBackground()
        .navigationTitle(L("Scripts"))
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { model.reloadScriptRuns() }
        .onAppear { model.reloadScriptRuns() }
    }
}

private struct ScriptRow: View {
    @EnvironmentObject private var model: AppModel
    let script: ResolvedScript
    @State private var showingLog = false

    var body: some View {
        let run = model.scriptRuns[script.key]
        let running = model.runningScripts.contains(script.key)
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(script.name)
                        .font(.body.weight(.medium))
                    Text("\(script.moduleName) · \(ScriptText.trigger(script))")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Spacer(minLength: 8)
                if running {
                    ProgressView()
                } else {
                    Button {
                        Task { await model.runScript(script, trigger: "manual") }
                    } label: {
                        Image(systemName: "play.fill")
                    }
                    .buttonStyle(.borderless)
                    .accessibilityLabel(L("Run now"))
                }
            }
            if let run {
                Text(status(run))
                    .font(.caption)
                    .foregroundStyle(run.error == nil ? Color.secondary : Palette.bad)
                if !run.logs.isEmpty {
                    DisclosureGroup(L("Log (%@)", String(run.logs.count)), isExpanded: $showingLog) {
                        Text(run.logs.joined(separator: "\n"))
                            .font(.caption2.monospaced())
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .textSelection(.enabled)
                    }
                    .font(.caption)
                }
            } else {
                Text(L("Not run yet"))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 2)
    }

    private func status(_ run: ScriptRunRecord) -> String {
        let when = ScriptText.time(run.finishedAt)
        if let error = run.error {
            return L("Failed %@: %@", when, ScriptText.error(error))
        }
        return L("Finished %@ in %@ ms", when, String(run.durationMs))
    }
}
