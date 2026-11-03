import Foundation

/// Localized text from the package's `Localizable.strings` (see `Resources/`). Keys are the
/// English text; every placeholder is `%@`.
func L(_ key: String, _ args: String...) -> String {
    let format = Bundle.module.localizedString(forKey: key, value: key, table: nil)
    guard !args.isEmpty else { return format }
    return String(format: format, arguments: args.map { $0 as CVarArg })
}

/// The package's resource bundle (localization tables), for tests and diagnostics.
public enum CoreResources {
    public static var bundle: Bundle { Bundle.module }
}
