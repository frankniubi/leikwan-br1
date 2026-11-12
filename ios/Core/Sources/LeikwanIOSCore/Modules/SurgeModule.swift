import Foundation

/// A parsed Surge module (`.sgmodule`): the parts this app can run.
///
/// Supported: `[Rule]` lines (prepended to the user's custom rules, each with its own policy),
/// `[Script]` entries of type `cron`, `event` (`network-changed`) and `generic`, and `[Panel]`
/// entries. Everything that needs an HTTP engine or TLS interception (`[MITM]`, `[URL Rewrite]`,
/// `[Header Rewrite]`, `[Map Local]`, `http-request` / `http-response` scripts …) is counted in
/// `unsupported` and never applied, so the UI can say exactly what was left out.
public struct SurgeModule: Equatable {
    public struct Argument: Equatable {
        public let name: String
        public let defaultValue: String
    }

    public struct Rule: Equatable {
        public enum Match: Equatable {
            /// A rule this app's route can express directly.
            case inline(type: CustomRuleType, value: String)
            /// `RULE-SET,<url>` / `DOMAIN-SET,<url>`: a list to download.
            case remoteList(url: String, domainSet: Bool)
        }

        public let match: Match
        public let outcome: CustomRuleOutcome
    }

    public struct Script: Equatable {
        public enum Kind: String, Codable {
            case cron
            case event
            case generic
        }

        public let name: String
        public let kind: Kind
        public let cronExpression: String?
        public let eventName: String?
        public let scriptPath: String
        public let argument: String?
        public let timeoutSeconds: Int
    }

    public struct Panel: Equatable {
        public let name: String
        public let scriptName: String?
        public let title: String
        public let content: String
        /// Seconds between automatic refreshes; nil = only on tap.
        public let updateInterval: Int?
    }

    public var name: String = ""
    public var desc: String = ""
    public var author: String?
    public var category: String?
    public var arguments: [Argument] = []
    public var argumentsDescription: String?
    public var rules: [Rule] = []
    public var scripts: [Script] = []
    public var panels: [Panel] = []
    /// What the module asks for that this app does not do: label -> number of lines.
    public var unsupported: [String: Int] = [:]

    public static let maxBytes = 512 * 1024
    public static let defaultScriptTimeout = 10
    public static let maxScriptTimeout = 120
    /// The only event this app raises.
    public static let networkChangedEvent = "network-changed"

    public var isEmpty: Bool { rules.isEmpty && scripts.isEmpty && panels.isEmpty }

    /// Script and list URLs the module needs on disk before it can run.
    public var scriptURLs: [String] {
        var seen = Set<String>()
        return scripts.map { $0.scriptPath }.filter { seen.insert($0).inserted }
    }

    public var remoteLists: [(url: String, domainSet: Bool)] {
        var seen = Set<String>()
        var result: [(url: String, domainSet: Bool)] = []
        for rule in rules {
            if case let .remoteList(url, domainSet) = rule.match, seen.insert(url).inserted {
                result.append((url, domainSet))
            }
        }
        return result
    }

