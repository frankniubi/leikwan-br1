import Foundation

/// Pre-built Clash-compatible rule presets for quick import into custom rules.
public enum ClashPresets {
    /// Preset (name, lines of rules)
    public static let all: [(name: String, lines: String)] = [
        (
            name: "AI Services",
            lines: """
            GEOSITE,openai,PROXY
            GEOSITE,google,PROXY
            """
        ),
        (
            name: "Streaming",
            lines: """
            GEOSITE,youtube,PROXY
            GEOSITE,netflix,PROXY
            GEOSITE,tiktok,PROXY
            GEOSITE,disney,PROXY
            """
        ),
        (
            name: "Telegram",
            lines: """
            GEOSITE,telegram,PROXY
            """
        ),
        (
            name: "China Direct",
            lines: """
            GEOSITE,cn,DIRECT
            GEOIP,CN,DIRECT
            """
        ),
    ]
}
