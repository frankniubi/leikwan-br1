import LeikwanIOSCore
import SwiftUI

/// Shadowrocket-style rule routing. iOS cannot route per app, so app presets
/// map each app to its domain/IP rule sets instead.
struct RulesView: View {
    @EnvironmentObject private var model: AppModel
    @State private var addingRule = false
    @State private var addingCountry = false

    var body: some View {
        Form {
                Section {
                    MatchOrderCard(mode: model.routing.mode, bypassChina: model.routing.bypassChina)
                        .listRowInsets(EdgeInsets())
                        .listRowBackground(Color.clear)
                }

                Section {
                    Picker(L("Mode"), selection: $model.routing.mode) {
                        Text(L("Rule")).tag(RoutingGlobalMode.rule)
                        Text(L("Global")).tag(RoutingGlobalMode.global)
                        Text(L("Direct")).tag(RoutingGlobalMode.direct)
                    }
                    .pickerStyle(.segmented)
                    Toggle(L("Mainland China sites and IPs direct"), isOn: $model.routing.bypassChina)
                } footer: {
                    Text(L("Rule: matched in the order shown; anything unmatched uses the proxy. Global: everything except the server and the lines themselves uses the proxy. Direct: everything goes direct."))
                }

                Section {
                    ForEach(AppPresetCatalog.presets, id: \.key) { preset in
                        OutcomePicker(
                            title: CatalogNames.appPreset(preset),
                            selection: presetBinding(preset.key)
                        )
                        if model.routing.appPresets[preset.key] == .tunnel && model.routingTargetBindings.count > 1 {
                            TargetPicker(selection: Binding(
                                get: { model.appPresetTarget(preset.key) },
                                set: { model.setAppPresetTarget(preset.key, nodeID: $0) }
                            ))
                        }
                    }
                } header: {
                    Text(L("Apps"))
                } footer: {
                    Text(L("iOS cannot route by app, so each app is matched by the domains and IP ranges it uses, which comes close to per-app routing."))
                }

                Section(L("Countries / regions (by IP)")) {
                    ForEach(model.routing.countries.keys.sorted(), id: \.self) { code in
                        VStack(spacing: 6) {
                            OutcomePicker(
                                title: "\(CatalogNames.country(code)) (\(code.uppercased()))",
                                selection: countryBinding(code)
                            )
                            if model.routing.countries[code] == .tunnel && model.routingTargetBindings.count > 1 {
                                TargetPicker(selection: Binding(
                                    get: { model.countryTarget(code) },
                                    set: { model.setCountryTarget(code, nodeID: $0) }
                                ))
                            }
                        }
                    }
                    .onDelete { offsets in
                        let keys = model.routing.countries.keys.sorted()
                        for index in offsets {
                            model.routing.countries.removeValue(forKey: keys[index])
                        }
                    }
                    Button {
                        addingCountry = true
                    } label: {
                        Label(L("Add country or region"), systemImage: "plus")
                    }
                }

                Section {
                    ForEach(model.routing.customRules) { rule in
                        VStack(alignment: .leading, spacing: 2) {
                            Text(rule.value)
                                .font(.body.monospaced())
                            Text("\(rule.type.rawValue) → \(Self.outcomeLabel(rule.outcome))" + (rule.outcome == .proxy ? " · " + model.routingTargetTitle(nodeID: rule.targetNodeId) : ""))
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            if rule.outcome == .proxy && model.routingTargetBindings.count > 1 {
                                TargetPicker(selection: Binding(
                                    get: { rule.targetNodeId },
                                    set: { model.setCustomRuleTarget(ruleID: rule.id, nodeID: $0) }
                                ))
                            }
                        }
                    }
                    .onDelete { model.routing.customRules.remove(atOffsets: $0) }
                    .onMove { model.routing.customRules.move(fromOffsets: $0, toOffset: $1) }
                    Button {
                        addingRule = true
                    } label: {
                        Label(L("Add rule"), systemImage: "plus")
                    }
                } header: {
                    Text(L("Custom rules"))
                } footer: {
                    Text(L("Custom rules come before app and country rules and are matched in list order."))
                }

                Section {
                    TextEditor(text: $model.routing.customBypass)
                        .font(.footnote.monospaced())
                        .frame(minHeight: 80)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                } header: {
                    Text(L("Direct list"))
                } footer: {
                    Text(L("One IP, CIDR or domain per line, sent direct just before the last step of Rule mode. To force a domain through the proxy, use a custom rule."))
                }

                Section {
                    Button(L("Apply to current connection")) {
                        Task { await model.applyToRunningTunnel() }
                    }
                    .disabled(!model.tunnel.isConnected)
                }
            }
            .scrollContentBackground(.hidden)
            .foldClear()
            .pageBackground()
            .navigationTitle(L("Routing rules"))
            .toolbar { EditButton() }
            .sheet(isPresented: $addingRule) {
                CustomRuleEditor { rule in
                    model.routing.customRules.append(rule)
                }
            }
            .sheet(isPresented: $addingCountry) {
                CountryPicker { code in
                    model.routing.countries[code.lowercased()] = .direct
                }
            }
    }

