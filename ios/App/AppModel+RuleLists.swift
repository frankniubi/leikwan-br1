import Foundation
import LeikwanIOSCore

/// Imported Surge/Clash rule lists (e.g. a blackmatrix7 `.list` URL). Each list is a sing-box
/// source rule-set file in the app group plus one `USER-LIST` custom rule that routes the
/// whole list to a single outcome.
extension AppModel {
    var importedRuleLists: ImportedRuleListStore {
        ImportedRuleListStore(directory: AppGroup.userRuleSetDirectory)
    }

    /// Custom rules the engine may compile, module rules first (as Surge orders them);
    /// `USER-LIST` rules whose file is gone are left out,
    /// because sing-box refuses to start with a rule-set it cannot read.
    var compilableCustomRules: [CustomRoutingRule] {
        let lists = importedRuleLists
        return (moduleCustomRules + routing.customRules).filter { rule in
            rule.type != .userList || lists.path(for: rule.value) != nil
        }
    }

    /// Downloads and imports a list; returns the summary line for the UI.
    func importRuleList(urlString: String, name: String, outcome: CustomRuleOutcome) async throws -> String {
        let trimmed = urlString.trimmingCharacters(in: .whitespacesAndNewlines)
        let text = try await downloadRuleList(trimmed)
        let conversion = try SurgeListConverter.parse(text)
        return try storeRuleList(name: name, sourceURL: trimmed, conversion: conversion, outcome: outcome)
    }

    /// Imports pasted list text (large pastes and logical AND/OR/NOT rules).
    func importRuleListText(_ text: String, name: String) throws -> String {
        let conversion = try SurgeListConverter.parse(text)
        return try storeRuleList(name: name, sourceURL: nil, conversion: conversion, outcome: Self.dominantOutcome(conversion))
    }

    /// Re-downloads a URL-imported list in place (same rule, same outcome).
    func refreshRuleList(ruleID: String) async throws -> String {
        guard let rule = routing.customRules.first(where: { $0.id == ruleID && $0.type == .userList }),
              let list = importedRuleLists.get(rule.value),
              let url = list.sourceURL
        else { throw AppError(L("This rule list cannot be updated")) }
        let conversion = try SurgeListConverter.parse(try await downloadRuleList(url))
        if conversion.isEmpty { return L("The list has no usable rules; kept the previous version") }
        try importedRuleLists.save(name: list.name, sourceURL: url, conversion: conversion, existingID: list.id)
        scheduleTunnelReload()
        return Self.summary(name: list.name, conversion: conversion)
    }

    /// Removes the list file of a deleted `USER-LIST` rule.
    func discardRuleListFiles(for removed: [CustomRoutingRule]) {
        let lists = importedRuleLists
        for rule in removed where rule.type == .userList {
            lists.delete(rule.value)
        }
    }

    /// Paste text that should go through the list converter rather than line-by-line rules.
    static func shouldImportAsList(_ text: String) -> Bool {
        let lines = text.split(whereSeparator: \.isNewline).compactMap { SurgeListConverter.cleanLine(String($0)) }
        if lines.count > 200 { return true }
        return lines.contains { line in
            let type = line.split(separator: ",", maxSplits: 1).first.map { $0.trimmingCharacters(in: .whitespaces).uppercased() } ?? ""
            return ["AND", "OR", "NOT", "DOMAIN-WILDCARD"].contains(type)
        }
    }

    private func storeRuleList(
        name: String,
        sourceURL: String?,
        conversion: SurgeListConverter.Conversion,
        outcome: CustomRuleOutcome
    ) throws -> String {
        if conversion.isEmpty {
            throw AppError(L("The list has no usable rules (skipped %@)", String(describing: conversion.skippedCount)))
        }
        let list = try importedRuleLists.save(name: name, sourceURL: sourceURL, conversion: conversion)
        routing.customRules.append(CustomRoutingRule(id: "list-\(list.id)", type: .userList, value: list.id, outcome: outcome))
        scheduleTunnelReload()
        return Self.summary(name: list.name, conversion: conversion)
    }

    private func downloadRuleList(_ urlString: String) async throws -> String {
        guard let url = URL(string: urlString), let scheme = url.scheme?.lowercased(), scheme == "https" || scheme == "http" else {
            throw AppError(L("The rule list URL must start with http:// or https://"))
        }
        let request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 30)
        let (data, response) = try await URLSession.shared.data(for: request)
        if let http = response as? HTTPURLResponse, !(200 ..< 300).contains(http.statusCode) {
            throw AppError(L("Rule list download returned HTTP %@", String(describing: http.statusCode)))
        }
        if data.count > SurgeListConverter.maxBytes { throw SurgeListConverter.TooLargeError() }
        return String(decoding: data, as: UTF8.self)
    }

    private static func dominantOutcome(_ conversion: SurgeListConverter.Conversion) -> CustomRuleOutcome {
        switch conversion.policies.max(by: { $0.value < $1.value })?.key {
        case "DIRECT": return .direct
        case "REJECT": return .reject
        default: return .proxy
        }
    }

    private static func summary(name: String, conversion: SurgeListConverter.Conversion) -> String {
        var text = L("Imported “%@”: %@ rules", name, String(describing: conversion.convertedCount))
        if conversion.skippedCount > 0 {
            let detail = conversion.skipped.sorted { $0.key < $1.key }.map { "\($0.key) \($0.value)" }.joined(separator: ", ")
            text += L(", skipped %@ (%@)", String(describing: conversion.skippedCount), detail)
        }
        if conversion.policies.count > 1 { text += L("; the list mixes policies, all of it uses the exit you chose") }
        if conversion.finalPolicy != nil { text += L("; FINAL was not imported, unmatched traffic follows the global mode") }
        return text
    }
}
