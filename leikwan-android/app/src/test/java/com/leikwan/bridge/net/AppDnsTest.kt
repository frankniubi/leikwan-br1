package com.leikwan.bridge.net

import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

class AppDnsTest {

    @Test
    fun `shared resolver is built once and memoised`() {
        // Constructing the resolver must not perform any network I/O, and repeated calls
        // must return the same instance (so OkHttp clients share one DoH bootstrap pool).
        assertSame(AppDns.shared(), AppDns.shared())
    }

    @Test
    fun `shared resolver is a fallback chain`() {
        assertTrue(AppDns.shared() is FallbackDns)
    }
}
