import Foundation

/// A labelled preset backed by bundled rule-sets (port of `CategoryPreset`).
public struct CategoryPreset: Equatable {
    public let key: String
    public let labelEn: String
    public let labelZh: String
    /// UI hint: insert as DIRECT by default.
    public let defaultDirect: Bool
    public let tags: [String]

    public init(key: String, labelEn: String, labelZh: String, defaultDirect: Bool, tags: [String]) {
        self.key = key
        self.labelEn = labelEn
        self.labelZh = labelZh
        self.defaultDirect = defaultDirect
        self.tags = tags
    }
}

/// Port of `routing/RoutingCategoryCatalog.kt`.
/// Fixes vs Android: `geolocation-cn` -> `geosite-geolocation-cn`; dropped `geosite-stackoverflow` and
/// `geosite-applemusic`, which do not exist in sing-geosite. Every tag is in `BundledRuleSets`.
public struct RoutingCategoryCatalog {
    public let presets: [CategoryPreset]
    private let aliases: [String: CategoryPreset]

    public init(presets: [CategoryPreset], aliases: [String: CategoryPreset] = [:]) {
        self.presets = presets
        self.aliases = aliases
    }

    public var presetKeys: [String] { return presets.map { $0.key } }

    public func preset(for key: String) -> CategoryPreset? {
        let normalized = key.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if let found = presets.first(where: { $0.key.lowercased() == normalized }) { return found }
        return aliases[normalized]
    }

    public func supports(_ key: String) -> Bool { return preset(for: key) != nil }

    public func label(for key: String, zh: Bool) -> String {
        guard let preset = preset(for: key) else { return key }
        return zh ? preset.labelZh : preset.labelEn
    }

    public func ruleSets(for matchValue: String) -> [RuleSetRef] {
        guard let preset = preset(for: matchValue) else { return [] }
        return preset.tags.map { RuleSetRef(tag: $0) }
    }

    static let streamingTags = [
        "geosite-youtube", "geosite-netflix", "geosite-disney", "geosite-primevideo", "geosite-hbo", "geosite-bilibili",
    ]

    public static let standard = RoutingCategoryCatalog(
        presets: seedPresets,
        aliases: ["video": CategoryPreset(key: "video", labelEn: "Video", labelZh: "视频", defaultDirect: false, tags: streamingTags)]
    )

    public static let seedPresets: [CategoryPreset] = [
        CategoryPreset(key: "google", labelEn: "Google", labelZh: "谷歌服务", defaultDirect: false, tags: ["geosite-google"]),
        CategoryPreset(key: "youtube", labelEn: "YouTube", labelZh: "YouTube 视频", defaultDirect: false, tags: ["geosite-youtube"]),
        CategoryPreset(key: "streaming", labelEn: "Streaming", labelZh: "流媒体", defaultDirect: false, tags: streamingTags),
        CategoryPreset(key: "ai", labelEn: "AI", labelZh: "AI 服务", defaultDirect: false, tags: ["geosite-openai", "geosite-category-ai-!cn"]),
        CategoryPreset(key: "telegram", labelEn: "Telegram", labelZh: "Telegram", defaultDirect: false, tags: ["geosite-telegram"]),
        CategoryPreset(key: "twitter", labelEn: "Twitter/X", labelZh: "Twitter/X", defaultDirect: false, tags: ["geosite-twitter"]),
        CategoryPreset(key: "meta", labelEn: "Meta", labelZh: "Meta 服务", defaultDirect: false, tags: ["geosite-facebook", "geosite-instagram", "geosite-whatsapp"]),
        CategoryPreset(key: "tiktok", labelEn: "TikTok", labelZh: "TikTok", defaultDirect: false, tags: ["geosite-tiktok"]),
        CategoryPreset(key: "microsoft", labelEn: "Microsoft", labelZh: "微软服务", defaultDirect: false, tags: ["geosite-microsoft"]),
        CategoryPreset(key: "apple", labelEn: "Apple", labelZh: "苹果服务", defaultDirect: false, tags: ["geosite-apple"]),
        CategoryPreset(key: "developer", labelEn: "Developer", labelZh: "开发者服务", defaultDirect: false, tags: ["geosite-github", "geosite-gitlab"]),
        CategoryPreset(key: "gaming", labelEn: "Gaming", labelZh: "游戏", defaultDirect: false, tags: ["geosite-steam", "geosite-epicgames", "geosite-playstation"]),
        CategoryPreset(key: "music", labelEn: "Music", labelZh: "音乐", defaultDirect: false, tags: ["geosite-spotify"]),
        CategoryPreset(key: "finance", labelEn: "Finance", labelZh: "金融", defaultDirect: false, tags: ["geosite-paypal", "geosite-stripe"]),
        CategoryPreset(key: "speedtest", labelEn: "Speedtest", labelZh: "测速", defaultDirect: false, tags: ["geosite-speedtest"]),
        CategoryPreset(
            key: "social", labelEn: "Social", labelZh: "社交", defaultDirect: false,
            tags: ["geosite-twitter", "geosite-facebook", "geosite-instagram", "geosite-tiktok", "geosite-reddit"]
        ),
        CategoryPreset(key: "china_direct", labelEn: "China direct", labelZh: "中国直连", defaultDirect: true, tags: ["geosite-cn", "geosite-geolocation-cn"]),
    ]
}
