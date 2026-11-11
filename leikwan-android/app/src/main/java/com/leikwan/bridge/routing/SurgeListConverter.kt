package com.leikwan.bridge.routing

import com.google.gson.JsonArray
import com.google.gson.JsonObject

/**
 * Converts a Surge / Clash rule list (e.g. blackmatrix7 `ChinaMax_All.list`, or a Clash
 * rule-provider `payload:` YAML) into a sing-box `source` rule-set: `{"version":2,"rules":[...]}`.
 *
 * The whole list maps to ONE outcome chosen at import (a rule-set match has no per-rule
 * policy), so each line's policy column is only counted for the import summary.
 * Plain rules are merged into a few headless rules (domain family / ip_cidr / source /
 * port, which sing-box ORs across separate rules); AND/OR/NOT become logical rules.
 * Types sing-box cannot express here (PROCESS-NAME, URL-REGEX, IP-ASN, GEOIP, USER-AGENT,
 * SUB-RULE, nested RULE-SET …) are skipped and counted, never fatal.
 *
 * Validation never resolves names (no DNS during an import). Same spec as iOS
 * `SurgeListConverter.swift`.
 */
object SurgeListConverter {
    const val MAX_BYTES = 4 * 1024 * 1024
    const val MAX_RULES = 20_000

    data class Conversion(
        /** Headless rules for the rule-set file. */
        val rules: List<JsonObject>,
        /** Number of source entries that became part of [rules]. */
        val convertedCount: Int,
        /** Skipped entries by rule type (or "INVALID"). */
        val skipped: Map<String, Int>,
        /** Policy column counts (DIRECT / REJECT / PROXY …) of the converted entries. */
        val policies: Map<String, Int>,
        /** FINAL / MATCH policy if the list had one (lists normally don't). */
        val finalPolicy: String?,
        /** First few problems, for display. */
        val samples: List<String>,
    ) {
        val isEmpty: Boolean get() = rules.isEmpty()
        val skippedCount: Int get() = skipped.values.sum()
    }

    class TooLargeException(message: String) : IllegalArgumentException(message)

    private val unsupportedTypes = setOf(
        "PROCESS-NAME", "PROCESS-PATH", "URL-REGEX", "USER-AGENT", "IP-ASN", "GEOIP", "GEOSITE",
        "SUB-RULE", "RULE-SET", "DOMAIN-SET", "SRC-PORT", "IN-PORT", "IN-TYPE", "IN-USER", "IN-NAME",
        "PROTOCOL", "NETWORK", "DSCP", "UID", "DEVICE-NAME", "SUBNET", "CELLULAR-RADIO", "HOSTNAME-TYPE",
        "SCRIPT", "DEST-PORT-RANGE",
    )

    private const val MAX_SAMPLES = 8

