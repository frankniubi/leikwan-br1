package com.leikwan.bridge.ui.routing

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.ImportedRuleListStore
import com.leikwan.bridge.data.routing.RuleListFetcher
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.SurgeListConverter
import com.leikwan.bridge.routing.Country
import com.leikwan.bridge.routing.CountryCatalog
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.routing.ClashRuleParser
import com.leikwan.bridge.routing.ClashRuleCatalog
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * UI state for the user-authored routing surface (the 用户自定义分流 screen).
 */
data class RoutingUiState(
    val selectorEnabled: Boolean = false,
    val globalDefault: UserTrafficOutcome = UserTrafficOutcome.TUNNEL,
    val globalDefaultNodeId: Long? = null,
    val availableNodes: List<RoutingNodeOption> = emptyList(),
    val countryRules: List<RoutingRule> = emptyList(),
    val categoryRules: List<RoutingRule> = emptyList(),
    val clashRules: List<RoutingRule> = emptyList(),
    val presetCategories: List<String> = emptyList(),
    val presetCategoryOptions: List<RoutingCategoryOption> = emptyList(),
    val countryQuery: String = "",
    val countrySuggestions: List<Country> = emptyList(),
    val importErrors: List<String> = emptyList(),
    /** Imported Surge/Clash lists by id (labels and counts for IMPORTED_LIST rules). */
    val importedLists: Map<String, ImportedRuleListStore.ImportedRuleList> = emptyMap(),
    val listImportBusy: Boolean = false,
    /** Result line of the last list import (summary or error). */
    val listImportMessage: String? = null,
)

data class RoutingNodeOption(
    val id: Long,
    val name: String,
    val type: String,
)

data class RoutingCategoryOption(
    val key: String,
    val labelEn: String,
    val labelZh: String,
    val defaultDirect: Boolean,
) {
    fun label(zh: Boolean): String = if (zh) labelZh else labelEn
}

/**
 * Drives the Routing screen: a user-settable global default plus add/toggle/delete of country and
 * preset-category rules, all persisted via [UserRoutingRuleStore] / [Prefs] so the next VPN
 * start/reload compiles them into the sing-box route.
 *
 * Collaborators are constructor-injected (defaulting to the real implementations) so the view-model
 * is unit-testable without EncryptedSharedPreferences/AndroidKeyStore: a test can pass an in-memory
 * store and a plain-SharedPreferences-backed [Prefs].
 */
