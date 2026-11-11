package com.leikwan.bridge.routing

import java.net.InetAddress

/**
 * Parses Clash-style routing rules (one per line) into [RoutingRule]s or error messages.
 * Tolerates comments, blanks, trailing `no-resolve`; normalizes TYPE to enum.
 * Never throws; every line becomes Ok or Error.
 */
data class ParsedLine(
    val lineNo: Int,
    val rule: RoutingRule? = null,
    val error: String? = null,
) {
    val isOk: Boolean = rule != null && error == null
}

object ClashRuleParser {
    /**
     * Parse Clash-style lines into [ParsedLine]s. Each line is one rule:
     * - `TYPE,value` → outcome=TUNNEL
     * - `TYPE,value,POLICY` where POLICY is DIRECT/REJECT (case-insensitive) or anything else (→TUNNEL)
     * - Comments (`#`, `//`) and blank lines are skipped.
     * - Trailing `no-resolve` is tolerated and ignored.
     * - Unsupported or invalid lines produce error ParsedLine.
     *
     * @param text The Clash rules text (one rule per line).
     * @param catalogTags Set of allowed geosite/rule-set tags (lowercase for comparison).
     * @return List of [ParsedLine], one per non-blank line (including errors).
     */
    fun parse(text: String, catalogTags: Set<String>): List<ParsedLine> {
        val result = mutableListOf<ParsedLine>()
        val seenRules = mutableSetOf<Pair<RoutingRuleKind, String>>()
        val lineTexts = text.lines()

        lineTexts.forEachIndexed { idx, line ->
            val lineNo = idx + 1
            val trimmed = line.trim()

            // Skip comments and blank lines
            if (trimmed.isEmpty() || trimmed.startsWith("#") || trimmed.startsWith("//")) {
                return@forEachIndexed
            }

            // Split by comma, trim parts
            val parts = trimmed.split(',').map { it.trim() }
            if (parts.isEmpty()) {
                result += ParsedLine(lineNo, error = "空规则行（第 $lineNo 行）")
                return@forEachIndexed
            }

            // Extract type, value, and policy
            val typeStr = parts.getOrNull(0)?.uppercase() ?: ""
            val value = parts.getOrNull(1) ?: ""
            var policyStr = parts.getOrNull(2)?.uppercase() ?: "TUNNEL"

            // Remove trailing "NO-RESOLVE" if present
            if (policyStr == "NO-RESOLVE") {
                policyStr = parts.getOrNull(2)?.uppercase() ?: "TUNNEL"
            }

            // Validate policy (DIRECT, REJECT, or anything else = TUNNEL)
            val outcome = when (policyStr.uppercase()) {
                "DIRECT" -> UserTrafficOutcome.DIRECT
                "REJECT" -> UserTrafficOutcome.REJECT
                else -> UserTrafficOutcome.TUNNEL
            }

            // Alias mapping: IP-CIDR6 → IP_CIDR6, RULE-SET → RULE_SET (GEOSITE), GEOIP → COUNTRY
            val (kind, processedValue) = when (typeStr) {
                "DOMAIN" -> RoutingRuleKind.DOMAIN to value
                "DOMAIN-SUFFIX" -> RoutingRuleKind.DOMAIN_SUFFIX to value
                "DOMAIN-KEYWORD" -> RoutingRuleKind.DOMAIN_KEYWORD to value
                "DOMAIN-REGEX" -> RoutingRuleKind.DOMAIN_REGEX to value
                "IP-CIDR" -> RoutingRuleKind.IP_CIDR to value
                "IP-CIDR6" -> RoutingRuleKind.IP_CIDR6 to value
                "DST-PORT" -> RoutingRuleKind.DST_PORT to value
                "GEOSITE" -> RoutingRuleKind.GEOSITE to value.lowercase()
                "RULE-SET" -> RoutingRuleKind.RULE_SET to value.lowercase()
                "GEOIP" -> RoutingRuleKind.COUNTRY to value.lowercase()
                "MATCH" -> {
                    result += ParsedLine(lineNo, error = "MATCH 必须是唯一规则；请改用全局模式开关（第 $lineNo 行）")
                    return@forEachIndexed
                }
                // Unsupported types
                "IP-ASN", "SUB-RULE", "AND", "OR", "NOT", "DSCP", "SRC-IP-CIDR",
                "SRC-DOMAIN", "IN-PORT", "IN-GEOIP", "IN-IP-CIDR", "PROCESS-NAME",
                "PROCESS-PATH", "UID", "NETWORK" -> {
                    result += ParsedLine(lineNo, error = "暂不支持规则类型 $typeStr（第 $lineNo 行），该行未导入")
                    return@forEachIndexed
                }
                else -> {
                    result += ParsedLine(lineNo, error = "未知规则类型 $typeStr（第 $lineNo 行），该行未导入")
                    return@forEachIndexed
                }
            }

            // Validate value based on kind
            val validationError = validateValue(processedValue, kind, lineNo)
            if (validationError != null) {
                result += ParsedLine(lineNo, error = validationError)
                return@forEachIndexed
            }

            // For GEOSITE and RULE_SET, accept both bare names (openai) and full tags
            // (geosite-openai); storage keeps the full tag because the compiler emits
            // matchValue verbatim as the sing-box rule_set name.
            var effectiveValue = processedValue
            if (kind == RoutingRuleKind.GEOSITE || kind == RoutingRuleKind.RULE_SET) {
                val withPrefix = if (processedValue.startsWith("geosite-")) processedValue else "geosite-$processedValue"
                val matched = catalogTags.contains(processedValue) || catalogTags.contains(withPrefix)
                if (!matched) {
                    result += ParsedLine(
                        lineNo,
                        error = "未收录的 geosite 标签 $processedValue（第 $lineNo 行）— 请改用内置分类规则或更换标签"
                    )
                    return@forEachIndexed
                }
                effectiveValue = if (catalogTags.contains(withPrefix)) withPrefix else processedValue
            }

            // Check for duplicates
            val ruleKey = kind to effectiveValue
            if (ruleKey in seenRules) {
                val prevLineNo = lineTexts.take(lineNo - 1).indexOfLast { l ->
                    val p = l.trim().split(',').map { it.trim() }
                    val t = p.getOrNull(0)?.uppercase() ?: ""
                    val v = p.getOrNull(1) ?: ""
                    val normalizedT = when (t) {
                        "IP-CIDR6" -> "IP_CIDR6"
                        "RULE-SET" -> "RULE_SET"
                        "GEOIP" -> "COUNTRY"
                        else -> t.replace("-", "_")
                    }
                    normalizedT == kind.name && run {
                        val nv = v.lowercase()
                        if (kind == RoutingRuleKind.GEOSITE || kind == RoutingRuleKind.RULE_SET) {
                            (if (nv.startsWith("geosite-")) nv else "geosite-$nv") == effectiveValue
                        } else {
                            nv == processedValue
                        }
                    }
                } + 1
                result += ParsedLine(lineNo, error = "第 $prevLineNo 行与第 $lineNo 行重复，未导入")
                return@forEachIndexed
            }
            seenRules += ruleKey

            // Build rule
            val ruleId = "${kind.name.lowercase()}-${effectiveValue}-${System.nanoTime()}"
            val rule = RoutingRule(
                id = ruleId,
                kind = kind,
                matchValue = effectiveValue,
                outcome = outcome,
                enabled = true,
                targetNodeId = null, // Clash rules don't pin nodes
            )
            result += ParsedLine(lineNo, rule = rule)
        }

        return result
    }