    private func presetBinding(_ key: String) -> Binding<UserTrafficOutcome?> {
        Binding(
            get: { model.routing.appPresets[key] },
            set: { model.routing.appPresets[key] = $0 }
        )
    }

    private func countryBinding(_ code: String) -> Binding<UserTrafficOutcome?> {
        Binding(
            get: { model.routing.countries[code] },
            set: { value in
                if let value {
                    model.routing.countries[code] = value
                } else {
                    model.routing.countries.removeValue(forKey: code)
                }
            }
        )
    }

    static func outcomeLabel(_ outcome: CustomRuleOutcome) -> String {
        switch outcome {
        case .proxy: return L("Proxy")
        case .direct: return L("Direct")
        case .reject: return L("Reject")
        }
    }
}

private struct OutcomePicker: View {
    let title: String
    @Binding var selection: UserTrafficOutcome?

    var body: some View {
        Picker(title, selection: $selection) {
            Text(L("Default")).tag(UserTrafficOutcome?.none)
            Text(L("Proxy")).tag(UserTrafficOutcome?.some(.tunnel))
            Text(L("Direct")).tag(UserTrafficOutcome?.some(.direct))
        }
    }
}

private struct CustomRuleEditor: View {
    @Environment(\.dismiss) private var dismiss
    @State private var type = CustomRuleType.domainSuffix
    @State private var value = ""
    @State private var outcome = CustomRuleOutcome.proxy
    let onAdd: (CustomRoutingRule) -> Void

    var body: some View {
        NavigationStack {
            Form {
                Picker(L("Type"), selection: $type) {
                    ForEach(CustomRuleType.allCases, id: \.self) { Text($0.rawValue).tag($0) }
                }
                TextField(placeholder, text: $value)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                Picker(L("Action"), selection: $outcome) {
                    ForEach(CustomRuleOutcome.allCases, id: \.self) { Text(RulesView.outcomeLabel($0)).tag($0) }
                }
                if !value.isEmpty, candidate == nil {
                    Text(L("Invalid value")).foregroundStyle(.red).font(.footnote)
                }
            }
            .navigationTitle(L("Add rule"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(L("Cancel")) { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(L("Add")) {
                        if let candidate {
                            onAdd(candidate)
                            dismiss()
                        }
                    }
                    .disabled(candidate == nil)
                }
            }
        }
    }

    private var candidate: CustomRoutingRule? {
        let rule = CustomRoutingRule(id: UUID().uuidString, type: type, value: value.trimmingCharacters(in: .whitespaces), outcome: outcome)
        return rule.normalizedValue() == nil ? nil : rule
    }

    private var placeholder: String {
        switch type {
        case .domain: return "www.example.com"
        case .domainSuffix: return "example.com"
        case .domainKeyword: return "example"
        case .ipCidr: return "1.2.3.0/24"
        case .geoip: return L("Country code, e.g. jp")
        }
    }
}

private struct CountryPicker: View {
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    let onPick: (String) -> Void

