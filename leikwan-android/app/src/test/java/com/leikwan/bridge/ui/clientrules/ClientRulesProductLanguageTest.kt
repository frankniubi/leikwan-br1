package com.leikwan.bridge.ui.clientrules

import com.leikwan.bridge.R
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ClientRulesProductLanguageTest {

    @Test
    fun `new Client Rules UI and resources avoid internal product terms`() {
        val root = File(requireNotNull(System.getProperty("user.dir")))
        val files = mutableListOf<File>()
        files += File(root, "src/main/java/com/leikwan/bridge/ui/clientrules").walkTopDown()
            .filter { it.isFile && it.extension == "kt" }
            .toList()
        files += listOf(
            File(root, "src/main/res/values/strings.xml"),
            File(root, "src/main/res/values-zh-rCN/strings.xml"),
        )

        val scannedLines = files.flatMap { file ->
            file.readLines().mapIndexedNotNull { index, line ->
                val trimmed = line.trim()
                val shouldScan = file.path.contains("ui${File.separator}clientrules") ||
                    trimmed.contains("name=\"route_direction_") ||
                    trimmed.contains("name=\"client_rule_") ||
                    trimmed.contains("name=\"client_rules_") ||
                    trimmed.contains("name=\"nav_client_rules")
                if (shouldScan) "${file.relativeTo(root).path}:${index + 1}:$line" else null
            }
        }

        assertTrue("Expected new Client Rules UI/resource lines to be scanned", scannedLines.isNotEmpty())
        val forbidden = Regex(
            pattern = "leigod|current_mode|cf_mode|forward_combination|cf_location_group|\\bchain\\b|slave group|sidecar|internal line|listener port|runtime mode",
            option = RegexOption.IGNORE_CASE,
        )
        val offenders = scannedLines.filter { forbidden.containsMatchIn(it) }
        assertFalse("Forbidden internal terms found:\n${offenders.joinToString("\n")}", offenders.isNotEmpty())
    }

    @Test
    fun `navigation keeps Routing destination while adding Client Rules`() {
        val routes = com.leikwan.bridge.ui.nav.Screen.entries.map { it.route }

        assertTrue("Existing Routing destination must remain available", "routing" in routes)
        assertTrue("Client Rules destination must be available", "client-rules" in routes)
    }

    @Test
    fun `Client Rules UI never falls back to raw Route Direction identifiers`() {
        val root = File(requireNotNull(System.getProperty("user.dir")))
        val screenSource = File(
            root,
            "src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt",
        ).readText()
        val rawDirectionFallback = Regex("\\?:\\s*rule\\.directionId\\b|else\\s*->\\s*rule\\.directionId\\b")

        assertFalse(
            "Client Rules UI must use a product-safe Route Direction fallback instead of raw identifiers",
            rawDirectionFallback.containsMatchIn(screenSource),
        )
    }

    @Test
    fun `Client Rules UI never falls back to raw error codes or messages`() {
        val root = File(requireNotNull(System.getProperty("user.dir")))
        val screenSource = File(
            root,
            "src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt",
        ).readText()
        val rawErrorFallback = Regex("else\\s*->\\s*this\\b")

        assertFalse(
            "Unknown state.errorCode and rule.pendingError values must use a localized Client Rule message instead of rendering verbatim",
            rawErrorFallback.containsMatchIn(screenSource),
        )
        assertEquals(R.string.client_rule_error_generic, "backend_internal_code".clientRuleMessageRes())
        assertEquals(R.string.client_rule_error_generic, "raw backend failure: forward_combination missing".clientRuleMessageRes())
    }
}
