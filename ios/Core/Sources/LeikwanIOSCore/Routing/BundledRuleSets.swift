import Foundation

/// Rule-sets shipped inside the app (`ios/RuleSets/<tag>.srs`, copied into the app group by the
/// packet tunnel). Nothing is downloaded at run time: sing-box reads them as `local` rule-sets,
/// and a rule whose set is not bundled is dropped at compile time.
///
/// `ios/RuleSets/sources.tsv` lists the same tags with the upstream file each one is refreshed
/// from by `ios/scripts/update-rule-sets.sh`; a unit test keeps both lists equal.
public enum BundledRuleSets {
    /// ISO codes of `CountryCatalog` with no sing-geoip set (no IP space of their own).
    public static let countriesWithoutGeoip: Set<String> = ["bv", "cc", "cx", "eh", "gs", "hm", "pn", "sh", "sj", "tf", "um"]

    /// Sets the China bypass uses.
    public static let chinaTags = ["geoip-cn", "geosite-cn"]

    public static let allTags: Set<String> = {
        var tags = Set(chinaTags)
        for preset in RoutingCategoryCatalog.seedPresets { tags.formUnion(preset.tags) }
        for preset in AppPresetCatalog.presets { tags.formUnion(preset.tags) }
        for country in CountryCatalog.all where hasGeoip(country.code) {
            tags.insert(RoutingPolicyEngine.geoipTag(country.code))
        }
        return tags
    }()

    public static func contains(_ tag: String) -> Bool {
        return allTags.contains(tag)
    }

    /// Whether a country rule / GEOIP rule for `code` can be routed.
    public static func hasGeoip(_ code: String) -> Bool {
        let normalized = code.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard normalized.count == 2, normalized.allSatisfy({ $0 >= "a" && $0 <= "z" }) else { return false }
        return !countriesWithoutGeoip.contains(normalized) && catalogCodes.contains(normalized)
    }

    private static let catalogCodes = Set(CountryCatalog.all.map { $0.code.lowercased() })

    public static func fileName(_ tag: String) -> String {
        return tag + ".srs"
    }

    /// sing-box `route.rule_set` entry reading `<directory>/<tag>.srs`.
    public static func singboxEntry(tag: String, directory: String) -> [String: Any] {
        let base = directory.hasSuffix("/") ? String(directory.dropLast()) : directory
        let path = base.isEmpty ? fileName(tag) : base + "/" + fileName(tag)
        return [
            "tag": tag,
            "type": "local",
            "format": "binary",
            "path": path,
        ]
    }
}
