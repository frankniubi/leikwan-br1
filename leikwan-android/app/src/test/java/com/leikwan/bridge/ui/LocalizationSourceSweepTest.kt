package com.leikwan.bridge.ui

import java.nio.file.Files
import java.nio.file.Path
import kotlin.io.path.exists
import kotlin.io.path.readText
import org.junit.Assert.assertTrue
import org.junit.Test

class LocalizationSourceSweepTest {
    @Test
    fun `compose surfaces and notifications do not hardcode user-facing literals`() {
        val sourceRoot = Path.of("src/main/java/com/leikwan/bridge")
        assertTrue("Expected Android source root at $sourceRoot", sourceRoot.exists())

        val flagged = mutableListOf<String>()
        Files.walk(sourceRoot).use { paths ->
            paths
                .filter { it.toString().endsWith(".kt") }
                .filter { path ->
                    val normalized = path.toString().replace('\\', '/')
                    normalized.contains("/ui/") || normalized.endsWith("/service/LeikwanVpnService.kt")
                }
                .forEach { path ->
                    path.readText().lineSequence().forEachIndexed { index, line ->
                        val userFacingLiteral =
                            line.contains("Text(\"") ||
                                Regex("""contentDescription\s*=\s*"""").containsMatchIn(line) ||
                                Regex("""Icon\([^,\n]+,\s*"""").containsMatchIn(line) ||
                                Regex("""_snackbar\.value\s*=\s*"""").containsMatchIn(line) ||
                                Regex("""error\s*=\s*"""").containsMatchIn(line) ||
                                Regex("""lastStartError\s*=\s*"""").containsMatchIn(line)
                        if (userFacingLiteral) {
                            flagged += "${path}:${index + 1}:$line"
                        }
                    }
                }
            }

        assertTrue(
            "Found hardcoded user-facing literals:\n${flagged.joinToString("\n")}",
            flagged.isEmpty(),
        )
    }
}
