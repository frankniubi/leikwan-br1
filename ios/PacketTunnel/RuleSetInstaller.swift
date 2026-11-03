import Foundation

/// Copies the rule-sets shipped in this extension's bundle (`RuleSets/`) into
/// `AppGroup.ruleSetDirectory`, where the sing-box config the app builds points. The copy is
/// redone only when the bundled `SHA256SUMS` differs from the installed one, so an app update
/// with new rule data replaces it on the next tunnel start.
enum RuleSetInstaller {
    static let manifestName = "SHA256SUMS"

    static func ensureInstalled() throws {
        guard let source = Bundle.main.url(forResource: "RuleSets", withExtension: nil) else {
            throw RuntimeHostError("rule-sets missing from the tunnel bundle")
        }
        let destination = AppGroup.ruleSetDirectory
        let manager = FileManager.default
        let bundledManifest = try Data(contentsOf: source.appendingPathComponent(manifestName))
        if let installed = try? Data(contentsOf: destination.appendingPathComponent(manifestName)), installed == bundledManifest {
            return
        }
        // Stale files first, the manifest last: an interrupted copy is redone on the next start.
        for name in (try? manager.contentsOfDirectory(atPath: destination.path)) ?? [] {
            try? manager.removeItem(at: destination.appendingPathComponent(name))
        }
        for name in try manager.contentsOfDirectory(atPath: source.path) where name.hasSuffix(".srs") {
            try manager.copyItem(at: source.appendingPathComponent(name), to: destination.appendingPathComponent(name))
        }
        try bundledManifest.write(to: destination.appendingPathComponent(manifestName), options: .atomic)
        TunnelLog.shared.write("installed bundled rule-sets")
    }
}
