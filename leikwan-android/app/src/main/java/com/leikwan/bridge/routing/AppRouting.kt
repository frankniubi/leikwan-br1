package com.leikwan.bridge.routing

import com.google.gson.Gson
import com.google.gson.annotations.SerializedName

/** Which apps the VPN captures at all (Android VpnService per-app scope). */
enum class AppVpnScope {
    /** Every app uses the VPN except those set to [AppPolicy.BYPASS]. */
    ALL_APPS,

    /** Only apps given an explicit in-VPN policy use the VPN; every other app bypasses it. */
    SELECTED_APPS,
}

/** What one app's traffic does. */
enum class AppPolicy {
    /** Inside the VPN, routed by the normal rules (country, category, default). */
    FOLLOW,

    /** Inside the VPN, always through the tunnel (optionally pinned to one node). */
    TUNNEL,

    /** Inside the VPN, always direct. */
    DIRECT,

    /** Not captured by the VPN at all. */
    BYPASS,
}

/** One configured app. Apps without an entry use the scope's default. */
data class AppPolicyEntry(
    @SerializedName("package") val packageName: String,
    @SerializedName("policy") val policy: AppPolicy,
    /** For [AppPolicy.TUNNEL]: a proxy node id to pin, or null for the default tunnel. */
    @SerializedName("node_id") val targetNodeId: Long? = null,
)

/**
 * A user-defined app group: its apps ride one line (forward) and one of the user's nodes, so
 * group A can leave through one node while group B leaves through another.
 */
data class AppGroup(
    @SerializedName("id") val id: String,
    @SerializedName("name") val name: String,
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("node_id") val nodeId: Long,
    @SerializedName("packages") val packages: List<String> = emptyList(),
)

/** Persisted per-app routing configuration. */
data class AppRoutingConfig(
    val scope: AppVpnScope = AppVpnScope.ALL_APPS,
    val entries: List<AppPolicyEntry> = emptyList(),
    /** An app is in at most one group, and a grouped app has no [entries] row. */
    val groups: List<AppGroup> = emptyList(),
) {
    /** The effective policy for [packageName], including the scope default. */
    fun policyFor(packageName: String): AppPolicy =
        entries.firstOrNull { it.packageName == packageName }?.policy
            ?: groupFor(packageName)?.let { AppPolicy.TUNNEL }
            ?: if (scope == AppVpnScope.ALL_APPS) AppPolicy.FOLLOW else AppPolicy.BYPASS

    fun entryFor(packageName: String): AppPolicyEntry? = entries.firstOrNull { it.packageName == packageName }

    fun groupFor(packageName: String): AppGroup? = groups.firstOrNull { packageName in it.packages }

    /** Sets (or, when equal to the scope default, removes) the policy of one app; it leaves any group. */
    fun with(packageName: String, policy: AppPolicy, targetNodeId: Long? = null): AppRoutingConfig {
        val defaultPolicy = if (scope == AppVpnScope.ALL_APPS) AppPolicy.FOLLOW else AppPolicy.BYPASS
        val rest = entries.filterNot { it.packageName == packageName }
        val next = if (policy == defaultPolicy) rest else rest + AppPolicyEntry(packageName, policy, targetNodeId.takeIf { policy == AppPolicy.TUNNEL })
        return copy(entries = next, groups = groups.map { it.copy(packages = it.packages - packageName) })
    }

    /** Moves one app into [groupId], out of any other group and its own policy row. */
    fun withGroupMember(packageName: String, groupId: String): AppRoutingConfig {
        if (groups.none { it.id == groupId }) return this
        return copy(
            entries = entries.filterNot { it.packageName == packageName },
            groups = groups.map { group ->
                val others = group.packages - packageName
                group.copy(packages = if (group.id == groupId) others + packageName else others)
            },
        )
    }

    /** Adds or replaces [group]; its apps leave other groups and their own policy rows. */
    fun withGroup(group: AppGroup): AppRoutingConfig {
        val packages = group.packages.distinct()
        val cleaned = group.copy(name = group.name.trim(), packages = packages)
        val others = groups.filterNot { it.id == group.id }.map { it.copy(packages = it.packages - packages.toSet()) }
        val index = groups.indexOfFirst { it.id == group.id }
        val next = if (index < 0) others + cleaned else others.toMutableList().apply { add(index, cleaned) }
        return copy(entries = entries.filterNot { it.packageName in packages }, groups = next)
    }

    fun withoutGroup(groupId: String): AppRoutingConfig = copy(groups = groups.filterNot { it.id == groupId })

    /** Packages Android must route into the VPN; empty means "all apps". */
    fun includePackages(ownerPackage: String): List<String> = if (scope == AppVpnScope.SELECTED_APPS) {
        (entries.filter { it.policy != AppPolicy.BYPASS }.map { it.packageName } + groups.flatMap { it.packages })
            .filter { it != ownerPackage }
            .distinct()
    } else {
        emptyList()
    }

    /** Packages Android must keep out of the VPN (only meaningful for [AppVpnScope.ALL_APPS]). */
    fun excludePackages(ownerPackage: String): List<String> = if (scope == AppVpnScope.ALL_APPS) {
        entries.filter { it.policy == AppPolicy.BYPASS && it.packageName != ownerPackage }.map { it.packageName }.distinct()
    } else {
        emptyList()
    }

    /** In-VPN app rules for sing-box (`package_name`), most specific first. */
    fun routingRules(): List<AppRoutingRule> = entries
        .filter { it.policy == AppPolicy.TUNNEL || it.policy == AppPolicy.DIRECT }
        .map { entry ->
            AppRoutingRule(
                packageName = entry.packageName,
                outcome = if (entry.policy == AppPolicy.DIRECT) UserTrafficOutcome.DIRECT else UserTrafficOutcome.TUNNEL,
                targetNodeId = entry.targetNodeId.takeIf { entry.policy == AppPolicy.TUNNEL },
            )
        } + groups.flatMap { group ->
            group.packages
                .filter { packageName -> entries.none { it.packageName == packageName } }
                .map { AppRoutingRule(packageName = it, outcome = UserTrafficOutcome.TUNNEL, targetNodeId = group.nodeId) }
        }

    fun toJson(): String = gson.toJson(Stored(scope.name, entries, groups))

    private data class Stored(
        @SerializedName("scope") val scope: String,
        @SerializedName("entries") val entries: List<AppPolicyEntry>,
        @SerializedName("groups") val groups: List<AppGroup>? = null,
    )

    companion object {
        private val gson = Gson()
        private val PACKAGE_NAME = Regex("^[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z0-9_]+)+$")

        fun fromJson(json: String?): AppRoutingConfig {
            if (json.isNullOrBlank()) return AppRoutingConfig()
            val stored = runCatching { gson.fromJson(json, Stored::class.java) }.getOrNull() ?: return AppRoutingConfig()
            val scope = runCatching { AppVpnScope.valueOf(stored.scope) }.getOrDefault(AppVpnScope.ALL_APPS)
            // Gson can leave non-null Kotlin fields null on malformed input; keep only sane rows.
            @Suppress("SENSELESS_COMPARISON")
            val entries = stored.entries.orEmpty().filter { entry ->
                entry != null && entry.packageName != null && entry.policy != null &&
                    PACKAGE_NAME.matches(entry.packageName)
            }.distinctBy { it.packageName }
            val claimed = entries.mapTo(hashSetOf()) { it.packageName }
            @Suppress("SENSELESS_COMPARISON", "USELESS_ELVIS")
            val groups = stored.groups.orEmpty().filter { group ->
                group != null && group.id != null && group.name != null && group.forwardId > 0L && group.nodeId > 0L
            }.distinctBy { it.id }.map { group ->
                // An app belongs to one place only: its own policy row, or the first group listing it.
                val packages = (group.packages ?: emptyList()).filter { it != null && PACKAGE_NAME.matches(it) && claimed.add(it) }
                group.copy(packages = packages)
            }
            return AppRoutingConfig(scope, entries, groups)
        }
    }
}