class RoutingViewModel(
    app: Application,
    private val db: AppDatabase = AppDatabase.get(app),
    private val store: UserRoutingRuleStore = UserRoutingRuleStore.from(app),
    private val prefs: Prefs = Prefs.get(app),
    private val categoryCatalog: RoutingCategoryCatalog = RoutingCategoryCatalog.default(),
    private val catalogRefresher: (suspend () -> Unit)? = null,
    private val listStore: ImportedRuleListStore = ImportedRuleListStore.from(app),
    private val listFetcher: RuleListFetcher = RuleListFetcher(),
    private val clock: () -> Long = System::currentTimeMillis,
) : AndroidViewModel(app) {

    private val _state = MutableStateFlow(
        RoutingUiState(
            selectorEnabled = prefs.routingSelectorEnabled,
            globalDefault = prefs.routingDefaultOutcome,
            globalDefaultNodeId = prefs.routingDefaultNodeId,
            presetCategories = categoryCatalog.presetKeys(),
            presetCategoryOptions = categoryOptions(),
        ),
    )
    val state = _state.asStateFlow()

    init {
        refresh()
    }

    fun refresh() {
        viewModelScope.launch {
            _state.value = _state.value.copy(
                selectorEnabled = prefs.routingSelectorEnabled,
                globalDefault = prefs.routingDefaultOutcome,
                globalDefaultNodeId = sanitizedDefaultNodeId(),
                availableNodes = availableNodeOptions(),
                countryRules = store.countryRules(),
                categoryRules = store.categoryRules(),
                clashRules = store.clashRules(),
                presetCategories = categoryCatalog.presetKeys(),
                presetCategoryOptions = categoryOptions(),
                importedLists = importedListsById(),
            )
        }
    }

    fun setGlobalDefault(outcome: UserTrafficOutcome) {
        prefs.routingDefaultOutcome = outcome
        _state.value = _state.value.copy(globalDefault = outcome)
    }

    fun setSelectorEnabled(enabled: Boolean) {
        prefs.routingSelectorEnabled = enabled
        _state.value = _state.value.copy(selectorEnabled = enabled)
    }

    fun setGlobalDefaultNode(nodeId: Long?) {
        viewModelScope.launch {
            prefs.routingDefaultNodeId = nodeId?.takeIf { it in enabledNodeIds() }
            refreshNow()
        }
    }

    fun addCountryRule(code: String, outcome: UserTrafficOutcome) {
        val normalized = code.trim().uppercase()
        if (normalized.isEmpty()) return
        val rule = RoutingRule(
            id = "country-${normalized.lowercase()}",
            kind = RoutingRuleKind.COUNTRY,
            matchValue = normalized,
            outcome = outcome,
            enabled = true,
        )
        viewModelScope.launch {
            store.upsert(rule)
            refreshNow()
        }
    }

    fun updateCountryQuery(query: String) {
        _state.value = _state.value.copy(
            countryQuery = query,
            countrySuggestions = CountryCatalog.search(query),
        )
    }

    fun selectCountrySuggestion(country: Country) {
        _state.value = _state.value.copy(
            countryQuery = country.code,
            countrySuggestions = emptyList(),
        )
    }

    fun addCategoryRule(category: String, outcome: UserTrafficOutcome) {
        val normalized = category.trim().lowercase()
        if (!categoryCatalog.supports(normalized)) return
        val rule = RoutingRule(
            id = "category-$normalized",
            kind = RoutingRuleKind.WEBSITE_CATEGORY,
            matchValue = normalized,
            outcome = outcome,
            enabled = true,
        )
        viewModelScope.launch {
            store.upsert(rule)
            refreshNow()
        }
    }

    fun toggleRule(id: String) {
        viewModelScope.launch {
            val current = store.all().firstOrNull { it.id == id } ?: return@launch
            store.setEnabled(id, !current.enabled)
            refreshNow()
        }
    }

    fun setRuleTargetNode(id: String, nodeId: Long?) {
        viewModelScope.launch {
            val sanitized = nodeId?.takeIf { it in enabledNodeIds() }
            store.setTargetNodeId(id, sanitized)
            refreshNow()
        }
    }

    fun deleteRule(id: String) {
        viewModelScope.launch {
            val rule = store.all().firstOrNull { it.id == id }
            store.delete(id)
            if (rule?.kind == RoutingRuleKind.IMPORTED_LIST) listStore.delete(rule.matchValue)
            refreshNow()
        }
    }

    /**
     * Downloads a Surge/Clash rule list (e.g. a blackmatrix7 `.list`) and stores it as one
     * routing rule whose traffic goes to [outcome].
     */
    fun importRuleList(url: String, name: String, outcome: UserTrafficOutcome) {
        if (url.isBlank() || _state.value.listImportBusy) return
        viewModelScope.launch {
            _state.value = _state.value.copy(listImportBusy = true, listImportMessage = null)
            val message = try {
                val conversion = SurgeListConverter.parse(listFetcher.fetch(url))
                saveList(name, url.trim(), conversion, outcome, existingId = null)
            } catch (e: Exception) {
                e.message ?: "导入失败"
            }
            _state.value = _state.value.copy(listImportBusy = false, listImportMessage = message)
            refreshNow()
        }
    }

    /** Re-downloads a URL-imported list in place (same rule, same outcome). */
    fun refreshImportedList(ruleId: String) {
        if (_state.value.listImportBusy) return
        viewModelScope.launch {
            val rule = store.all().firstOrNull { it.id == ruleId && it.kind == RoutingRuleKind.IMPORTED_LIST } ?: return@launch
            val list = listStore.get(rule.matchValue) ?: return@launch
            val url = list.sourceUrl ?: return@launch
            _state.value = _state.value.copy(listImportBusy = true, listImportMessage = null)
            val message = try {
                val conversion = SurgeListConverter.parse(listFetcher.fetch(url))
                if (conversion.isEmpty) {
                    "列表中没有可用规则，保留原内容"
                } else {
                    listStore.save(list.name, url, conversion, clock(), existingId = list.id)
                    summary(list.name, conversion)
                }
            } catch (e: Exception) {
                e.message ?: "更新失败"
            }
            _state.value = _state.value.copy(listImportBusy = false, listImportMessage = message)
            refreshNow()
        }
    }

    fun importClash(text: String) {
        viewModelScope.launch {
            if (shouldImportAsList(text)) {
                // Large pastes (a whole .list) and logical AND/OR/NOT rules go through the list
                // converter: one rule-set file instead of thousands of routing rows.
                val message = try {
                    val conversion = SurgeListConverter.parse(text)
                    saveList("粘贴的规则", null, conversion, dominantOutcome(conversion), existingId = null)
                } catch (e: Exception) {
                    e.message ?: "导入失败"
                }
                _state.value = _state.value.copy(importErrors = emptyList(), listImportMessage = message)
                refreshNow()
                return@launch
            }
            val catalogTags = ClashRuleCatalog.allowedGeositeTags()
            val parsed = ClashRuleParser.parse(text, catalogTags)
            val okLines = parsed.filter { it.isOk }.mapNotNull { it.rule }
            val errorLines = parsed.filter { !it.isOk }.mapNotNull { it.error }

            if (okLines.isNotEmpty()) {
                store.upsertAll(okLines)
            }

            _state.value = _state.value.copy(importErrors = errorLines)
            refreshNow()
        }
    }

    fun refreshRules() {
        viewModelScope.launch {
            runCatching { catalogRefresher?.invoke() }
            refreshNow()
        }
    }

    private suspend fun refreshNow() {
        _state.value = _state.value.copy(
            selectorEnabled = prefs.routingSelectorEnabled,
            globalDefault = prefs.routingDefaultOutcome,
            globalDefaultNodeId = sanitizedDefaultNodeId(),
            availableNodes = availableNodeOptions(),
            countryRules = store.countryRules(),
            categoryRules = store.categoryRules(),
            clashRules = store.clashRules(),
            presetCategories = categoryCatalog.presetKeys(),
            presetCategoryOptions = categoryOptions(),
            importedLists = importedListsById(),
        )
    }

    private fun importedListsById(): Map<String, ImportedRuleListStore.ImportedRuleList> =
        listStore.all().associateBy { it.id }

    private suspend fun saveList(
        name: String,
        sourceUrl: String?,
        conversion: SurgeListConverter.Conversion,
        outcome: UserTrafficOutcome,
        existingId: String?,
    ): String {
        if (conversion.isEmpty) return "列表中没有可用规则（跳过 ${conversion.skippedCount} 条）"
        val list = listStore.save(name, sourceUrl, conversion, clock(), existingId)
        store.upsert(
            RoutingRule(
                id = "list-${list.id}",
                kind = RoutingRuleKind.IMPORTED_LIST,
                matchValue = list.id,
                outcome = outcome,
                enabled = true,
            ),
        )
        return summary(list.name, conversion)
    }

    private fun summary(name: String, conversion: SurgeListConverter.Conversion): String = buildString {
        append("已导入「").append(name).append("」：").append(conversion.convertedCount).append(" 条")
        if (conversion.skippedCount > 0) {
            append("，跳过 ").append(conversion.skippedCount).append(" 条（")
            append(conversion.skipped.entries.joinToString("、") { "${it.key} ${it.value}" })
            append("）")
        }
        if (conversion.policies.size > 1) append("；原列表含多种策略，已统一按所选出口")
        conversion.finalPolicy?.let { append("；FINAL 未导入，未匹配流量按全局默认") }
    }

    private fun shouldImportAsList(text: String): Boolean {
        val lines = text.lineSequence().mapNotNull { SurgeListConverter.cleanLine(it) }.toList()
        if (lines.size > LIST_IMPORT_LINE_THRESHOLD) return true
        return lines.any { line ->
            val type = line.substringBefore(',').trim().uppercase()
            type == "AND" || type == "OR" || type == "NOT" || type == "DOMAIN-WILDCARD"
        }
    }

    /** A pasted list keeps its own policy when it has a single one. */
    private fun dominantOutcome(conversion: SurgeListConverter.Conversion): UserTrafficOutcome =
        when (conversion.policies.maxByOrNull { it.value }?.key) {
            "DIRECT" -> UserTrafficOutcome.DIRECT
            "REJECT" -> UserTrafficOutcome.REJECT
            else -> UserTrafficOutcome.TUNNEL
        }

    private companion object {
        const val LIST_IMPORT_LINE_THRESHOLD = 200
    }

    private fun categoryOptions(): List<RoutingCategoryOption> =
        categoryCatalog.presets().map {
            RoutingCategoryOption(
                key = it.key,
                labelEn = it.labelEn,
                labelZh = it.labelZh,
                defaultDirect = it.defaultDirect,
            )
        }

    private suspend fun sanitizedDefaultNodeId(): Long? {
        val nodeId = prefs.routingDefaultNodeId
        return nodeId?.takeIf { it in enabledNodeIds() }
    }

    private suspend fun enabledNodeIds(): Set<Long> =
        db.proxyNodeDao().getEnabled().map { it.id }.toSet()

    private suspend fun availableNodeOptions(): List<RoutingNodeOption> =
        db.proxyNodeDao().getEnabled().map { it.toOption() }

    private fun ProxyNode.toOption(): RoutingNodeOption =
        RoutingNodeOption(
            id = id,
            name = name,
            type = type,
        )
}
