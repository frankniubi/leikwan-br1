import Foundation
import LeikwanIOSCore

/// Display names of catalog entries in the UI language.
enum CatalogNames {
    /// Country/region name from the system (every UI language), else the catalog's English name.
    static func country(_ code: String) -> String {
        let upper = code.uppercased()
        let locale = Locale(identifier: UILanguage.code)
        if let name = locale.localizedString(forRegionCode: upper), !name.isEmpty, name != upper {
            return name
        }
        return CountryCatalog.name(for: code, zh: false)
    }

    /// App preset label: the Chinese label for zh-Hans/zh-Hant, otherwise the English one
    /// (the labels are mostly brand names).
    static func appPreset(_ preset: AppPreset) -> String {
        return UILanguage.isChinese ? preset.labelZh : preset.labelEn
    }
}
