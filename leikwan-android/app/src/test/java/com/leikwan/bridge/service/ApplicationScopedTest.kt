package com.leikwan.bridge.service

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ApplicationScopedTest {
    private val app: Context = ApplicationProvider.getApplicationContext()

    @Test
    fun sameApplicationReturnsTheExistingOwner() {
        val scoped = ApplicationScoped<Any>()
        var creates = 0

        val first = scoped.get(app) { creates += 1; Any() }
        val second = scoped.get(app) { creates += 1; Any() }

        assertSame(first, second)
        assertEquals(1, creates)
    }
}