    fun parse(text: String): Conversion {
        if (text.length > MAX_BYTES) throw TooLargeException("规则列表超过 4 MB")

        val domain = LinkedHashSet<String>()
        val domainSuffix = LinkedHashSet<String>()
        val domainKeyword = LinkedHashSet<String>()
        val domainRegex = LinkedHashSet<String>()
        val ipCidr = LinkedHashSet<String>()
        val sourceIpCidr = LinkedHashSet<String>()
        val ports = LinkedHashSet<Int>()
        val portRanges = LinkedHashSet<String>()
        val logical = ArrayList<JsonObject>()
        val skipped = LinkedHashMap<String, Int>()
        val policies = LinkedHashMap<String, Int>()
        val samples = ArrayList<String>()
        var finalPolicy: String? = null
        var converted = 0

        fun skip(type: String, lineNo: Int, line: String) {
            skipped[type] = (skipped[type] ?: 0) + 1
            if (samples.size < MAX_SAMPLES) samples += "第 $lineNo 行 ($type)：${line.take(80)}"
        }

        text.removePrefix("﻿").lineSequence().forEachIndexed { index, raw ->
            val lineNo = index + 1
            val line = cleanLine(raw) ?: return@forEachIndexed
            if (converted >= MAX_RULES) {
                skip("LIMIT", lineNo, line)
                return@forEachIndexed
            }
            val parts = splitTopLevel(line)
            val type = parts[0].trim().uppercase()
            when {
                type == "FINAL" || type == "MATCH" -> {
                    finalPolicy = parts.getOrNull(1)?.trim()?.takeIf { it.isNotEmpty() } ?: "DIRECT"
                }
                type == "AND" || type == "OR" || type == "NOT" -> {
                    val rule = parts.getOrNull(1)?.let { convertLogical(type, it) }
                    if (rule == null) {
                        skip("LOGICAL-UNSUPPORTED", lineNo, line)
                    } else {
                        logical += rule
                        converted++
                        countPolicy(policies, parts.getOrNull(2))
                    }
                }
                type in unsupportedTypes -> skip(type, lineNo, line)
                else -> {
                    val value = parts.getOrNull(1)?.trim().orEmpty()
                    val ok = when (type) {
                        "DOMAIN" -> normalizeDomain(value)?.let { domain += it } != null
                        "DOMAIN-SUFFIX" -> normalizeDomain(value.removePrefix("."))?.let { domainSuffix += it } != null
                        "DOMAIN-KEYWORD" -> normalizeKeyword(value)?.let { domainKeyword += it } != null
                        "DOMAIN-REGEX" -> normalizeRegex(value)?.let { domainRegex += it } != null
                        "DOMAIN-WILDCARD" -> wildcardToRegex(value)?.let { domainRegex += it } != null
                        "IP-CIDR", "IP-CIDR6", "IP6-CIDR" -> normalizeCidr(value)?.let { ipCidr += it } != null
                        "SRC-IP", "SRC-IP-CIDR" -> normalizeCidr(value)?.let { sourceIpCidr += it } != null
                        "DST-PORT", "DEST-PORT" -> addPort(value, ports, portRanges)
                        else -> {
                            skip(type.ifEmpty { "INVALID" }, lineNo, line)
                            return@forEachIndexed
                        }
                    }
                    if (ok) {
                        converted++
                        countPolicy(policies, parts.getOrNull(2))
                    } else {
                        skip("INVALID", lineNo, line)
                    }
                }
            }
        }

        val rules = ArrayList<JsonObject>()
        if (domain.isNotEmpty() || domainSuffix.isNotEmpty() || domainKeyword.isNotEmpty() || domainRegex.isNotEmpty()) {
            // Domain fields of one headless rule are ORed together by sing-box.
            rules += JsonObject().apply {
                if (domain.isNotEmpty()) add("domain", domain.toJsonArray())
                if (domainSuffix.isNotEmpty()) add("domain_suffix", domainSuffix.toJsonArray())
                if (domainKeyword.isNotEmpty()) add("domain_keyword", domainKeyword.toJsonArray())
                if (domainRegex.isNotEmpty()) add("domain_regex", domainRegex.toJsonArray())
            }
        }
        if (ipCidr.isNotEmpty()) rules += JsonObject().apply { add("ip_cidr", ipCidr.toJsonArray()) }
        if (sourceIpCidr.isNotEmpty()) rules += JsonObject().apply { add("source_ip_cidr", sourceIpCidr.toJsonArray()) }
        if (ports.isNotEmpty() || portRanges.isNotEmpty()) {
            // port and port_range are different field groups (ANDed), so keep them apart.
            if (ports.isNotEmpty()) rules += JsonObject().apply { add("port", JsonArray().apply { ports.forEach { add(it) } }) }
            if (portRanges.isNotEmpty()) rules += JsonObject().apply { add("port_range", portRanges.toJsonArray()) }
        }
        rules += logical
        return Conversion(rules, converted, skipped, policies, finalPolicy, samples)
    }

    /** The rule-set file body sing-box loads with `type: local, format: source`. */
    fun toJson(conversion: Conversion): String = JsonObject().apply {
        addProperty("version", 2)
        add("rules", JsonArray().apply { conversion.rules.forEach { add(it) } })
    }.toString()

    // ------------------------------------------------------------------ line handling

