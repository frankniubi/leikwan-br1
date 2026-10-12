import Foundation

/// An app-centric routing preset ("App preset pack"). iOS cannot route per app, so each entry is a
/// bundle of domain (geosite) and, where published, IP (geoip) rule-sets for that app's services.
public struct AppPreset: Equatable {
    public let key: String
    public let labelEn: String
    public let labelZh: String
    /// Suggested outcome when the user adds the preset.
    public let defaultOutcome: UserTrafficOutcome
    public let ruleSets: [RuleSetRef]

    public init(key: String, labelEn: String, labelZh: String, defaultOutcome: UserTrafficOutcome, ruleSets: [RuleSetRef]) {
        self.key = key
        self.labelEn = labelEn
        self.labelZh = labelZh
        self.defaultOutcome = defaultOutcome
        self.ruleSets = ruleSets
    }

    public var tags: [String] { return ruleSets.map { $0.tag } }
}

/// Curated app preset pack. Geosite refs use the SagerNet sing-geosite URL pattern (same as Android).
/// SagerNet/sing-geoip only publishes country sets, so app IP sets (telegram, google, netflix, facebook,
/// twitter) come from MetaCubeX/meta-rules-dat (`sing` branch); all URLs were checked to exist (2026-10-12).
public enum AppPresetCatalog {
    public static let metaGeoipBase = "https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/sing/geo/geoip/"

    static func site(_ name: String) -> RuleSetRef {
        let tag = "geosite-" + name
        return RuleSetRef(tag: tag, url: RoutingCategoryCatalog.geositeUrl(tag))
    }

    static func appIp(_ name: String) -> RuleSetRef {
        return RuleSetRef(tag: "geoip-" + name, url: metaGeoipBase + name + ".srs")
    }

    public static let presets: [AppPreset] = [
        AppPreset(key: "telegram", labelEn: "Telegram", labelZh: "Telegram", defaultOutcome: .tunnel,
                  ruleSets: [site("telegram"), appIp("telegram")]),
        AppPreset(key: "youtube", labelEn: "YouTube", labelZh: "YouTube", defaultOutcome: .tunnel,
                  ruleSets: [site("youtube")]),
        AppPreset(key: "netflix", labelEn: "Netflix", labelZh: "Netflix", defaultOutcome: .tunnel,
                  ruleSets: [site("netflix"), appIp("netflix")]),
        AppPreset(key: "disney", labelEn: "Disney+", labelZh: "Disney+", defaultOutcome: .tunnel,
                  ruleSets: [site("disney")]),
        AppPreset(key: "openai", labelEn: "ChatGPT / OpenAI", labelZh: "ChatGPT / OpenAI", defaultOutcome: .tunnel,
                  ruleSets: [site("openai")]),
        AppPreset(key: "anthropic", labelEn: "Claude / Anthropic", labelZh: "Claude / Anthropic", defaultOutcome: .tunnel,
                  ruleSets: [site("anthropic")]),
        AppPreset(key: "google", labelEn: "Gemini / Google", labelZh: "Gemini / 谷歌", defaultOutcome: .tunnel,
                  ruleSets: [site("google"), site("google-gemini"), appIp("google")]),
        AppPreset(key: "twitter", labelEn: "X / Twitter", labelZh: "X / Twitter", defaultOutcome: .tunnel,
                  ruleSets: [site("twitter"), site("x"), appIp("twitter")]),
        AppPreset(key: "meta", labelEn: "Instagram / Facebook / WhatsApp", labelZh: "Instagram / Facebook / WhatsApp", defaultOutcome: .tunnel,
                  ruleSets: [site("facebook"), site("instagram"), site("whatsapp"), site("threads"), appIp("facebook")]),
        AppPreset(key: "tiktok", labelEn: "TikTok", labelZh: "TikTok", defaultOutcome: .tunnel,
                  ruleSets: [site("tiktok")]),
        AppPreset(key: "spotify", labelEn: "Spotify", labelZh: "Spotify", defaultOutcome: .tunnel,
                  ruleSets: [site("spotify")]),
        AppPreset(key: "steam", labelEn: "Steam", labelZh: "Steam", defaultOutcome: .tunnel,
                  ruleSets: [site("steam")]),
        AppPreset(key: "github", labelEn: "GitHub", labelZh: "GitHub", defaultOutcome: .tunnel,
                  ruleSets: [site("github")]),
        AppPreset(key: "apple", labelEn: "Apple services", labelZh: "苹果服务", defaultOutcome: .tunnel,
                  ruleSets: [site("apple"), site("icloud")]),
        AppPreset(key: "microsoft", labelEn: "Microsoft", labelZh: "微软服务", defaultOutcome: .tunnel,
                  ruleSets: [site("microsoft"), site("bing"), site("onedrive")]),
        AppPreset(key: "bilibili_iqiyi", labelEn: "Bilibili / iQIYI", labelZh: "哔哩哔哩 / 爱奇艺", defaultOutcome: .direct,
                  ruleSets: [site("bilibili"), site("iqiyi")]),
        AppPreset(key: "tencent", labelEn: "WeChat / QQ (Tencent)", labelZh: "微信 / QQ（腾讯）", defaultOutcome: .direct,
                  ruleSets: [site("tencent")]),
    ]

    public static func preset(for key: String) -> AppPreset? {
        let normalized = key.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return presets.first { $0.key == normalized }
    }

    public static func supports(_ key: String) -> Bool { return preset(for: key) != nil }

    public static func label(for key: String, zh: Bool) -> String {
        guard let preset = preset(for: key) else { return key }
        return zh ? preset.labelZh : preset.labelEn
    }
}
