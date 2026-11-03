import Foundation

/// Localized UI text from this target's `Localizable.strings` (en is the base language;
/// es, zh-Hans, zh-Hant and ko ship alongside). Keys are the English text; every placeholder
/// is `%@`, so callers pass numbers as strings.
func L(_ key: String, _ args: String...) -> String {
    let format = Bundle.main.localizedString(forKey: key, value: key, table: nil)
    guard !args.isEmpty else { return format }
    return String(format: format, arguments: args.map { $0 as CVarArg })
}

/// The app's current UI language (first of the bundle's localizations that matches).
enum UILanguage {
    static var code: String { Bundle.main.preferredLocalizations.first ?? "en" }
    static var isChinese: Bool { code.hasPrefix("zh") }
}
