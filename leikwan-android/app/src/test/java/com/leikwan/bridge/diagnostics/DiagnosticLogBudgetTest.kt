package com.leikwan.bridge.diagnostics

import org.junit.Assert.*
import org.junit.Test

class DiagnosticLogBudgetTest {
    @Test
    fun `multiple batches share time budget and report exact suppressed count once`() {
        var now = 0L
        val budget = DiagnosticLogBudget(limit = 3, windowMs = 10_000, nowMs = { now })
        var emitted = 0
        repeat(7) { // Simulates log and connection callbacks sharing the same window.
            assertEquals(0L, budget.advance())
            repeat(5) { if (budget.admit()) emitted++ }
        }
        assertEquals(3, emitted)
        now = 9_999
        assertEquals(0L, budget.advance())
        assertFalse(budget.admit())
        now = 10_000
        assertEquals(33L, budget.advance())
        assertEquals(0L, budget.advance())
        assertTrue(budget.admit())
    }
}
