package com.leikwan.bridge.data.routing

import android.content.Context
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.leikwan.bridge.routing.SurgeListConverter
import java.io.File
import java.security.SecureRandom

/**
 * Imported Surge/Clash rule lists. Each list is one sing-box source rule-set file
 * (`<id>.json`) plus an index entry; the routing rule that applies it is an ordinary
 * `IMPORTED_LIST` row in `user_routing_rules` (outcome, enable toggle, node pin live there).
 *
 * Files, not Room: a ChinaMax-sized list is ~1 MB of JSON that sing-box reads by path.
 */
class ImportedRuleListStore(private val dir: File, private val gson: Gson = Gson()) {

    data class ImportedRuleList(
        val id: String,
        val name: String,
        val sourceUrl: String? = null,
        val ruleCount: Int = 0,
        val skippedCount: Int = 0,
        val finalPolicy: String? = null,
        val importedAtMs: Long = 0L,
    )

    @Synchronized
    fun all(): List<ImportedRuleList> {
        val index = File(dir, INDEX_FILE)
        if (!index.isFile) return emptyList()
        return runCatching {
            gson.fromJson<List<ImportedRuleList>>(index.readText(), listType) ?: emptyList()
        }.getOrDefault(emptyList())
    }

    fun get(id: String): ImportedRuleList? = all().firstOrNull { it.id == id }

    /** The rule-set file of [id]; exists only for a stored list. */
    fun fileFor(id: String): File = File(dir, "$id.json")

    /** Absolute path sing-box loads, or null when the list (or its file) is gone. */
    fun pathFor(id: String): String? =
        fileFor(id).takeIf { isValidId(id) && it.isFile }?.absolutePath

    /** Writes a new list (or replaces [existingId]'s contents, e.g. a refresh). */
    @Synchronized
    fun save(
        name: String,
        sourceUrl: String?,
        conversion: SurgeListConverter.Conversion,
        nowMs: Long,
        existingId: String? = null,
    ): ImportedRuleList {
        dir.mkdirs()
        val id = existingId?.takeIf { isValidId(it) } ?: newId()
        writeAtomically(fileFor(id), SurgeListConverter.toJson(conversion))
        val entry = ImportedRuleList(
            id = id,
            name = name.trim().ifEmpty { sourceUrl?.substringAfterLast('/')?.ifEmpty { null } ?: id },
            sourceUrl = sourceUrl,
            ruleCount = conversion.convertedCount,
            skippedCount = conversion.skippedCount,
            finalPolicy = conversion.finalPolicy,
            importedAtMs = nowMs,
        )
        writeIndex(all().filterNot { it.id == id } + entry)
        return entry
    }

    @Synchronized
    fun delete(id: String) {
        if (!isValidId(id)) return
        fileFor(id).delete()
        writeIndex(all().filterNot { it.id == id })
    }

    private fun writeIndex(entries: List<ImportedRuleList>) {
        dir.mkdirs()
        writeAtomically(File(dir, INDEX_FILE), gson.toJson(entries))
    }

    private fun writeAtomically(target: File, text: String) {
        val tmp = File(target.parentFile, target.name + ".tmp")
        tmp.writeText(text)
        if (!tmp.renameTo(target)) {
            target.delete()
            check(tmp.renameTo(target)) { "could not write ${target.name}" }
        }
    }

    companion object {
        private const val INDEX_FILE = "index.json"
        private val listType = object : TypeToken<List<ImportedRuleList>>() {}.type
        private val idPattern = Regex("^l[0-9a-f]{12}$")
        private val random = SecureRandom()

        fun from(context: Context): ImportedRuleListStore =
            ImportedRuleListStore(File(context.filesDir, "rule-lists"))

        /** Ids double as file names and sing-box tag suffixes, so they stay `l` + 12 hex. */
        fun isValidId(id: String): Boolean = idPattern.matches(id)

        private fun newId(): String {
            val bytes = ByteArray(6).also(random::nextBytes)
            return "l" + bytes.joinToString("") { "%02x".format(it) }
        }
    }
}