    /// Parses module text. `argumentValues` override the `#!arguments` defaults; a value is
    /// substituted for every `{{{name}}}` in the body before the sections are read.
    public static func parse(_ text: String, argumentValues: [String: String] = [:]) -> SurgeModule {
        var module = SurgeModule()
        var body = text
        if body.hasPrefix("\u{FEFF}") { body.removeFirst() }
        let rawLines = body.split(omittingEmptySubsequences: false, whereSeparator: \.isNewline).map(String.init)

        // Pass 1: metadata.
        for raw in rawLines {
            let line = raw.trimmingCharacters(in: .whitespaces)
            guard line.hasPrefix("#!"), let equals = line.firstIndex(of: "=") else { continue }
            let key = line[line.index(line.startIndex, offsetBy: 2)..<equals].trimmingCharacters(in: .whitespaces).lowercased()
            let value = String(line[line.index(after: equals)...]).trimmingCharacters(in: .whitespaces)
            switch key {
            case "name": module.name = value
            case "desc": module.desc = value
            case "author": module.author = value
            case "category": module.category = value
            case "arguments": module.arguments = parseArguments(value)
            case "arguments-desc": module.argumentsDescription = value.replacingOccurrences(of: "\\n", with: "\n")
            default: break
            }
        }

        // Pass 2: sections, with the arguments substituted.
        var substitutions: [(String, String)] = []
        for argument in module.arguments {
            substitutions.append(("{{{\(argument.name)}}}", argumentValues[argument.name] ?? argument.defaultValue))
        }
        var section = ""
        for raw in rawLines {
            var line = raw.trimmingCharacters(in: .whitespaces)
            if line.isEmpty || line.hasPrefix("#") || line.hasPrefix(";") || line.hasPrefix("//") { continue }
            if line.hasPrefix("["), line.hasSuffix("]") {
                section = String(line.dropFirst().dropLast()).trimmingCharacters(in: .whitespaces)
                continue
            }
            for (placeholder, value) in substitutions {
                line = line.replacingOccurrences(of: placeholder, with: value)
            }
            switch section.lowercased() {
            case "rule": module.addRule(line)
            case "script": module.addScript(line)
            case "panel": module.addPanel(line)
            case "": break
            default: module.unsupported[section, default: 0] += 1
            }
        }
        return module
    }

    // MARK: Sections

    private mutating func addRule(_ raw: String) {
        guard let line = SurgeListConverter.cleanLine(raw) else { return }
        let parts = SurgeListConverter.splitTopLevel(line).map { $0.trimmingCharacters(in: .whitespaces) }
        let type = parts[0].uppercased()
        if (type == "RULE-SET" || type == "DOMAIN-SET"), parts.count >= 2, SurgeModule.isHTTPSURL(parts[1]) {
            let outcome: CustomRuleOutcome = parts.count >= 3 ? CustomRoutingRule.clashPolicyOutcome(parts[2]) : .proxy
            rules.append(Rule(match: .remoteList(url: parts[1], domainSet: type == "DOMAIN-SET"), outcome: outcome))
            return
        }
        guard let parsed = CustomRoutingRule.parseLine(line, id: "") else {
            unsupported["Rule \(type)", default: 0] += 1
            return
        }
        rules.append(Rule(match: .inline(type: parsed.type, value: parsed.value), outcome: parsed.outcome))
    }

    private mutating func addScript(_ line: String) {
        guard let entry = SurgeModule.parseEntry(line), let type = entry.attributes["type"]?.lowercased() else {
            unsupported["Script", default: 0] += 1
            return
        }
        let (name, attributes) = (entry.name, entry.attributes)
        let timeout = min(max(attributes["timeout"].flatMap { Int($0) } ?? SurgeModule.defaultScriptTimeout, 1), SurgeModule.maxScriptTimeout)
        guard let path = attributes["script-path"], SurgeModule.isHTTPSURL(path) else {
            unsupported["Script \(type)", default: 0] += 1
            return
        }
        let argument = attributes["argument"]
        switch type {
        case "cron":
            guard let expression = attributes["cronexp"], CronExpression(expression) != nil else {
                unsupported["Script cron", default: 0] += 1
                return
            }
            scripts.append(Script(name: name, kind: .cron, cronExpression: expression, eventName: nil, scriptPath: path, argument: argument, timeoutSeconds: timeout))
        case "event":
            let event = (attributes["event-name"] ?? "").lowercased()
            guard event == SurgeModule.networkChangedEvent else {
                unsupported["Script event \(event)", default: 0] += 1
                return
            }
            scripts.append(Script(name: name, kind: .event, cronExpression: nil, eventName: event, scriptPath: path, argument: argument, timeoutSeconds: timeout))
        case "generic":
            scripts.append(Script(name: name, kind: .generic, cronExpression: nil, eventName: nil, scriptPath: path, argument: argument, timeoutSeconds: timeout))
        default:
            unsupported["Script \(type)", default: 0] += 1
        }
    }