    /// Catalog search plus a match on the name in the UI language; only countries with a
    /// bundled geoip set.
    private var countries: [Country] {
        let routable = CountryCatalog.all.filter { BundledRuleSets.hasGeoip($0.code) }
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            return routable.sorted { CatalogNames.country($0.code).localizedCompare(CatalogNames.country($1.code)) == .orderedAscending }
        }
        let found = CountryCatalog.search(trimmed, limit: 60).map(\.code)
        return routable.filter { country in
            found.contains(country.code) || CatalogNames.country(country.code).localizedCaseInsensitiveContains(trimmed)
        }
    }

    var body: some View {
        NavigationStack {
            List(countries, id: \.code) { country in
                Button {
                    onPick(country.code)
                    dismiss()
                } label: {
                    HStack {
                        Text(CatalogNames.country(country.code))
                        Spacer()
                        Text(country.code.uppercased())
                            .foregroundStyle(.secondary)
                            .font(.caption.monospaced())
                    }
                }
                .foregroundStyle(.primary)
            }
            .searchable(text: $query, prompt: L("Search countries or regions"))
            .navigationTitle(L("Choose country or region"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(L("Cancel")) { dismiss() }
                }
            }
        }
    }
}

/// Explains, in the order sing-box applies them, what the rule mode does.
private struct MatchOrderCard: View {
    let mode: RoutingGlobalMode
    let bypassChina: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            CardHeader(L("Rule mode match order"), systemImage: "list.number")
            VStack(alignment: .leading, spacing: 6) {
                step(1, L("The server and the forward lines"), L("Direct (fixed)"))
                step(2, L("Custom rules"), L("In list order: proxy / direct / reject; proxy can name a binding"))
                step(3, L("Apps"), L("By each app's domains and IP ranges; can name a binding"))
                step(4, L("Countries / regions"), L("By the destination IP's country"))
                step(5, L("Mainland China sites and IPs"), bypassChina ? L("Direct (on)") : L("Off, skipped"))
                step(6, L("Direct list"), L("Direct"))
                step(7, L("All other traffic"), L("Default binding"))
            }
            if mode != .rule {
                Label(mode == .global ? L("Global mode: steps 2–6 are skipped and everything uses the proxy.") : L("Direct mode: the rules above are skipped and everything goes direct."), systemImage: "exclamationmark.circle")
                    .font(.caption)
                    .foregroundStyle(Palette.onWarningContainer)
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Palette.warningContainer, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            }
        }
        .padding(14)
        .card()
    }

    private func step(_ index: Int, _ title: String, _ detail: String) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 10) {
            Text("\(index)")
                .font(.caption.weight(.bold).monospacedDigit())
                .foregroundStyle(Palette.onPrimaryContainer)
                .frame(width: 20, height: 20)
                .background(Palette.primaryContainer, in: Circle())
            VStack(alignment: .leading, spacing: 1) {
                Text(title)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Palette.onSurface)
                Text(detail)
                    .font(.caption)
                    .foregroundStyle(Palette.onSurfaceVariant)
            }
        }
    }
}

/// "走哪个绑定" for a proxy rule: the default binding or a specific one.
private struct TargetPicker: View {
    @EnvironmentObject private var model: AppModel
    @Binding var selection: Int64?

    var body: some View {
        Picker(selection: $selection) {
            Text(L("Default binding")).tag(Int64?.none)
            ForEach(model.routingTargetBindings) { row in
                if let node = row.node {
                    Text(row.title).tag(Int64?.some(node.id))
                }
            }
        } label: {
            Label(L("Via"), systemImage: "arrow.turn.down.right")
                .font(.subheadline)
                .foregroundStyle(Palette.onSurfaceVariant)
        }
        .pickerStyle(.menu)
    }
}