    /** Strips comments, YAML list markers and quotes; null for lines that carry no rule. */
    internal fun cleanLine(raw: String): String? {
        var line = raw.trim()
        if (line.isEmpty() || line.startsWith("#") || line.startsWith("//") || line.startsWith(";")) return null
        if (line == "payload:" || line.startsWith("payload:")) return null
        if (line.startsWith("- ")) line = line.removePrefix("- ").trim()
        // Trailing inline comment ("DOMAIN,a.com // note"), before unquoting a YAML entry.
        line = line.substringBefore(" //").substringBefore(" #").trim()
        if (line.length >= 2 && (line.first() == '\'' || line.first() == '"') && line.last() == line.first()) {
            line = line.substring(1, line.length - 1).trim()
        }
        return line.ifEmpty { null }
    }

    /** Splits on commas outside parentheses: `AND,((A,b),(C,d)),DIRECT` → [AND, ((A,b),(C,d)), DIRECT]. */
    internal fun splitTopLevel(line: String): List<String> {
        val parts = ArrayList<String>()
        var depth = 0
        val current = StringBuilder()
        for (ch in line) {
            when {
                ch == '(' -> { depth++; current.append(ch) }
                ch == ')' -> { depth = maxOf(0, depth - 1); current.append(ch) }
                ch == ',' && depth == 0 -> { parts += current.toString(); current.clear() }
                else -> current.append(ch)
            }
        }
        parts += current.toString()
        return parts
    }

    private fun countPolicy(policies: MutableMap<String, Int>, raw: String?) {
        val policy = raw?.trim()?.uppercase()?.takeIf { it.isNotEmpty() && it != "NO-RESOLVE" } ?: "PROXY"
        val bucket = when (policy) {
            "DIRECT", "REJECT", "REJECT-DROP", "REJECT-TINYGIF", "REJECT-NO-DROP" ->
                if (policy.startsWith("REJECT")) "REJECT" else "DIRECT"
            else -> "PROXY"
        }
        policies[bucket] = (policies[bucket] ?: 0) + 1
    }

    // ------------------------------------------------------------------ logical rules

    /**
     * `((DOMAIN,a.com),(DST-PORT,443))` → sing-box logical rule. NOT takes exactly one
     * operand and becomes that operand with `invert: true`. Null when any operand is a
     * type sing-box cannot express (the whole rule is skipped, never partially applied).
     */
    private fun convertLogical(mode: String, operandsText: String): JsonObject? {
        val inner = operandsText.trim().removeSurrounding("(", ")")
        val operands = splitTopLevel(inner).map { it.trim() }.filter { it.isNotEmpty() }
        if (operands.isEmpty()) return null
        val converted = operands.map { operand ->
            convertOperand(operand.removeSurrounding("(", ")")) ?: return null
        }
        if (mode == "NOT") {
            if (converted.size != 1) return null
            return converted[0].deepCopy().apply { addProperty("invert", !(get("invert")?.asBoolean ?: false)) }
        }
        return JsonObject().apply {
            addProperty("type", "logical")
            addProperty("mode", mode.lowercase())
            add("rules", JsonArray().apply { converted.forEach { add(it) } })
        }
    }

    private fun convertOperand(text: String): JsonObject? {
        val parts = splitTopLevel(text)
        val type = parts[0].trim().uppercase()
        if (type == "AND" || type == "OR" || type == "NOT") {
            return parts.getOrNull(1)?.let { convertLogical(type, it) }
        }
        val value = parts.getOrNull(1)?.trim().orEmpty()
        fun single(key: String, v: String?) = v?.let { JsonObject().apply { add(key, JsonArray().apply { add(it) }) } }
        return when (type) {
            "DOMAIN" -> single("domain", normalizeDomain(value))
            "DOMAIN-SUFFIX" -> single("domain_suffix", normalizeDomain(value.removePrefix(".")))
            "DOMAIN-KEYWORD" -> single("domain_keyword", normalizeKeyword(value))
            "DOMAIN-REGEX" -> single("domain_regex", normalizeRegex(value))
            "DOMAIN-WILDCARD" -> single("domain_regex", wildcardToRegex(value))
            "IP-CIDR", "IP-CIDR6", "IP6-CIDR" -> single("ip_cidr", normalizeCidr(value))
            "SRC-IP", "SRC-IP-CIDR" -> single("source_ip_cidr", normalizeCidr(value))
            "DST-PORT", "DEST-PORT" -> {
                val ports = LinkedHashSet<Int>()
                val ranges = LinkedHashSet<String>()
                if (!addPort(value, ports, ranges)) return null
                JsonObject().apply {
                    if (ports.isNotEmpty()) add("port", JsonArray().apply { ports.forEach { add(it) } })
                    else add("port_range", ranges.toJsonArray())
                }
            }
            else -> null
        }
    }