    private mutating func addPanel(_ line: String) {
        guard let entry = SurgeModule.parseEntry(line) else {
            unsupported["Panel", default: 0] += 1
            return
        }
        let (name, attributes) = (entry.name, entry.attributes)
        panels.append(Panel(
            name: name,
            scriptName: attributes["script-name"],
            title: attributes["title"] ?? name,
            content: attributes["content"] ?? "",
            updateInterval: attributes["update-interval"].flatMap { Int($0) }.flatMap { $0 > 0 ? $0 : nil }
        ))
    }

    // MARK: Syntax

    /// `name = key=value,key="va,lue",…` -> (name, attributes). Keys are lowercased.
    static func parseEntry(_ line: String) -> (name: String, attributes: [String: String])? {
        guard let equals = line.firstIndex(of: "=") else { return nil }
        let name = line[..<equals].trimmingCharacters(in: .whitespaces)
        // The old positional form (`http-response ^pattern script-path=…`) has no `type=`
        // attribute, so the caller rejects it.
        guard !name.isEmpty else { return nil }
        let attributes = parseAttributes(String(line[line.index(after: equals)...]))
        return attributes.isEmpty ? nil : (name, attributes)
    }

    /// Splits on commas outside double quotes. A piece without `=` belongs to the previous
    /// value (an unquoted value that itself contains a comma).
    static func parseAttributes(_ text: String) -> [String: String] {
        var pieces: [String] = []
        var current = ""
        var quoted = false
        for ch in text {
            if ch == "\"" {
                quoted.toggle()
                current.append(ch)
            } else if ch == ",", !quoted {
                pieces.append(current)
                current = ""
            } else {
                current.append(ch)
            }
        }
        pieces.append(current)

        var result: [String: String] = [:]
        var lastKey: String?
        for piece in pieces {
            let trimmed = piece.trimmingCharacters(in: .whitespaces)
            if let equals = trimmed.firstIndex(of: "="), SurgeModule.isAttributeKey(trimmed[..<equals]) {
                let key = trimmed[..<equals].trimmingCharacters(in: .whitespaces).lowercased()
                result[key] = unquote(String(trimmed[trimmed.index(after: equals)...]).trimmingCharacters(in: .whitespaces))
                lastKey = key
            } else if let key = lastKey, let previous = result[key] {
                result[key] = previous + "," + piece
            }
        }
        return result
    }

    private static func isAttributeKey(_ text: Substring) -> Bool {
        let key = text.trimmingCharacters(in: .whitespaces)
        return !key.isEmpty && key.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "-" || $0 == "_") }
    }

    /// `tokens:"",mode:auto` -> arguments in order. Values may be double-quoted.
    static func parseArguments(_ text: String) -> [Argument] {
        var result: [Argument] = []
        var pieces: [String] = []
        var current = ""
        var quoted = false
        for ch in text {
            if ch == "\"" {
                quoted.toggle()
                current.append(ch)
            } else if ch == ",", !quoted {
                pieces.append(current)
                current = ""
            } else {
                current.append(ch)
            }
        }
        pieces.append(current)
        for piece in pieces {
            let trimmed = piece.trimmingCharacters(in: .whitespaces)
            guard !trimmed.isEmpty else { continue }
            let name: String
            let value: String
            if let colon = trimmed.firstIndex(of: ":") {
                name = trimmed[..<colon].trimmingCharacters(in: .whitespaces)
                value = unquote(String(trimmed[trimmed.index(after: colon)...]).trimmingCharacters(in: .whitespaces))
            } else {
                name = trimmed
                value = ""
            }
            if !name.isEmpty, !result.contains(where: { $0.name == name }) {
                result.append(Argument(name: name, defaultValue: value))
            }
        }
        return result
    }

    private static func unquote(_ value: String) -> String {
        if value.count >= 2, value.hasPrefix("\""), value.hasSuffix("\"") {
            return String(value.dropFirst().dropLast())
        }
        return value
    }

    /// Code and lists are only ever fetched over TLS.
    public static func isHTTPSURL(_ text: String) -> Bool {
        guard let url = URL(string: text), url.scheme?.lowercased() == "https", let host = url.host else { return false }
        return !host.isEmpty
    }
}
