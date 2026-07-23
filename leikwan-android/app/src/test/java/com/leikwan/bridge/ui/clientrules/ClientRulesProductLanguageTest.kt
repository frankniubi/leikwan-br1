package com.leikwan.bridge.ui.clientrules

import java.io.File
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
}
