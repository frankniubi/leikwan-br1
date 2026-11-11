package com.leikwan.bridge.routing

/**
 * Compiler + precedence authority for the v2 safe-routing core.
 *
 * - [compile] produces the authoritative [RoutingPolicySnapshot] for one rebuild cycle (dropping
 *   unsupported rules with a warning, never expanding outcomes beyond TUNNEL/DIRECT).
 * - [decide] is the canonical, unit-testable statement of precedence (and powers explainability). It
 *   is explicitly NOT in the data path; real per-packet routing is done by sing-box from the JSON.
 * - [compileRouteRules] / [requiredRuleSets] produce the lists that SingboxConfigBuilder.buildRoute
 *   emits into route.rules / route.rule_set.
 *
 * Precedence (enforced identically in [decide] and in buildRoute):
 *   protected paths > runtime reachability > user app rules > user country rules > user category rules
 *   > global default.
 * Protected paths win even when a user rule says TUNNEL.
 */
class RoutingPolicyEngine(
    private val safePathRegistry: SafePathRegistry,
    private val categoryCatalog: RoutingCategoryCatalog,
) {
    companion object {
        /** Sentinel outbound for TUNNEL rules; SingboxConfigBuilder swaps it for the fallback proxy tag. */
        const val PROXY_OUTBOUND_SENTINEL = "<PROXY>"

        /** Sentinel for a TUNNEL rule pinned to a specific node; SingboxConfigBuilder resolves it. */
        fun nodeSentinel(nodeId: Long): String = "<NODE:$nodeId>"

        private const val GEOIP_BASE =
            "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/"

        private fun geoipTag(countryCode: String): String = "geoip-${countryCode.trim().lowercase()}"

        private fun geoipUrl(tag: String): String = "$GEOIP_BASE$tag.srs"

        /** Rule-set tag of an imported list: `user-list-<file name>` (file = `<list id>.json`). */
        fun importedListTag(path: String): String =
            "user-list-" + java.io.File(path).name.removeSuffix(".json")
    }

    fun withCategoryCatalog(categoryCatalog: RoutingCategoryCatalog): RoutingPolicyEngine =
        RoutingPolicyEngine(safePathRegistry, categoryCatalog)

    /**
     * Compile the authoritative snapshot for one rebuild cycle. Unsupported or disabled rules are
     * dropped with a warning; outcomes are never expanded beyond TUNNEL/DIRECT/REJECT.
     */
    fun compile(
        globalDefault: UserTrafficOutcome,
        globalDefaultNodeId: Long?,
        countryRules: List<RoutingRule>,
        categoryRules: List<RoutingRule>,
        rulePackVersions: List<String>,
        compiledAtMs: Long,
        appRules: List<AppRoutingRule> = emptyList(),
        clashRules: List<RoutingRule> = emptyList(),
    ): RoutingPolicySnapshot {
        val warnings = mutableListOf<String>()

        val keptCountry = countryRules.filter { rule ->
            when {
                !rule.enabled -> false
                rule.kind != RoutingRuleKind.COUNTRY -> {
                    warnings += "Dropped rule '${rule.id}': kind ${rule.kind} is not a country rule."
                    false
                }
                !isSupportedCountry(rule.matchValue) -> {
                    warnings += "Dropped country rule '${rule.id}': unsupported country code '${rule.matchValue}'."
                    false
                }
                else -> true
            }
        }

        val keptCategory = categoryRules.filter { rule ->
            when {
                !rule.enabled -> false
                rule.kind != RoutingRuleKind.WEBSITE_CATEGORY -> {
                    warnings += "Dropped rule '${rule.id}': kind ${rule.kind} is not a website-category rule."
                    false
                }
                !categoryCatalog.supports(rule.matchValue) -> {
                    warnings += "Dropped category rule '${rule.id}': unknown category '${rule.matchValue}'."
                    false
                }
                else -> true
            }
        }

        val keptClash = clashRules.filter { rule ->
            when {
                !rule.enabled -> false
                rule.kind in listOf(
                    RoutingRuleKind.DOMAIN, RoutingRuleKind.DOMAIN_SUFFIX, RoutingRuleKind.DOMAIN_KEYWORD,
                    RoutingRuleKind.DOMAIN_REGEX, RoutingRuleKind.IP_CIDR, RoutingRuleKind.IP_CIDR6,
                    RoutingRuleKind.DST_PORT, RoutingRuleKind.GEOSITE, RoutingRuleKind.RULE_SET,
                ) -> true
                rule.kind == RoutingRuleKind.IMPORTED_LIST -> {
                    if (rule.matchValue.isNotEmpty() && java.io.File(rule.matchValue).isAbsolute) {
                        true
                    } else {
                        warnings += "Dropped rule '${rule.id}': imported list file is missing."
                        false
                    }
                }
                else -> {
                    warnings += "Dropped rule '${rule.id}': kind ${rule.kind} is not a supported Clash rule."
                    false
                }
            }
        }

        return RoutingPolicySnapshot(
            protectedPaths = safePathRegistry.protectedEntries(),
            userCountryRules = keptCountry,
            userCategoryRules = keptCategory,
            globalDefaultOutcome = globalDefault,
            globalDefaultNodeId = globalDefaultNodeId,
            activeRulePackVersions = rulePackVersions,
            compileTimestampMs = compiledAtMs,
            warnings = warnings,
            precedenceVersion = 1,
            userAppRules = appRules.distinctBy { it.packageName },
            userClashRules = keptClash,
        )
    }

    /**
     * Canonical precedence statement (diagnostics only; not the data path).
     *
     * Steps: system-protected > user country > user category > global default. Runtime reachability
     * is enforced at config-build time (it has no per-request signal here), so [decide] never returns
     * [RoutingDecisionReason.RUNTIME_REACHABILITY].
     */
    fun decide(snapshot: RoutingPolicySnapshot, request: RoutingRequest): RoutingDecision {
        val host = request.destinationHost?.trim()?.lowercase()
        val ip = request.destinationIp?.trim()
        val protectedMatch = snapshot.protectedPaths.firstOrNull { entry ->
            (host != null && entry.domains.any { it.equals(host, ignoreCase = true) }) ||
                (ip != null && entry.ipCidrs.contains(ip))
        }
        if (protectedMatch != null) {
            return RoutingDecision(
                outcome = UserTrafficOutcome.DIRECT,
                reason = RoutingDecisionReason.SYSTEM_PROTECTED,
                matchedRuleId = null,
                protectedPathId = protectedMatch.id,
            )
        }

        val countryRule = snapshot.userCountryRules.firstOrNull {
            request.countryCode != null && it.matchValue.equals(request.countryCode, ignoreCase = true)
        }
        if (countryRule != null) {
            return RoutingDecision(countryRule.outcome, RoutingDecisionReason.USER_RULE, countryRule.id, null)
        }

        val categoryRule = snapshot.userCategoryRules.firstOrNull {
            request.websiteCategory != null && it.matchValue.equals(request.websiteCategory, ignoreCase = true)
        }
        if (categoryRule != null) {
            return RoutingDecision(categoryRule.outcome, RoutingDecisionReason.USER_RULE, categoryRule.id, null)
        }

        return RoutingDecision(
            outcome = snapshot.globalDefaultOutcome,
            reason = RoutingDecisionReason.DEFAULT,
            matchedRuleId = null,
            protectedPathId = null,
        )
    }

    /**
     * Project user rules into route.rule-shaped [CompiledRouteRule]s, in precedence order
     * (app rules, clash rules, country rules, category rules). DIRECT -> outbound "direct"; TUNNEL -> proxy/node sentinel; REJECT -> "reject".
     */
    fun compileRouteRules(snapshot: RoutingPolicySnapshot): List<CompiledRouteRule> {
        val compiled = mutableListOf<CompiledRouteRule>()
        // App rules first, one route rule per distinct outbound.
        snapshot.userAppRules
            .groupBy { rule ->
                when (rule.outcome) {
                    UserTrafficOutcome.DIRECT -> "direct"
                    UserTrafficOutcome.TUNNEL -> rule.targetNodeId?.let { nodeSentinel(it) } ?: PROXY_OUTBOUND_SENTINEL
                    UserTrafficOutcome.REJECT -> "reject"
                }
            }
            .forEach { (outbound, rules) ->
                compiled += CompiledRouteRule(
                    ruleSetTags = emptyList(),
                    outbound = outbound,
                    sourceRuleId = "app:$outbound",
                    packageNames = rules.map { it.packageName },
                )
            }
        // Clash rules next, before country/category rules
        snapshot.userClashRules.forEach { rule ->
            compiled += compileClashRule(rule)
        }
        snapshot.userCountryRules.forEach { rule ->
            compiled += CompiledRouteRule(
                ruleSetTags = listOf(geoipTag(rule.matchValue)),
                outbound = outboundFor(rule),
                sourceRuleId = rule.id,
            )
        }
        snapshot.userCategoryRules.forEach { rule ->
            val tags = categoryCatalog.ruleSetsFor(rule.matchValue).map { it.tag }
            if (tags.isNotEmpty()) {
                compiled += CompiledRouteRule(
                    ruleSetTags = tags,
                    outbound = outboundFor(rule),
                    sourceRuleId = rule.id,
                )
            }
        }
        return compiled
    }

    /**
     * All remote rule-set refs the snapshot needs (geoip per country + category geosite refs + clash geosite refs),
     * de-duplicated by tag, preserving first-seen order.
     */
    fun requiredRuleSets(snapshot: RoutingPolicySnapshot): List<RuleSetRef> {
        val refs = LinkedHashMap<String, RuleSetRef>()
        snapshot.userCountryRules.forEach { rule ->
            val tag = geoipTag(rule.matchValue)
            refs.putIfAbsent(tag, RuleSetRef(tag, geoipUrl(tag)))
        }
        snapshot.userCategoryRules.forEach { rule ->
            categoryCatalog.ruleSetsFor(rule.matchValue).forEach { ref ->
                refs.putIfAbsent(ref.tag, ref)
            }
        }
        // Add clash rules' geosite/rule-set tags
        snapshot.userClashRules.forEach { rule ->
            if (rule.kind == RoutingRuleKind.IMPORTED_LIST) {
                val tag = importedListTag(rule.matchValue)
                refs.putIfAbsent(tag, RuleSetRef(tag, url = "", localPath = rule.matchValue))
                return@forEach
            }
            if (rule.kind == RoutingRuleKind.GEOSITE || rule.kind == RoutingRuleKind.RULE_SET) {
                val tag = rule.matchValue
                if (!refs.containsKey(tag)) {
                    refs[tag] = RuleSetRef(tag, RoutingCategoryCatalog.geositeUrl(tag))
                }
            }
        }
        return refs.values.toList()
    }

    private fun outboundFor(rule: RoutingRule): String = when (rule.outcome) {
        UserTrafficOutcome.DIRECT -> "direct"
        UserTrafficOutcome.TUNNEL -> rule.targetNodeId?.let { nodeSentinel(it) } ?: PROXY_OUTBOUND_SENTINEL
        UserTrafficOutcome.REJECT -> "reject"
    }

    private fun compileClashRule(rule: RoutingRule): CompiledRouteRule {
        val outbound = outboundFor(rule)

        // Build raw JSON for Clash rule types
        val rawMatch = com.google.gson.JsonObject().apply {
            when (rule.kind) {
                RoutingRuleKind.DOMAIN -> {
                    add("domain", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.DOMAIN_SUFFIX -> {
                    add("domain_suffix", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.DOMAIN_KEYWORD -> {
                    add("domain_keyword", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.DOMAIN_REGEX -> {
                    add("domain_regex", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.IP_CIDR -> {
                    add("ip_cidr", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.IP_CIDR6 -> {
                    add("ip_cidr", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.DST_PORT -> {
                    if (rule.matchValue.contains(':')) {
                        addProperty("port_range", rule.matchValue)
                    } else {
                        add("port", com.google.gson.JsonArray().apply { add(rule.matchValue.toIntOrNull() ?: 0) })
                    }
                }
                RoutingRuleKind.GEOSITE, RoutingRuleKind.RULE_SET -> {
                    add("rule_set", com.google.gson.JsonArray().apply { add(rule.matchValue) })
                }
                RoutingRuleKind.IMPORTED_LIST -> {
                    add("rule_set", com.google.gson.JsonArray().apply { add(importedListTag(rule.matchValue)) })
                }
                else -> {
                    // Shouldn't happen for valid Clash rules
                }
            }
        }

        return CompiledRouteRule(
            ruleSetTags = when (rule.kind) {
                RoutingRuleKind.GEOSITE, RoutingRuleKind.RULE_SET -> listOf(rule.matchValue)
                RoutingRuleKind.IMPORTED_LIST -> listOf(importedListTag(rule.matchValue))
                else -> emptyList()
            },
            outbound = outbound,
            sourceRuleId = rule.id,
            packageNames = emptyList(),
            rawMatch = rawMatch,
        )
    }

    private fun isSupportedCountry(matchValue: String): Boolean {
        val cc = matchValue.trim()
        return cc.length == 2 && cc.all { it.isLetter() }
    }
}
