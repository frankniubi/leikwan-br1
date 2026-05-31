package com.leikwan.bridge.routing

import java.text.Normalizer

data class Country(
    val code: String,
    val nameEn: String,
    val nameZh: String,
)

object CountryCatalog {
    private val countries = listOf(
        Country("CN", "China", "中国"),
        Country("HK", "Hong Kong", "香港"),
        Country("MO", "Macao", "澳门"),
        Country("TW", "Taiwan", "台湾"),
        Country("JP", "Japan", "日本"),
        Country("KR", "South Korea", "韩国"),
        Country("SG", "Singapore", "新加坡"),
        Country("MY", "Malaysia", "马来西亚"),
        Country("TH", "Thailand", "泰国"),
        Country("VN", "Vietnam", "越南"),
        Country("PH", "Philippines", "菲律宾"),
        Country("ID", "Indonesia", "印度尼西亚"),
        Country("IN", "India", "印度"),
        Country("US", "United States", "美国"),
        Country("CA", "Canada", "加拿大"),
        Country("GB", "United Kingdom", "英国"),
        Country("DE", "Germany", "德国"),
        Country("FR", "France", "法国"),
        Country("IT", "Italy", "意大利"),
        Country("ES", "Spain", "西班牙"),
        Country("NL", "Netherlands", "荷兰"),
        Country("CH", "Switzerland", "瑞士"),
        Country("SE", "Sweden", "瑞典"),
        Country("NO", "Norway", "挪威"),
        Country("FI", "Finland", "芬兰"),
        Country("DK", "Denmark", "丹麦"),
        Country("AU", "Australia", "澳大利亚"),
        Country("NZ", "New Zealand", "新西兰"),
        Country("BR", "Brazil", "巴西"),
        Country("MX", "Mexico", "墨西哥"),
        Country("AR", "Argentina", "阿根廷"),
        Country("CL", "Chile", "智利"),
        Country("RU", "Russia", "俄罗斯"),
        Country("TR", "Turkey", "土耳其"),
        Country("AE", "United Arab Emirates", "阿联酋"),
        Country("SA", "Saudi Arabia", "沙特阿拉伯"),
        Country("ZA", "South Africa", "南非"),
        Country("EG", "Egypt", "埃及"),
        Country("CI", "Côte d'Ivoire", "科特迪瓦"),
    )

    fun all(): List<Country> = countries

    fun search(query: String, limit: Int = 12): List<Country> {
        val raw = query.trim()
        val normalized = normalize(query)
        if (raw.isBlank()) return emptyList()
        return countries
            .filter { country ->
                (normalized.isNotBlank() && normalize(country.code).contains(normalized)) ||
                    (normalized.isNotBlank() && normalize(country.nameEn).contains(normalized)) ||
                    country.nameZh.contains(raw)
            }
            .sortedWith(compareBy<Country> {
                when {
                    normalize(it.code) == normalized -> 0
                    normalize(it.nameEn).startsWith(normalized) -> 1
                    it.nameZh.startsWith(raw) -> 2
                    else -> 3
                }
            }.thenBy { it.nameEn })
            .take(limit)
    }

    fun nameFor(code: String, zh: Boolean): String =
        countries.firstOrNull { it.code.equals(code, ignoreCase = true) }
            ?.let { if (zh) it.nameZh else it.nameEn }
            ?: code.uppercase()

    private fun normalize(value: String): String =
        Normalizer.normalize(value, Normalizer.Form.NFD)
            .replace("\\p{Mn}+".toRegex(), "")
            .replace("[^A-Za-z0-9]+".toRegex(), " ")
            .trim()
            .lowercase()
}
