package com.leikwan.bridge.ui.settings

import android.app.Application
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class SettingsViewModelRouteRuleLogoutTest {
    private lateinit var app: Application
    private lateinit var store: ClientRouteRuleStore

    @Before
    fun setUp() {
        app = ApplicationProvider.getApplicationContext()
        app.getSharedPreferences("client-route-rules", Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit()
        store = ClientRouteRuleStore.from(app)
    }

    @Test
    fun `logout clears client route rules through settings logout path`() {
        store.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto(id = "dir_sh_jp_1", label = "沪日1", status = "available", sortOrder = 10)),
            entitlements = emptyList(),
            rules = listOf(
                ClientRouteRuleDto(
                    id = "rule_1",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    protocol = "tcp_udp",
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = "active",
                    syncVersion = 1L,
                )
            ),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )

        SettingsViewModel(
            app = app,
            prefsOverride = Prefs(app.getSharedPreferences("settings-vm-logout-prefs", Context.MODE_PRIVATE)),
        ).logout()

        assertTrue(ClientRouteRuleStore.from(app).current().rules.isEmpty())
        assertTrue(ClientRouteRuleStore.from(app).current().directions.isEmpty())
    }
}
