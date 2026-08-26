package com.leikwan.bridge.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.security.AndroidDeviceIdentity
import com.leikwan.bridge.service.ProxyConnectionMode

class Prefs internal constructor(private val prefs: SharedPreferences) {
    init {
        migrateLegacyCredentials()
    }

    var panelUrl: String
        get() = prefs.getString("panel_url", "https://v3-mirror.leikwanhost.com") ?: "https://v3-mirror.leikwanhost.com"
        set(v) = prefs.edit().putString("panel_url", v).apply()

    var agentToken: String?
        get() = prefs.getString("agent_token", null)
        set(v) = putNullableString("agent_token", v)

    var deviceSessionToken: String?
        get() = prefs.getString("device_session_token", null)
        set(v) = putNullableString("device_session_token", v)

    var deviceSessionExpiresAtMs: Long
        get() = prefs.getLong("device_session_expires_at_ms", 0L)
        set(v) = prefs.edit().putLong("device_session_expires_at_ms", v).apply()

    var enrolledDeviceId: String?
        get() = prefs.getString("enrolled_device_id", null)
        set(v) = putNullableString("enrolled_device_id", v)

    var clientId: Long
        get() = prefs.getLong("client_id", 0L)
        set(v) = prefs.edit().putLong("client_id", v).apply()

    var selectedForwardId: Long
        get() = prefs.getLong("selected_forward_id", 0L)
        set(v) = prefs.edit().putLong("selected_forward_id", v).apply()

    var selectedProxyId: Long
        get() = prefs.getLong("selected_proxy_id", 0L)
        set(v) = prefs.edit().putLong("selected_proxy_id", v).apply()

    var proxyConnectionMode: ProxyConnectionMode
        get() = ProxyConnectionMode.fromStored(prefs.getString("proxy_connection_mode", ProxyConnectionMode.SERVICES.storedValue))
        set(v) = prefs.edit().putString("proxy_connection_mode", v.storedValue).apply()

    var dnsServer: String
        get() = prefs.getString("dns_server", "1.1.1.1") ?: "1.1.1.1"
        set(v) = prefs.edit().putString("dns_server", v).apply()

    var dnsFallback: String
        get() = prefs.getString("dns_fallback", "8.8.8.8") ?: "8.8.8.8"
        set(v) = prefs.edit().putString("dns_fallback", v).apply()

    var dnsStrategy: String
        get() = prefs.getString("dns_strategy", "prefer_ipv4") ?: "prefer_ipv4"
        set(v) = prefs.edit().putString("dns_strategy", v).apply()

    var bypassLan: Boolean
        get() = prefs.getBoolean("bypass_lan", true)
        set(v) = prefs.edit().putBoolean("bypass_lan", v).apply()

    var bypassChina: Boolean
        get() = prefs.getBoolean("bypass_china", false)
        set(v) = prefs.edit().putBoolean("bypass_china", v).apply()

    var customBypassRules: String
        get() = prefs.getString("custom_bypass", "") ?: ""
        set(v) = prefs.edit().putString("custom_bypass", v).apply()

    /**
     * User-settable global default routing outcome (stored by [UserTrafficOutcome.name]).
     * `TUNNEL` = global VPN (rules are DIRECT exceptions); `DIRECT` = selective tunneling.
     */
    var routingDefaultOutcome: UserTrafficOutcome
        get() = runCatching {
            UserTrafficOutcome.valueOf(
                prefs.getString("routing_default_outcome", UserTrafficOutcome.TUNNEL.name)
                    ?: UserTrafficOutcome.TUNNEL.name
            )
        }.getOrDefault(UserTrafficOutcome.TUNNEL)
        set(v) = prefs.edit().putString("routing_default_outcome", v.name).apply()

    /**
     * Master switch for user-authored country/category routing rules.
     * Default OFF keeps the Proxies-selected node authoritative for new installs.
     */
    var routingSelectorEnabled: Boolean
        get() = prefs.getBoolean("routing_selector_enabled", false)
        set(v) = prefs.edit().putBoolean("routing_selector_enabled", v).apply()