/** A compiled in-VPN app rule. */
data class AppRoutingRule(
    val packageName: String,
    val outcome: UserTrafficOutcome,
    val targetNodeId: Long? = null,
)

/** A preset group of well-known apps, shown with only the ones installed on the device. */
data class AppPresetGroup(
    val key: String,
    val labelEn: String,
    val labelZh: String,
    val packages: List<String>,
)

object AppPresetCatalog {
    /** Google Play services carries sign-in and push for many apps; the UI explains it. */
    const val GOOGLE_PLAY_SERVICES = "com.google.android.gms"

    val groups: List<AppPresetGroup> = listOf(
        AppPresetGroup(
            key = "streaming",
            labelEn = "Streaming",
            labelZh = "流媒体",
            packages = listOf(
                "com.netflix.mediaclient",
                "com.disney.disneyplus",
                "com.google.android.youtube",
                "com.amazon.avod.thirdpartyclient",
                "com.wbd.stream",
                "com.hbo.hbonow",
                "tv.twitch.android.app",
                "com.hulu.plus",
                "com.apple.atve.androidtv.appletv",
                "com.viu.phone",
            ),
        ),
        AppPresetGroup(
            key = "ai",
            labelEn = "AI",
            labelZh = "AI",
            packages = listOf(
                "com.openai.chatgpt",
                "com.anthropic.claude",
                "com.google.android.apps.bard",
                "ai.perplexity.app.android",
                "com.microsoft.copilot",
                "ai.x.grok",
                "com.deepseek.chat",
            ),
        ),
        AppPresetGroup(
            key = "google",
            labelEn = "Google",
            labelZh = "Google 系列",
            packages = listOf(
                "com.google.android.googlequicksearchbox",
                "com.android.chrome",
                "com.google.android.gm",
                "com.google.android.apps.maps",
                "com.android.vending",
                "com.google.android.apps.docs",
                "com.google.android.apps.photos",
                "com.google.android.apps.translate",
                "com.google.android.apps.youtube.music",
                GOOGLE_PLAY_SERVICES,
            ),
        ),
        AppPresetGroup(
            key = "music",
            labelEn = "Music",
            labelZh = "音乐",
            packages = listOf(
                "com.spotify.music",
                "com.google.android.apps.youtube.music",
                "com.apple.android.music",
                "com.soundcloud.android",
                "com.aspiro.tidal",
                "deezer.android.app",
                "com.amazon.mp3",
            ),
        ),
    )

    val allPackages: Set<String> get() = groups.flatMapTo(linkedSetOf()) { it.packages }
}
