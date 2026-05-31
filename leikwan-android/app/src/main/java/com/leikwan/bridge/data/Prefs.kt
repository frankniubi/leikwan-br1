package com.leikwan.bridge.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKeys
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.service.ProxyConnectionMode

class Prefs internal constructor(private val prefs: SharedPreferences) {

    var panelUrl: String
        get() = prefs.getString("panel_url", "https://v3-mirror.leikwanhost.com") ?: "https://v3-mirror.leikwanhost.com"
        set(v) = prefs.edit().putString("panel_url", v).apply()

    var token: String?
        get() = prefs.getString("token", null)
        set(v) = prefs.edit().putString("token", v).apply()

    var username: String?
        get() = prefs.getString("username", null)
        set(v) = prefs.edit().putString("username", v).apply()

    var email: String?
        get() = prefs.getString("email", null)
        set(v) = prefs.edit().putString("email", v).apply()

    /** Stored in EncryptedSharedPreferences for client switching */
    var password: String?
        get() = prefs.getString("password", null)
        set(v) = prefs.edit().putString("password", v).apply()

    var clientId: Long
        get() = prefs.getLong("client_id", 0L)
        set(v) = prefs.edit().putLong("client_id", v).apply()

    var clientName: String?
        get() = prefs.getString("client_name", null)
        set(v) = prefs.edit().putString("client_name", v).apply()

    /** JSON array of {id, name} objects — cached from login response */
    var clientsJson: String?
        get() = prefs.getString("clients_json", null)
        set(v) = prefs.edit().putString("clients_json", v).apply()

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

    fun isLoggedIn(): Boolean = token != null && clientsJson != null

    fun logout() {
        prefs.edit()
            .remove("token").remove("username").remove("email").remove("password")
            .remove("client_id").remove("client_name").remove("clients_json")
            .remove("selected_forward_id")
            .remove("selected_proxy_id")
            .remove("proxy_connection_mode")
            .remove("routing_default_node_id")
            .apply()
    }

    companion object {
        @Volatile private var INSTANCE: Prefs? = null

        fun get(context: Context): Prefs =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Prefs(
                    EncryptedSharedPreferences.create(
                        "leikwan_secure_prefs",
                        MasterKeys.getOrCreate(MasterKeys.AES256_GCM_SPEC),
                        context.applicationContext,
                        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
                    )
                ).also { INSTANCE = it }
            }
    }
}