    /**
     * Optional node pin for the global TUNNEL default. `0`/null = auto/default selector.
     */
    var routingDefaultNodeId: Long?
        get() = prefs.getLong("routing_default_node_id", 0L).takeIf { it > 0L }
        set(v) {
            val editor = prefs.edit()
            if (v != null && v > 0L) {
                editor.putLong("routing_default_node_id", v)
            } else {
                editor.remove("routing_default_node_id")
            }
            editor.apply()
        }

    var activeRulePackCountryVersion: String?
        get() = prefs.getString("active_rule_pack_country_version", null)
        set(v) = prefs.edit().putString("active_rule_pack_country_version", v).apply()

    var activeRulePackCategoryVersion: String?
        get() = prefs.getString("active_rule_pack_category_version", null)
        set(v) = prefs.edit().putString("active_rule_pack_category_version", v).apply()

    var activeRulePackSourceLabel: String?
        get() = prefs.getString("active_rule_pack_source_label", null)
        set(v) = prefs.edit().putString("active_rule_pack_source_label", v).apply()

    var activeRulePackFetchedAtMs: Long
        get() = prefs.getLong("active_rule_pack_fetched_at_ms", 0L)
        set(v) = prefs.edit().putLong("active_rule_pack_fetched_at_ms", v).apply()

    var activeRulePackStale: Boolean
        get() = prefs.getBoolean("active_rule_pack_stale", false)
        set(v) = prefs.edit().putBoolean("active_rule_pack_stale", v).apply()

    var latestExplainabilityRevision: Long
        get() = prefs.getLong("latest_explainability_revision", 0L)
        set(v) = prefs.edit().putLong("latest_explainability_revision", v).apply()

    var latestExplainabilityCompiledAtMs: Long
        get() = prefs.getLong("latest_explainability_compiled_at_ms", 0L)
        set(v) = prefs.edit().putLong("latest_explainability_compiled_at_ms", v).apply()

    fun isLoggedIn(nowMs: Long = System.currentTimeMillis()): Boolean =
        clientId > 0L &&
            !agentToken.isNullOrBlank() &&
            !enrolledDeviceId.isNullOrBlank() &&
            !deviceSessionToken.isNullOrBlank() &&
            deviceSessionExpiresAtMs > nowMs

    fun clearEnrollment(clearIdentity: Boolean = true) {
        val cleared = prefs.edit()
            .remove("agent_token")
            .remove("device_session_token")
            .remove("device_session_expires_at_ms")
            .remove("enrolled_device_id")
            .remove("token")
            .remove("username")
            .remove("email")
            .remove("password")
            .remove("clients_json")
            .remove("client_id")
            .remove("client_name")
            .remove("selected_forward_id")
            .commit()
        check(cleared) { "failed to clear enrollment state" }
        if (clearIdentity) {
            runCatching { AndroidDeviceIdentity().clear() }
        }
    }

    fun clearDeviceSession() {
        check(
            prefs.edit()
                .remove("device_session_token")
                .remove("device_session_expires_at_ms")
                .commit(),
        ) { "failed to clear device session" }
    }

    fun logout() {
        clearEnrollment()
        prefs.edit()
            .remove("selected_proxy_id")
            .remove("proxy_connection_mode")
            .remove("routing_selector_enabled")
            .remove("routing_default_node_id")
            .apply()
    }

    private fun migrateLegacyCredentials() {
        val legacyKeys = listOf("token", "username", "email", "password", "clients_json")
        if (legacyKeys.none(prefs::contains)) return
        val editor = prefs.edit()
        legacyKeys.forEach(editor::remove)
        check(editor.commit()) { "failed to clear legacy credentials" }
    }

    private fun putNullableString(key: String, value: String?) {
        val editor = prefs.edit()
        if (value.isNullOrBlank()) editor.remove(key) else editor.putString(key, value)
        editor.apply()
    }

    companion object {
        @Volatile private var INSTANCE: Prefs? = null

        fun get(context: Context): Prefs =
            INSTANCE ?: synchronized(this) {
                val appContext = context.applicationContext
                val masterKey = MasterKey.Builder(appContext)
                    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                    .build()
                INSTANCE ?: Prefs(
                    EncryptedSharedPreferences.create(
                        appContext,
                        "leikwan_secure_prefs",
                        masterKey,
                        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
                    )
                ).also { INSTANCE = it }
            }
    }
}
