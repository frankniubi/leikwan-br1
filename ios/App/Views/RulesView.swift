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
                    Picker("模式", selection: $model.routing.mode) {
                        Text("规则").tag(RoutingGlobalMode.rule)
                        Text("全局").tag(RoutingGlobalMode.global)
                        Text("直连").tag(RoutingGlobalMode.direct)
                    }
                    .pickerStyle(.segmented)
                    Toggle("中国大陆网站和 IP 直连", isOn: $model.routing.bypassChina)
                } footer: {
                    Text("规则：按上面的顺序逐条匹配，都没命中就走代理。全局：除面板和线路本身外全部走代理。直连：全部直连，代理只在被选中时可用。")
                }

                Section {
                    ForEach(AppPresetCatalog.presets, id: \.key) { preset in
                        OutcomePicker(
                            title: preset.labelZh,
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
                    Text("应用分流")
                } footer: {
                    Text("iOS 不允许按 App 分流，这里按每个 App 使用的域名和 IP 段匹配，效果接近按 App 分流。")
                }

                Section("国家 / 地区（按 IP）") {
                    ForEach(model.routing.countries.keys.sorted(), id: \.self) { code in
                        VStack(spacing: 6) {
                            OutcomePicker(
                                title: "\(CountryCatalog.name(for: code, zh: true)) (\(code.uppercased()))",
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
                        Label("添加国家或地区", systemImage: "plus")
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
                        Label("添加规则", systemImage: "plus")
                    }
                } header: {
                    Text("自定义规则")
                } footer: {
                    Text("自定义规则优先于应用分流和国家规则，按列表顺序匹配。")
                }

                Section {
                    TextEditor(text: $model.routing.customBypass)
                        .font(.footnote.monospaced())
                        .frame(minHeight: 80)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                } header: {
                    Text("直连列表")
                } footer: {
                    Text("每行一个 IP、CIDR 或域名，在规则模式最后一步之前匹配为直连。要强制某个域名走代理，请用自定义规则。")
                }

                Section {
                    Button("应用到当前连接") {
                        Task { await model.applyToRunningTunnel() }
                    }
                    .disabled(!model.tunnel.isConnected)
                }
            }
            .scrollContentBackground(.hidden)
            .pageBackground()
            .navigationTitle("分流规则")
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
        case .proxy: return "代理"
        case .direct: return "直连"
        case .reject: return "拒绝"
        }
    }
}

private struct OutcomePicker: View {
    let title: String
    @Binding var selection: UserTrafficOutcome?

    var body: some View {
        Picker(title, selection: $selection) {
            Text("默认").tag(UserTrafficOutcome?.none)
            Text("代理").tag(UserTrafficOutcome?.some(.tunnel))
            Text("直连").tag(UserTrafficOutcome?.some(.direct))
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
                Picker("类型", selection: $type) {
                    ForEach(CustomRuleType.allCases, id: \.self) { Text($0.rawValue).tag($0) }
                }
                TextField(placeholder, text: $value)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                Picker("策略", selection: $outcome) {
                    ForEach(CustomRuleOutcome.allCases, id: \.self) { Text(RulesView.outcomeLabel($0)).tag($0) }
                }
                if !value.isEmpty, candidate == nil {
                    Text("格式不正确").foregroundStyle(.red).font(.footnote)
                }
            }
            .navigationTitle("添加规则")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("添加") {
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
        case .geoip: return "国家代码，如 jp"
        }
    }
}

private struct CountryPicker: View {
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    let onPick: (String) -> Void

    var body: some View {
        NavigationStack {
            List(query.isEmpty ? CountryCatalog.all : CountryCatalog.search(query, limit: 60), id: \.code) { country in
                Button {
                    onPick(country.code)
                    dismiss()
                } label: {
                    HStack {
                        Text(country.nameZh)
                        Spacer()
                        Text(country.code.uppercased())
                            .foregroundStyle(.secondary)
                            .font(.caption.monospaced())
                    }
                }
                .foregroundStyle(.primary)
            }
            .searchable(text: $query, prompt: "搜索国家或地区")
            .navigationTitle("选择国家或地区")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
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
            CardHeader("规则模式的匹配顺序", systemImage: "list.number")
            VStack(alignment: .leading, spacing: 6) {
                step(1, "面板和转发线路本身", "直连（不可修改）")
                step(2, "自定义规则", "按列表顺序，代理 / 直连 / 拒绝；代理可指定绑定")
                step(3, "应用分流", "按每个 App 的域名和 IP 段；可指定绑定")
                step(4, "国家 / 地区", "按目标 IP 所在国家")
                step(5, "中国大陆网站和 IP", bypassChina ? "直连（已开启）" : "未开启，跳过")
                step(6, "直连列表", "直连")
                step(7, "其余所有流量", "走默认绑定")
            }
            if mode != .rule {
                Label(mode == .global ? "当前是全局模式：以上第 2–6 步不生效，全部走代理。" : "当前是直连模式：以上规则不生效，全部直连。", systemImage: "exclamationmark.circle")
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
            Text("默认绑定").tag(Int64?.none)
            ForEach(model.routingTargetBindings) { row in
                if let node = row.node {
                    Text(row.title).tag(Int64?.some(node.id))
                }
            }
        } label: {
            Label("走", systemImage: "arrow.turn.down.right")
                .font(.subheadline)
                .foregroundStyle(Palette.onSurfaceVariant)
        }
        .pickerStyle(.menu)
    }
}