    private fun validateValue(value: String, kind: RoutingRuleKind, lineNo: Int): String? = when (kind) {
        RoutingRuleKind.IP_CIDR -> validateCidr(value, 4, lineNo)
        RoutingRuleKind.IP_CIDR6 -> validateCidr(value, 6, lineNo)
        RoutingRuleKind.DST_PORT -> validatePort(value, lineNo)
        RoutingRuleKind.DOMAIN_REGEX -> validateRegex(value, lineNo)
        RoutingRuleKind.DOMAIN -> {
            if (value.isEmpty() || value.contains('/') || value.contains(' ')) {
                "无效的域名值 $value（第 $lineNo 行）"
            } else null
        }
        RoutingRuleKind.DOMAIN_SUFFIX, RoutingRuleKind.DOMAIN_KEYWORD -> {
            if (value.isEmpty() || value.contains('/') || value.contains(' ')) {
                "无效的域名值 $value（第 $lineNo 行）"
            } else null
        }
        RoutingRuleKind.GEOSITE, RoutingRuleKind.RULE_SET -> {
            if (value.isEmpty()) "无效的 geosite 标签（第 $lineNo 行）" else null
        }
        RoutingRuleKind.COUNTRY -> {
            if (value.isEmpty()) "无效的国家代码（第 $lineNo 行）" else null
        }
        RoutingRuleKind.WEBSITE_CATEGORY -> null // Shouldn't reach here for Clash rules
    }