    // ------------------------------------------------------------------ value validation

    private val hostnameLabel = Regex("^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$")

    internal fun normalizeDomain(value: String): String? {
        val host = value.trim().trimEnd('.').lowercase()
        if (host.isEmpty() || host.length > 253) return null
        val labels = host.split('.')
        if (labels.any { !hostnameLabel.matches(it) }) return null
        return host
    }

    private fun normalizeKeyword(value: String): String? {
        val keyword = value.trim().lowercase()
        return keyword.takeIf { it.isNotEmpty() && it.none { ch -> ch.isWhitespace() || ch == ',' } }
    }

    private fun normalizeRegex(value: String): String? {
        if (value.isEmpty()) return null
        return try {
            Regex(value)
            value.takeUnless { ClashRuleParser.hasUnsupportedRe2Syntax(it) }
        } catch (_: Exception) {
            null
        }
    }

    /** Surge DOMAIN-WILDCARD: `*` = any run of characters, `?` = one character. */
    private fun wildcardToRegex(value: String): String? {
        val pattern = value.trim().lowercase()
        if (pattern.isEmpty() || pattern.any { it.isWhitespace() || it == ',' }) return null
        val regex = buildString {
            append('^')
            pattern.forEach { ch ->
                when (ch) {
                    '*' -> append(".*")
                    '?' -> append('.')
                    else -> if (ch.isLetterOrDigit() || ch == '-' || ch == '_') append(ch) else append('\\').append(ch)
                }
            }
            append('$')
        }
        return regex
    }

    internal fun normalizeCidr(value: String): String? {
        val text = value.trim()
        val address = text.substringBefore('/')
        val prefixText = if (text.contains('/')) text.substringAfter('/') else null
        val isV6 = address.contains(':')
        if (isV6) {
            if (!isIpv6Literal(address)) return null
        } else if (!isIpv4Literal(address)) {
            return null
        }
        val max = if (isV6) 128 else 32
        val prefix = if (prefixText == null) max else prefixText.toIntOrNull() ?: return null
        if (prefix !in 0..max) return null
        return "${address.lowercase()}/$prefix"
    }

    private fun isIpv4Literal(address: String): Boolean {
        val octets = address.split('.')
        return octets.size == 4 && octets.all { part ->
            part.isNotEmpty() && part.length <= 3 && part.all(Char::isDigit) && part.toInt() <= 255
        }
    }

    private fun isIpv6Literal(address: String): Boolean {
        if (address.isEmpty() || address.count { it == ':' } < 2) return false
        if (address.split("::").size > 2) return false
        val groups = address.split(':')
        var hextets = 0
        groups.forEachIndexed { i, group ->
            when {
                group.isEmpty() -> Unit
                i == groups.lastIndex && group.contains('.') -> {
                    if (!isIpv4Literal(group)) return false
                    hextets += 2
                }
                group.length <= 4 && group.all { it.isDigit() || it.lowercaseChar() in 'a'..'f' } -> hextets++
                else -> return false
            }
        }
        return if (address.contains("::")) hextets < 8 else hextets == 8
    }

    /** `443`, `8000-9000` or `8000:9000` (Surge uses `-`, sing-box `:`). */
    private fun addPort(value: String, ports: MutableSet<Int>, ranges: MutableSet<String>): Boolean {
        val text = value.trim()
        text.toIntOrNull()?.let { port ->
            if (port !in 1..65535) return false
            ports += port
            return true
        }
        val bounds = text.split('-', ':')
        if (bounds.size != 2) return false
        val start = bounds[0].trim().toIntOrNull() ?: return false
        val end = bounds[1].trim().toIntOrNull() ?: return false
        if (start !in 1..65535 || end !in 1..65535 || start >= end) return false
        ranges += "$start:$end"
        return true
    }

    private fun Collection<String>.toJsonArray(): JsonArray = JsonArray().also { array -> forEach { array.add(it) } }
}