    private fun validateCidr(value: String, version: Int, lineNo: Int): String? {
        if (!value.contains('/')) {
            return "CIDR 地址必须包含 /（第 $lineNo 行）"
        }
        val parts = value.split('/')
        if (parts.size != 2) {
            return "无效的 CIDR 地址 $value（第 $lineNo 行）"
        }
        val (addr, prefixStr) = parts

        // Validate prefix
        val prefix = prefixStr.toIntOrNull()
        if (prefix == null || prefix < 0 || prefix > (if (version == 4) 32 else 128)) {
            return "无效的 CIDR 前缀长度 $prefixStr（第 $lineNo 行）"
        }

        // Validate address
        return try {
            InetAddress.getByName(addr)
            null
        } catch (e: Exception) {
            "无效的 IP 地址 $addr（第 $lineNo 行）"
        }
    }

    private fun validatePort(value: String, lineNo: Int): String? {
        if (value.isEmpty()) return "无效的端口（第 $lineNo 行）"

        return if (value.contains(':')) {
            // Port range: sing-box only accepts a single `a:b` range, not `a:b:c`.
            val parts = value.split(':')
            if (parts.size != 2) {
                return "无效的端口范围 $value（第 $lineNo 行），sing-box 仅支持单个端口或 a:b 范围"
            }
            val (a, b) = parts[0].toIntOrNull() to parts[1].toIntOrNull()
            if (a == null || b == null || a < 1 || b > 65535 || a >= b) {
                "无效的端口范围 $value（第 $lineNo 行）"
            } else null
        } else {
            // Single port
            val port = value.toIntOrNull()
            if (port == null || port < 1 || port > 65535) {
                "无效的端口 $value（第 $lineNo 行）"
            } else null
        }
    }

    /**
     * sing-box compiles DOMAIN-REGEX with Go's RE2 engine, which has no backtracking and therefore
     * rejects lookaround assertions, backreferences, and atomic groups that Java's regex engine
     * (used by [Regex] above) happily compiles. Reject those patterns here so an import never
     * produces a config sing-box refuses to load.
     */
    private fun hasUnsupportedRe2Syntax(pattern: String): Boolean {
        val forbiddenLiterals = listOf("(?=", "(?!", "(?<=", "(?<!", "(?>")
        if (forbiddenLiterals.any { pattern.contains(it) }) return true
        return Regex("""\\[1-9]""").containsMatchIn(pattern)
    }

    private fun validateRegex(value: String, lineNo: Int): String? {
        return try {
            Regex(value)
            if (hasUnsupportedRe2Syntax(value)) {
                "第 $lineNo 行：正则使用了 sing-box 不支持的语法（前后断言/反向引用）"
            } else {
                null
            }
        } catch (e: Exception) {
            "无效的正则表达式 $value（第 $lineNo 行）"
        }
    }
}
