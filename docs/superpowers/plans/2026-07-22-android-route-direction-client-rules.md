# Android Route Direction Client Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Android support for Route Directions and server-synced exact-destination Client Rules without exposing runtime construction terms in the upgraded app surface.

**Architecture:** Android consumes the additive `/api/client/configs/pull` route-rule fields, stores them in a dedicated file-backed cache, and keeps the existing forward/listener runtime as the transport substrate. Direction runtime bindings use the shared nested contract: `service_scope_id`, `direction_id`, `binding_state`, opaque `client_runtime_binding_id`, nested `local_endpoint { host, tcp_port, udp_port }`, `tcp_ready`, `udp_ready`, and `runtime_ref`. `TunnelOrchestrator` starts any helper runtime required by active Client Rules, then `SingboxConfigBuilder` inserts exact IP:PORT rules after protected control-plane/runtime reachability rules and before country/category/default user rules. This plan intentionally does **not** add Room entities or an `AppDatabase` migration; Client Rules are server-authoritative and are cached in `SharedPreferences` JSON with explicit pending create/update/tombstone state.

**Tech Stack:** Kotlin, Retrofit/Gson, SharedPreferences file-backed JSON cache, coroutines/StateFlow, existing Android runtime runners, sing-box TUN route JSON, Jetpack Compose, Robolectric/JUnit tests run with `.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest`.

---

## Implementation rules

- Scope is Android-only under `leikwan-android/` plus this plan document. Do not change PHP backend, Linux client-agent, Windows Desktop, or production deployment in this plan.
- New user-facing app surfaces must use only product terms: `Route Direction`, `Client Rule`, destination, quota, status, enabled, active, unavailable, syncing, saved.
- New route-rule UI/resources must not expose these implementation terms: `LeiGod`, `current_mode`, `cf_mode`, `forward_combination`, `cf_location_group`, `chain`, `slave group`, `sidecar`, `internal line`, `listener port`, `runtime mode`.
- Existing legacy strings outside the new Client Rules surface may keep their current wording. The hidden-term tests below scan only the new UI package and the new `route_direction_*` / `client_rule_*` resource lines.
- Route-rule sing-box order is locked: sniff and DNS handling first, protected control-plane rules second, runtime reachability bypass rules third, **Client Rules fourth**, country/category/default user routing after Client Rules.
- Client Rules are exact IPv4 + port in V1. The app rejects local form entries that are not IPv4 literals or ports in `1..65535`; server validation remains authoritative.
- Client Rules use fixed protocol `tcp_udp`; Android emits separate TCP and UDP sing-box route entries so both protocols are enforced.
- Enabled rules that are not installable are fail-closed through sing-box `action = "reject"` rules.
- Helper forwards may be cached and used by runtime planning, but they must not appear in service selection UI.
- Direction runtime binding pull data must use the shared nested contract only: `service_scope_id`, `direction_id`, `binding_state`, `client_runtime_binding_id`, `runtime_ref`, `local_endpoint { host, tcp_port, udp_port }`, `tcp_ready`, and `udp_ready`. Android DTOs, samples, and pull parsing must not define or consume legacy flat local TCP/UDP port fields.
- `client_runtime_binding_id` is an opaque server identity. Android may use it only as a local runtime/process/outbound identity key; it must never be displayed in UI and must never be accepted by create/update/delete write APIs.
- Runtime process keys and sing-box outbound tags must be derived from binding identity: prefer `client_runtime_binding_id`; if absent, fall back to `service_scope_id + "::" + direction_id`. Do not key runtime processes or outbounds by direction alone.
- Snapshot binding lookup must be keyed by both service scope and direction: `bindingFor(serviceScopeId, directionId)`.
- Commit after every task. Commit commands below include the required co-author trailer.

## File structure

Create:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/clientrules/ClientRouteRuleModels.kt` — product-domain models, DTO mapping, exact-destination validation, effective-state enums, runtime binding models.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStore.kt` — file-backed JSON cache with pull replacement and pending create/update/tombstone lifecycle.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlanner.kt` — maps active Client Rules and ready direction bindings to helper forward runtime contexts and sing-box outbound bindings.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt` — Client Rules UI state, validation, sync actions, and repository/store orchestration.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt` — Compose list/create/edit/tombstone surface using product-only language.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/api/RouteRulePullModelsTest.kt` — Retrofit/Gson pull model and capability parsing tests.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStoreTest.kt` — file-backed store lifecycle tests.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryRouteRuleSyncTest.kt` — repository capability heartbeat, pull persistence, and write lifecycle tests with a fake `PanelApi`.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlannerTest.kt` — helper-forward planning and fail-closed readiness tests.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModelTest.kt` — ViewModel validation and repository/store action tests.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesProductLanguageTest.kt` — source/resource product-terminology guard.

Modify:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt` — additive route-rule DTOs, heartbeat capability body, write request/response models.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt` — heartbeat body, client-rule create/update/delete endpoints.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt` — injectable API/store seam, capability advertisement before pull, pull cache replacement, write lifecycle methods.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt` — parse `route_rule_helper_only` so helper forwards can be cached but hidden from selection.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt` — exclude helper forwards from `visibleForwards` and selected service choice.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt` — add a defaulted `routeRuleProcessKey: Long?` metadata field while keeping existing constructors backward compatible.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt` — load route-rule snapshot, start helper runtimes, pass runtime bindings into sing-box builder, stop helper runtimes with the base tunnel.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt` — emit route-rule outbounds and ordered exact IP:PORT route/reject rules.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt` — add Client Rules navigation entry.
- `leikwan-android/app/src/main/res/values/strings.xml` — English product strings for Client Rules.
- `leikwan-android/app/src/main/res/values-zh-rCN/strings.xml` — Simplified Chinese product strings for Client Rules.

Do not modify:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt` — no Room migration in this plan.
- Existing `UserRoutingRuleStore` country/category routing tables — Client Rules are a separate server-synced feature.

---

### Task 1: Pull models, heartbeat capability body, and API write endpoints

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/api/RouteRulePullModelsTest.kt`

- [ ] **Step 1: Write the failing pull-model parsing test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/api/RouteRulePullModelsTest.kt`:

```kotlin
package com.leikwan.bridge.api

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class RouteRulePullModelsTest {
    private val gson = Gson()

    @Test
    fun `old pull payload remains parseable without route rule fields`() {
        val parsed = gson.fromJson(
            """
            {
              "ok": true,
              "client": {"id": 7, "name": "phone"},
              "forwards": []
            }
            """.trimIndent(),
            PullConfigsResponse::class.java,
        )

        assertTrue(parsed.ok)
        assertEquals(0, parsed.routeDirections.orEmpty().size)
        assertEquals(0, parsed.routeRuleEntitlements.orEmpty().size)
        assertEquals(0, parsed.clientRules.orEmpty().size)
        assertEquals(0, parsed.directionRuntimeBindings.orEmpty().size)
        assertNull(parsed.routeRuleCapabilities)
    }

    @Test
    fun `upgraded pull payload parses route directions entitlements rules bindings and capabilities`() {
        val parsed = gson.fromJson(
            """
            {
              "ok": true,
              "client": {"id": 7, "name": "phone"},
              "forwards": [],
              "route_directions": [
                {"id":"dir_sh_jp_1","label":"沪日1","status":"available","sort_order":10}
              ],
              "route_rule_entitlements": [
                {"service_scope_id":"svc_scope_7","service_id":7,"subscription_id":7007,"max_rules":5,"remaining_rules":4,"allowed_direction_ids":["dir_sh_jp_1"]}
              ],
              "client_rules": [
                {"id":"rule_abc","service_scope_id":"svc_scope_7","destination_ip":"93.184.216.34","destination_port":443,"protocol":"tcp_udp","direction_id":"dir_sh_jp_1","enabled":true,"effective_state":"active","sync_version":3}
              ],
              "direction_runtime_bindings": [
                {"service_scope_id":"svc_scope_7","direction_id":"dir_sh_jp_1","binding_state":"ready","client_runtime_binding_id":"crb_svc7_dir_sh_jp_1","local_endpoint":{"host":"127.0.0.1","tcp_port":12001,"udp_port":12001},"tcp_ready":true,"udp_ready":true,"runtime_ref":{"type":"forward_ref","forward_id":77,"selectable":false,"transport":"tcp_udp"}}
              ],
              "capabilities": {"route_direction_rules":true,"exact_destination_rules":true}
            }
            """.trimIndent(),
            PullConfigsResponse::class.java,
        )

        val direction = parsed.routeDirections.orEmpty().single()
        assertEquals("dir_sh_jp_1", direction.id)
        assertEquals("沪日1", direction.label)
        assertEquals("available", direction.status)
        assertEquals(10, direction.sortOrder)

        val entitlement = parsed.routeRuleEntitlements.orEmpty().single()
        assertEquals("svc_scope_7", entitlement.serviceScopeId)
        assertEquals(5, entitlement.maxRules)
        assertEquals(4, entitlement.remainingRules)
        assertEquals(listOf("dir_sh_jp_1"), entitlement.allowedDirectionIds)

        val rule = parsed.clientRules.orEmpty().single()
        assertEquals("rule_abc", rule.id)
        assertEquals("93.184.216.34", rule.destinationIp)
        assertEquals(443, rule.destinationPort)
        assertEquals("tcp_udp", rule.protocol)
        assertEquals("active", rule.effectiveState)
        assertEquals(3L, rule.syncVersion)

        val binding = parsed.directionRuntimeBindings.orEmpty().single()
        assertEquals("svc_scope_7", binding.serviceScopeId)
        assertEquals("dir_sh_jp_1", binding.directionId)
        assertEquals("ready", binding.bindingState)
        assertEquals("crb_svc7_dir_sh_jp_1", binding.clientRuntimeBindingId)
        assertEquals("127.0.0.1", binding.localEndpoint?.host)
        assertEquals(12001, binding.localEndpoint?.tcpPort)
        assertEquals(12001, binding.localEndpoint?.udpPort)
        assertEquals(true, binding.tcpReady)
        assertEquals(true, binding.udpReady)
        assertNotNull(binding.runtimeRef)
        assertEquals(77L, binding.runtimeRef?.forwardId)
        assertFalse(binding.runtimeRef?.selectable ?: true)

        assertTrue(parsed.routeRuleCapabilities?.routeDirectionRules == true)
        assertTrue(parsed.routeRuleCapabilities?.exactDestinationRules == true)
    }

    @Test
    fun `write response parses stable server error and successful rule`() {
        val error = gson.fromJson(
            """
            {"ok":false,"error":"duplicate_destination","message":"该目标已存在规则"}
            """.trimIndent(),
            ClientRuleWriteResponse::class.java,
        )
        assertFalse(error.ok)
        assertEquals("duplicate_destination", error.error)
        assertEquals("该目标已存在规则", error.message)

        val success = gson.fromJson(
            """
            {"ok":true,"client_rule":{"id":"rule_abc","service_scope_id":"svc_scope_7","destination_ip":"93.184.216.34","destination_port":443,"protocol":"tcp_udp","direction_id":"dir_sh_jp_1","enabled":true,"effective_state":"active","sync_version":1}}
            """.trimIndent(),
            ClientRuleWriteResponse::class.java,
        )
        assertTrue(success.ok)
        assertEquals("rule_abc", success.clientRule?.id)
        assertEquals(1L, success.clientRule?.syncVersion)
    }
}
```

- [ ] **Step 2: Run the model test and verify it fails**

Run from the repository worktree root:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.RouteRulePullModelsTest"
```

Expected: FAIL with unresolved references such as `routeDirections`, `RouteRuleCapabilitiesDto`, or `ClientRuleWriteResponse`.

- [ ] **Step 3: Add additive DTOs and heartbeat/write models**

In `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`, replace `PullConfigsResponse` and append the new DTOs below `HeartbeatResponse`:

```kotlin
data class PullConfigsResponse(
    val ok: Boolean,
    val client: JsonElement,
    val forwards: List<JsonElement>,
    @SerializedName("route_directions") val routeDirections: List<RouteDirectionDto>? = null,
    @SerializedName("route_rule_entitlements") val routeRuleEntitlements: List<RouteRuleEntitlementDto>? = null,
    @SerializedName("client_rules") val clientRules: List<ClientRouteRuleDto>? = null,
    @SerializedName("direction_runtime_bindings") val directionRuntimeBindings: List<DirectionRuntimeBindingDto>? = null,
    @SerializedName("capabilities") val routeRuleCapabilities: RouteRuleCapabilitiesDto? = null,
)

data class HeartbeatRequest(
    @SerializedName("client_platform") val clientPlatform: String = "android",
    @SerializedName("supports_route_direction_rules") val supportsRouteDirectionRules: Boolean = true,
    @SerializedName("supports_exact_destination_rules") val supportsExactDestinationRules: Boolean = true,
)

data class HeartbeatResponse(val ok: Boolean)

data class RouteDirectionDto(
    val id: String = "",
    val label: String = "",
    val status: String = "",
    @SerializedName("sort_order") val sortOrder: Int = 100,
)

data class RouteRuleEntitlementDto(
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("service_id") val serviceId: Long = 0L,
    @SerializedName("subscription_id") val subscriptionId: Long? = null,
    @SerializedName("max_rules") val maxRules: Int = 0,
    @SerializedName("remaining_rules") val remainingRules: Int = 0,
    @SerializedName("allowed_direction_ids") val allowedDirectionIds: List<String> = emptyList(),
)

data class ClientRouteRuleDto(
    val id: String = "",
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("destination_ip") val destinationIp: String = "",
    @SerializedName("destination_port") val destinationPort: Int = 0,
    val protocol: String = "tcp_udp",
    @SerializedName("direction_id") val directionId: String = "",
    val enabled: Boolean = false,
    @SerializedName("effective_state") val effectiveState: String = "unavailable",
    @SerializedName("sync_version") val syncVersion: Long = 0L,
)

data class DirectionRuntimeBindingDto(
    @SerializedName("service_scope_id") val serviceScopeId: String = "",
    @SerializedName("direction_id") val directionId: String = "",
    @SerializedName("binding_state") val bindingState: String = "missing",
    @SerializedName("client_runtime_binding_id") val clientRuntimeBindingId: String? = null,
    @SerializedName("local_endpoint") val localEndpoint: DirectionLocalEndpointDto? = null,
    @SerializedName("tcp_ready") val tcpReady: Boolean = false,
    @SerializedName("udp_ready") val udpReady: Boolean = false,
    @SerializedName("runtime_ref") val runtimeRef: DirectionRuntimeRefDto? = null,
)

data class DirectionLocalEndpointDto(
    val host: String = "",
    @SerializedName("tcp_port") val tcpPort: Int = 0,
    @SerializedName("udp_port") val udpPort: Int = 0,
)

data class DirectionRuntimeRefDto(
    val type: String = "",
    @SerializedName("forward_id") val forwardId: Long? = null,
    val selectable: Boolean = false,
    val transport: String = "tcp_udp",
)

data class RouteRuleCapabilitiesDto(
    @SerializedName("route_direction_rules") val routeDirectionRules: Boolean? = null,
    @SerializedName("exact_destination_rules") val exactDestinationRules: Boolean? = null,
)

data class ClientRuleWriteRequest(
    @SerializedName("service_scope_id") val serviceScopeId: String? = null,
    @SerializedName("destination_ip") val destinationIp: String? = null,
    @SerializedName("destination_port") val destinationPort: Int? = null,
    @SerializedName("direction_id") val directionId: String? = null,
    val enabled: Boolean? = null,
    @SerializedName("sync_version") val syncVersion: Long? = null,
)

data class ClientRuleWriteResponse(
    val ok: Boolean,
    val error: String? = null,
    val message: String? = null,
    @SerializedName("client_rule") val clientRule: ClientRouteRuleDto? = null,
)
```

Keep the existing `ForwardInfo` class below these declarations unchanged.

- [ ] **Step 4: Add Retrofit endpoint declarations**

In `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt`, add these imports:

```kotlin
import retrofit2.http.DELETE
import retrofit2.http.PUT
```

Replace the heartbeat method and add the Client Rule write endpoints below `pullConfigs()`:

```kotlin
@POST("api/client/heartbeat")
suspend fun heartbeat(
    @Header("Authorization") auth: String,
    @Body request: HeartbeatRequest = HeartbeatRequest(),
): HeartbeatResponse

@GET("api/client/configs/pull")
suspend fun pullConfigs(@Header("Authorization") auth: String): PullConfigsResponse

@POST("api/client/client-rules")
suspend fun createClientRule(
    @Header("Authorization") auth: String,
    @Body request: ClientRuleWriteRequest,
): Response<ClientRuleWriteResponse>

@PUT("api/client/client-rules/{id}")
suspend fun updateClientRule(
    @Header("Authorization") auth: String,
    @Path("id") ruleId: String,
    @Body request: ClientRuleWriteRequest,
): Response<ClientRuleWriteResponse>

@DELETE("api/client/client-rules/{id}")
suspend fun deleteClientRule(
    @Header("Authorization") auth: String,
    @Path("id") ruleId: String,
): Response<ClientRuleWriteResponse>
```

The file should still contain the existing `login`, `selectClient`, and `getForwardConfig` declarations.

- [ ] **Step 5: Run the model test and verify it passes**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.RouteRulePullModelsTest"
```

Expected: PASS.

- [ ] **Step 6: Commit model/API changes**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt leikwan-android/app/src/test/java/com/leikwan/bridge/api/RouteRulePullModelsTest.kt
git commit -m "feat(android): parse route direction client rule API models" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Domain models and file-backed Client Rule store

**Files:**
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/clientrules/ClientRouteRuleModels.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStore.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStoreTest.kt`

- [ ] **Step 1: Write the failing store lifecycle test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStoreTest.kt`:

```kotlin
package com.leikwan.bridge.data.clientrules

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionLocalEndpointDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.DirectionRuntimeRefDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ClientRouteRuleStoreTest {
    private lateinit var context: Context
    private lateinit var store: ClientRouteRuleStore
    private var now = 1_000L

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        context.getSharedPreferences("client-route-rule-store-test", Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit()
        store = ClientRouteRuleStore(
            prefs = context.getSharedPreferences("client-route-rule-store-test", Context.MODE_PRIVATE),
            clock = { now },
        )
    }

    @Test
    fun `replaceFromPull stores directions entitlements rules bindings and capabilities`() {
        store.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            entitlements = listOf(RouteRuleEntitlementDto("svc_scope_7", 7L, 7007L, 5, 4, listOf("dir_sh_jp_1"))),
            rules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 2L)),
            bindings = listOf(DirectionRuntimeBindingDto("svc_scope_7", "dir_sh_jp_1", "ready", "crb_svc7_dir_sh_jp_1", DirectionLocalEndpointDto("127.0.0.1", 12001, 12001), true, true, DirectionRuntimeRefDto("forward_ref", 77L, false, "tcp_udp"))),
            capabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )

        val snapshot = store.current()
        assertTrue(snapshot.capabilities.routeDirectionRules)
        assertTrue(snapshot.capabilities.exactDestinationRules)
        assertEquals("沪日1", snapshot.directions.single().label)
        assertEquals(5, snapshot.entitlements.single().maxRules)
        assertEquals(ClientRuleEffectiveState.ACTIVE, snapshot.rules.single().effectiveState)
        assertEquals(12001, snapshot.bindings.single().localTcpPort)
    }

    @Test
    fun `pending create update and tombstone survive pull replacement until server ack`() {
        val local = store.markPendingCreate(
            ClientRuleWriteDraft(
                serviceScopeId = "svc_scope_7",
                destinationIp = "93.184.216.34",
                destinationPort = 443,
                directionId = "dir_sh_jp_1",
                enabled = true,
            )
        )
        assertTrue(local.id.startsWith("local_"))
        assertEquals(PendingClientRuleOperation.CREATE, local.pendingOperation)

        store.replaceFromPull(
            routeDirections = emptyList(),
            entitlements = emptyList(),
            rules = emptyList(),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )
        assertEquals(PendingClientRuleOperation.CREATE, store.current().rules.single().pendingOperation)

        store.applyServerRule(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L), local.id)
        assertEquals("rule_1", store.current().rules.single().id)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)

        store.markPendingUpdate("rule_1", ClientRuleWriteDraft(serviceScopeId = null, destinationIp = null, destinationPort = null, directionId = "dir_sh_jp_2", enabled = false, syncVersion = 1L))
        assertEquals(PendingClientRuleOperation.UPDATE, store.current().rules.single().pendingOperation)
        assertEquals("dir_sh_jp_2", store.current().rules.single().directionId)
        assertFalse(store.current().rules.single().enabled)

        store.markPendingTombstone("rule_1")
        val tombstone = store.current().rules.single()
        assertEquals(PendingClientRuleOperation.DELETE, tombstone.pendingOperation)
        assertNotNull(tombstone.deletedAtMs)
        assertTrue(store.activeRulesForScope("svc_scope_7").isEmpty())
    }

    @Test
    fun `invalid exact destinations are rejected before local pending writes`() {
        assertEquals("invalid_destination_ip", ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "example.com", 443, "dir", true)).error)
        assertEquals("invalid_destination_port", ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "93.184.216.34", 0, "dir", true)).error)
        assertNull(ClientRouteRuleStore.validateDraft(ClientRuleWriteDraft("svc", "93.184.216.34", 443, "dir", true)).error)
    }
}
```

- [ ] **Step 2: Run the store test and verify it fails**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.data.clientrules.ClientRouteRuleStoreTest"
```

Expected: FAIL with unresolved references such as `ClientRouteRuleStore`, `ClientRuleWriteDraft`, or `PendingClientRuleOperation`.

- [ ] **Step 3: Add domain models**

Create `leikwan-android/app/src/main/java/com/leikwan/bridge/clientrules/ClientRouteRuleModels.kt`:

```kotlin
package com.leikwan.bridge.clientrules

import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto

enum class ClientRuleEffectiveState(val wireValue: String) {
    ACTIVE("active"),
    DISABLED("disabled"),
    UNAVAILABLE("unavailable"),
    NOT_ALLOWED_BY_PLAN("not_allowed_by_plan"),
    OVER_QUOTA("over_quota"),
    RUNTIME_MISSING("runtime_missing"),
    SYNCING("syncing"),
    ERROR("error");

    companion object {
        fun fromWire(value: String?): ClientRuleEffectiveState = entries.firstOrNull { it.wireValue == value } ?: UNAVAILABLE
    }
}

enum class PendingClientRuleOperation(val wireValue: String) {
    NONE("none"),
    CREATE("create"),
    UPDATE("update"),
    DELETE("delete");

    companion object {
        fun fromWire(value: String?): PendingClientRuleOperation = entries.firstOrNull { it.wireValue == value } ?: NONE
    }
}

data class RouteDirection(
    val id: String,
    val label: String,
    val status: String,
    val sortOrder: Int,
)

data class RouteRuleEntitlement(
    val serviceScopeId: String,
    val serviceId: Long,
    val subscriptionId: Long?,
    val maxRules: Int,
    val remainingRules: Int,
    val allowedDirectionIds: List<String>,
)

data class ClientRouteRule(
    val id: String,
    val serviceScopeId: String,
    val destinationIp: String,
    val destinationPort: Int,
    val protocol: String = "tcp_udp",
    val directionId: String,
    val enabled: Boolean,
    val effectiveState: ClientRuleEffectiveState,
    val syncVersion: Long,
    val pendingOperation: PendingClientRuleOperation = PendingClientRuleOperation.NONE,
    val pendingError: String? = null,
    val deletedAtMs: Long? = null,
    val updatedAtMs: Long,
) {
    val installable: Boolean
        get() = enabled && deletedAtMs == null && pendingOperation != PendingClientRuleOperation.DELETE && effectiveState == ClientRuleEffectiveState.ACTIVE
}

data class DirectionRuntimeBinding(
    val serviceScopeId: String,
    val directionId: String,
    val bindingState: String,
    val clientRuntimeBindingId: String?,
    val localEndpointHost: String,
    val localTcpPort: Int,
    val localUdpPort: Int,
    val tcpReady: Boolean,
    val udpReady: Boolean,
    val runtimeForwardId: Long?,
    val selectable: Boolean,
    val transport: String,
) {
    val bindingIdentity: String
        get() = clientRuntimeBindingId?.takeIf { it.isNotBlank() } ?: "$serviceScopeId::$directionId"

    val readyForAndroid: Boolean
        get() = bindingState == "ready" &&
            tcpReady &&
            udpReady &&
            runtimeForwardId != null &&
            localEndpointHost == "127.0.0.1" &&
            localTcpPort in 1..65535 &&
            localUdpPort in 1..65535 &&
            transport == "tcp_udp"
}

data class RouteRuleCapabilities(
    val routeDirectionRules: Boolean = false,
    val exactDestinationRules: Boolean = false,
)

data class ClientRouteRuleSnapshot(
    val directions: List<RouteDirection> = emptyList(),
    val entitlements: List<RouteRuleEntitlement> = emptyList(),
    val rules: List<ClientRouteRule> = emptyList(),
    val bindings: List<DirectionRuntimeBinding> = emptyList(),
    val capabilities: RouteRuleCapabilities = RouteRuleCapabilities(),
    val updatedAtMs: Long = 0L,
) {
    fun directionLabel(directionId: String): String = directions.firstOrNull { it.id == directionId }?.label ?: directionId
    fun bindingFor(serviceScopeId: String, directionId: String): DirectionRuntimeBinding? = bindings.firstOrNull { it.serviceScopeId == serviceScopeId && it.directionId == directionId }
    fun entitlementFor(serviceScopeId: String): RouteRuleEntitlement? = entitlements.firstOrNull { it.serviceScopeId == serviceScopeId }
}

data class ClientRuleWriteDraft(
    val serviceScopeId: String? = null,
    val destinationIp: String? = null,
    val destinationPort: Int? = null,
    val directionId: String? = null,
    val enabled: Boolean? = null,
    val syncVersion: Long? = null,
)

data class ClientRuleValidationResult(val error: String? = null)

fun RouteDirectionDto.toDomain(): RouteDirection = RouteDirection(
    id = id,
    label = label,
    status = status,
    sortOrder = sortOrder,
)

fun RouteRuleEntitlementDto.toDomain(): RouteRuleEntitlement = RouteRuleEntitlement(
    serviceScopeId = serviceScopeId,
    serviceId = serviceId,
    subscriptionId = subscriptionId,
    maxRules = maxRules,
    remainingRules = remainingRules,
    allowedDirectionIds = allowedDirectionIds,
)

fun ClientRouteRuleDto.toDomain(nowMs: Long): ClientRouteRule = ClientRouteRule(
    id = id,
    serviceScopeId = serviceScopeId,
    destinationIp = destinationIp,
    destinationPort = destinationPort,
    protocol = protocol.ifBlank { "tcp_udp" },
    directionId = directionId,
    enabled = enabled,
    effectiveState = ClientRuleEffectiveState.fromWire(effectiveState),
    syncVersion = syncVersion,
    updatedAtMs = nowMs,
)

fun DirectionRuntimeBindingDto.toDomain(): DirectionRuntimeBinding = DirectionRuntimeBinding(
    serviceScopeId = serviceScopeId,
    directionId = directionId,
    bindingState = bindingState,
    clientRuntimeBindingId = clientRuntimeBindingId,
    localEndpointHost = localEndpoint?.host.orEmpty(),
    localTcpPort = localEndpoint?.tcpPort ?: 0,
    localUdpPort = localEndpoint?.udpPort ?: 0,
    tcpReady = tcpReady,
    udpReady = udpReady,
    runtimeForwardId = runtimeRef?.forwardId,
    selectable = runtimeRef?.selectable ?: false,
    transport = runtimeRef?.transport ?: "tcp_udp",
)

fun RouteRuleCapabilitiesDto?.toDomain(): RouteRuleCapabilities = RouteRuleCapabilities(
    routeDirectionRules = this?.routeDirectionRules == true,
    exactDestinationRules = this?.exactDestinationRules == true,
)
```

- [ ] **Step 4: Add the file-backed store**

Create `leikwan-android/app/src/main/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStore.kt`:

```kotlin
package com.leikwan.bridge.data.clientrules

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.ClientRuleValidationResult
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.clientrules.RouteRuleCapabilities
import com.leikwan.bridge.clientrules.toDomain
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.util.UUID

class ClientRouteRuleStore(
    private val prefs: SharedPreferences,
    private val gson: Gson = Gson(),
    private val clock: () -> Long = System::currentTimeMillis,
) {
    private val _snapshot = MutableStateFlow(loadSnapshot())
    val snapshot: StateFlow<ClientRouteRuleSnapshot> = _snapshot.asStateFlow()

    fun current(): ClientRouteRuleSnapshot = _snapshot.value

    fun replaceFromPull(
        routeDirections: List<RouteDirectionDto>,
        entitlements: List<RouteRuleEntitlementDto>,
        rules: List<ClientRouteRuleDto>,
        bindings: List<DirectionRuntimeBindingDto>,
        capabilities: RouteRuleCapabilitiesDto?,
    ) {
        val now = clock()
        val pending = current().rules.filter { it.pendingOperation != PendingClientRuleOperation.NONE }
        val pendingById = pending.associateBy { it.id }
        val serverRules = rules.map { it.toDomain(now) }
        val mergedServerRules = serverRules.map { server -> pendingById[server.id] ?: server }
        val pendingLocalOnly = pending.filter { local ->
            local.pendingOperation == PendingClientRuleOperation.CREATE || serverRules.none { it.id == local.id }
        }
        save(
            ClientRouteRuleSnapshot(
                directions = routeDirections.map { it.toDomain() }.sortedBy { it.sortOrder },
                entitlements = entitlements.map { it.toDomain() },
                rules = (mergedServerRules + pendingLocalOnly).distinctBy { it.id },
                bindings = bindings.map { it.toDomain() },
                capabilities = capabilities.toDomain(),
                updatedAtMs = now,
            )
        )
    }

    fun activeRulesForScope(serviceScopeId: String): List<ClientRouteRule> = current().rules
        .filter { it.serviceScopeId == serviceScopeId && it.deletedAtMs == null }
        .sortedWith(compareBy<ClientRouteRule> { it.destinationIp }.thenBy { it.destinationPort })

    fun markPendingCreate(draft: ClientRuleWriteDraft): ClientRouteRule {
        validateDraft(draft).error?.let { throw IllegalArgumentException(it) }
        val now = clock()
        val rule = ClientRouteRule(
            id = "local_${UUID.randomUUID().toString().replace("-", "")}",
            serviceScopeId = draft.serviceScopeId.orEmpty(),
            destinationIp = draft.destinationIp.orEmpty(),
            destinationPort = draft.destinationPort ?: 0,
            directionId = draft.directionId.orEmpty(),
            enabled = draft.enabled ?: true,
            effectiveState = ClientRuleEffectiveState.SYNCING,
            syncVersion = 0L,
            pendingOperation = PendingClientRuleOperation.CREATE,
            updatedAtMs = now,
        )
        save(current().copy(rules = current().rules + rule, updatedAtMs = now))
        return rule
    }

    fun markPendingUpdate(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule {
        val now = clock()
        val existing = current().rules.first { it.id == ruleId }
        val updated = existing.copy(
            destinationIp = draft.destinationIp ?: existing.destinationIp,
            destinationPort = draft.destinationPort ?: existing.destinationPort,
            directionId = draft.directionId ?: existing.directionId,
            enabled = draft.enabled ?: existing.enabled,
            effectiveState = ClientRuleEffectiveState.SYNCING,
            pendingOperation = PendingClientRuleOperation.UPDATE,
            pendingError = null,
            updatedAtMs = now,
        )
        validateDraft(
            ClientRuleWriteDraft(
                serviceScopeId = updated.serviceScopeId,
                destinationIp = updated.destinationIp,
                destinationPort = updated.destinationPort,
                directionId = updated.directionId,
                enabled = updated.enabled,
                syncVersion = updated.syncVersion,
            )
        ).error?.let { throw IllegalArgumentException(it) }
        save(current().copy(rules = current().rules.map { if (it.id == ruleId) updated else it }, updatedAtMs = now))
        return updated
    }

    fun markPendingTombstone(ruleId: String): ClientRouteRule {
        val now = clock()
        val updated = current().rules.first { it.id == ruleId }.copy(
            effectiveState = ClientRuleEffectiveState.SYNCING,
            pendingOperation = PendingClientRuleOperation.DELETE,
            pendingError = null,
            deletedAtMs = now,
            updatedAtMs = now,
        )
        save(current().copy(rules = current().rules.map { if (it.id == ruleId) updated else it }, updatedAtMs = now))
        return updated
    }

    fun applyServerRule(dto: ClientRouteRuleDto, localId: String? = null): ClientRouteRule {
        val now = clock()
        val server = dto.toDomain(now)
        val kept = current().rules.filterNot { it.id == server.id || (localId != null && it.id == localId) }
        save(current().copy(rules = kept + server, updatedAtMs = now))
        return server
    }

    fun markPendingError(ruleId: String, error: String) {
        val now = clock()
        val updated = current().rules.map { rule ->
            if (rule.id == ruleId) {
                rule.copy(effectiveState = ClientRuleEffectiveState.ERROR, pendingError = error, updatedAtMs = now)
            } else {
                rule
            }
        }
        save(current().copy(rules = updated, updatedAtMs = now))
    }

    fun clear() = save(ClientRouteRuleSnapshot())

    private fun loadSnapshot(): ClientRouteRuleSnapshot = runCatching {
        gson.fromJson(prefs.getString(KEY_SNAPSHOT, null), ClientRouteRuleSnapshot::class.java)
    }.getOrNull() ?: ClientRouteRuleSnapshot()

    private fun save(snapshot: ClientRouteRuleSnapshot) {
        prefs.edit().putString(KEY_SNAPSHOT, gson.toJson(snapshot)).apply()
        _snapshot.value = snapshot
    }

    companion object {
        private const val KEY_SNAPSHOT = "snapshot"
        private val IPV4 = Regex("^(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)(\\.(25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)){3}$")

        fun from(context: Context): ClientRouteRuleStore = ClientRouteRuleStore(
            prefs = context.applicationContext.getSharedPreferences("client-route-rules", Context.MODE_PRIVATE),
        )

        fun validateDraft(draft: ClientRuleWriteDraft): ClientRuleValidationResult {
            val ip = draft.destinationIp?.trim().orEmpty()
            val port = draft.destinationPort ?: 0
            return when {
                ip.isBlank() || !IPV4.matches(ip) -> ClientRuleValidationResult("invalid_destination_ip")
                port !in 1..65535 -> ClientRuleValidationResult("invalid_destination_port")
                draft.directionId?.trim().isNullOrEmpty() -> ClientRuleValidationResult("direction_not_allowed")
                else -> ClientRuleValidationResult()
            }
        }
    }
}
```

- [ ] **Step 5: Run the store test and verify it passes**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.data.clientrules.ClientRouteRuleStoreTest"
```

Expected: PASS.

- [ ] **Step 6: Commit domain/store changes**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/clientrules/ClientRouteRuleModels.kt leikwan-android/app/src/main/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStore.kt leikwan-android/app/src/test/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStoreTest.kt
git commit -m "feat(android): cache route direction client rules" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Repository sync, capability advertisement, and write lifecycle

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryRouteRuleSyncTest.kt`

- [ ] **Step 1: Write the failing repository sync test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryRouteRuleSyncTest.kt`:

```kotlin
package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PanelRepositoryRouteRuleSyncTest {
    private lateinit var context: Context
    private lateinit var store: ClientRouteRuleStore
    private lateinit var api: FakePanelApi
    private lateinit var repo: PanelRepository

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        val prefs = Prefs(context.getSharedPreferences("route-rule-repo-test-prefs", Context.MODE_PRIVATE))
        prefs.token = "agent-token"
        context.getSharedPreferences("route-rule-repo-store", Context.MODE_PRIVATE).edit().clear().commit()
        store = ClientRouteRuleStore(context.getSharedPreferences("route-rule-repo-store", Context.MODE_PRIVATE), clock = { 10L })
        api = FakePanelApi()
        repo = PanelRepository(context, prefsOverride = prefs, apiOverride = api, routeRuleStore = store)
    }

    @Test
    fun `pullCatalog advertises Android route rule capabilities before pulling and stores payload`() = runBlocking {
        api.pullResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = emptyList(),
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            routeRuleEntitlements = listOf(RouteRuleEntitlementDto("svc_scope_7", 7L, 7007L, 5, 4, listOf("dir_sh_jp_1"))),
            clientRules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
            directionRuntimeBindings = emptyList(),
            routeRuleCapabilities = RouteRuleCapabilitiesDto(routeDirectionRules = true, exactDestinationRules = true),
        )

        repo.pullCatalog()

        assertEquals("Bearer agent-token", api.lastHeartbeatAuth)
        assertEquals(true, api.lastHeartbeatRequest?.supportsRouteDirectionRules)
        assertEquals(true, api.lastHeartbeatRequest?.supportsExactDestinationRules)
        assertEquals("沪日1", store.current().directions.single().label)
        assertEquals("rule_1", store.current().rules.single().id)
    }

    @Test
    fun `create update and delete use pending lifecycle and server acknowledgements`() = runBlocking {
        api.createResponse = ClientRuleWriteResponse(
            ok = true,
            clientRule = ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L),
        )
        val created = repo.createClientRule(
            ClientRuleWriteDraft("svc_scope_7", "93.184.216.34", 443, "dir_sh_jp_1", true),
        )
        assertEquals("rule_1", created.id)
        assertEquals(PendingClientRuleOperation.NONE, store.current().rules.single().pendingOperation)
        assertEquals("93.184.216.34", api.lastCreateRequest?.destinationIp)

        api.updateResponse = ClientRuleWriteResponse(
            ok = true,
            clientRule = ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", false, "disabled", 2L),
        )
        val updated = repo.updateClientRule("rule_1", ClientRuleWriteDraft(enabled = false, syncVersion = 1L))
        assertFalse(updated.enabled)
        assertEquals(1L, api.lastUpdateRequest?.syncVersion)

        api.deleteResponse = ClientRuleWriteResponse(
            ok = true,
            clientRule = ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", false, "disabled", 3L),
        )
        repo.deleteClientRule("rule_1")
        assertTrue(api.deletedRuleIds.contains("rule_1"))
    }

    private class FakePanelApi : PanelApi {
        var pullResponse = PullConfigsResponse(true, JsonObject(), emptyList())
        var createResponse = ClientRuleWriteResponse(false, error = "not_configured")
        var updateResponse = ClientRuleWriteResponse(false, error = "not_configured")
        var deleteResponse = ClientRuleWriteResponse(false, error = "not_configured")
        var lastHeartbeatAuth: String? = null
        var lastHeartbeatRequest: HeartbeatRequest? = null
        var lastCreateRequest: ClientRuleWriteRequest? = null
        var lastUpdateRequest: ClientRuleWriteRequest? = null
        val deletedRuleIds = mutableListOf<String>()

        override suspend fun login(request: LoginRequest): Response<LoginResponse> = Response.success(LoginResponse(ok = true))
        override suspend fun selectClient(request: ClientSelectionRequest): Response<ClientSelectionResponse> = throw UnsupportedOperationException()
        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse {
            lastHeartbeatAuth = auth
            lastHeartbeatRequest = request
            return HeartbeatResponse(ok = true)
        }
        override suspend fun pullConfigs(auth: String): PullConfigsResponse = pullResponse
        override suspend fun createClientRule(auth: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> {
            lastCreateRequest = request
            return Response.success(createResponse)
        }
        override suspend fun updateClientRule(auth: String, ruleId: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> {
            lastUpdateRequest = request
            return Response.success(updateResponse)
        }
        override suspend fun deleteClientRule(auth: String, ruleId: String): Response<ClientRuleWriteResponse> {
            deletedRuleIds += ruleId
            return Response.success(deleteResponse)
        }
        override suspend fun getForwardConfig(auth: String, forwardId: Long): JsonElement = JsonObject()
    }
}
```

- [ ] **Step 2: Run the repository test and verify it fails**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.PanelRepositoryRouteRuleSyncTest"
```

Expected: FAIL with constructor mismatch for `PanelRepository(... prefsOverride = ..., apiOverride = ..., routeRuleStore = ...)` and missing `createClientRule`, `updateClientRule`, or `deleteClientRule` methods.

- [ ] **Step 3: Add repository injection seam and route-rule store**

Change the `PanelRepository` class declaration and initial fields in `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`:

```kotlin
class PanelRepository(
    context: Context,
    private val prefsOverride: Prefs? = null,
    apiOverride: PanelApi? = null,
    private val routeRuleStore: ClientRouteRuleStore = ClientRouteRuleStore.from(context),
) {
    private val prefs = prefsOverride ?: Prefs.get(context)
    private val gson = Gson()

    private var api: PanelApi = apiOverride ?: buildApi(prefs.panelUrl)
```

Add this import:

```kotlin
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
```

In `login`, `selectClient`, and `switchClient`, keep the existing behavior. In `logout()`, add:

```kotlin
routeRuleStore.clear()
```

- [ ] **Step 4: Persist route-rule pull data and advertise capabilities before pull**

At the start of `pullCatalog()` before `val resp = api.pullConfigs(authHeader())`, add:

```kotlin
runCatching { api.heartbeat(authHeader(), HeartbeatRequest()) }
```

After `if (!resp.ok) throw Exception("Pull configs failed")`, add:

```kotlin
routeRuleStore.replaceFromPull(
    routeDirections = resp.routeDirections.orEmpty(),
    entitlements = resp.routeRuleEntitlements.orEmpty(),
    rules = resp.clientRules.orEmpty(),
    bindings = resp.directionRuntimeBindings.orEmpty(),
    capabilities = resp.routeRuleCapabilities,
)
```

This persists route-rule fields independently from visible service selection.

- [ ] **Step 5: Add write lifecycle methods**

Add these methods near `heartbeat()` in `PanelRepository`:

```kotlin
suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule {
    val local = routeRuleStore.markPendingCreate(draft)
    return try {
        val response = api.createClientRule(authHeader(), draft.toRequest())
        val body = response.body()
        if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
            val code = body?.error ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
            routeRuleStore.markPendingError(local.id, code)
            throw Exception(code)
        }
        routeRuleStore.applyServerRule(body.clientRule, local.id)
    } catch (e: Exception) {
        routeRuleStore.markPendingError(local.id, e.message ?: "client_rule_write_failed")
        throw e
    }
}

suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule {
    val local = routeRuleStore.markPendingUpdate(ruleId, draft)
    return try {
        val response = api.updateClientRule(authHeader(), ruleId, draft.toRequest())
        val body = response.body()
        if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
            val code = body?.error ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
            routeRuleStore.markPendingError(ruleId, code)
            throw Exception(code)
        }
        routeRuleStore.applyServerRule(body.clientRule, local.id)
    } catch (e: Exception) {
        routeRuleStore.markPendingError(ruleId, e.message ?: "client_rule_write_failed")
        throw e
    }
}

suspend fun deleteClientRule(ruleId: String): ClientRouteRule {
    val local = routeRuleStore.markPendingTombstone(ruleId)
    return try {
        val response = api.deleteClientRule(authHeader(), ruleId)
        val body = response.body()
        if (!response.isSuccessful || body?.ok != true || body.clientRule == null) {
            val code = body?.error ?: PanelErrorParser.messageFromHttp(response.code(), response.message(), response.errorBody()?.string())
            routeRuleStore.markPendingError(ruleId, code)
            throw Exception(code)
        }
        routeRuleStore.applyServerRule(body.clientRule, local.id)
    } catch (e: Exception) {
        routeRuleStore.markPendingError(ruleId, e.message ?: "client_rule_write_failed")
        throw e
    }
}

private fun ClientRuleWriteDraft.toRequest(): ClientRuleWriteRequest = ClientRuleWriteRequest(
    serviceScopeId = serviceScopeId,
    destinationIp = destinationIp,
    destinationPort = destinationPort,
    directionId = directionId,
    enabled = enabled,
    syncVersion = syncVersion,
)
```

- [ ] **Step 6: Run repository and existing API tests**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.PanelRepositoryRouteRuleSyncTest" --tests "com.leikwan.bridge.api.RouteRulePullModelsTest" --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest"
```

Expected: PASS.

- [ ] **Step 7: Commit repository sync changes**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryRouteRuleSyncTest.kt
git commit -m "feat(android): sync route direction client rules" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Helper forward parsing, visible catalog filtering, and route-direction runtime planning

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlanner.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlannerTest.kt`
- Test: extend `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelModelsContractParsingTest.kt`

- [ ] **Step 1: Add failing parser/filter assertions for helper forwards**

Append this test to `PanelModelsContractParsingTest`:

```kotlin
@Test
fun `route rule helper forwards are parsed but hidden from visible catalog`() {
    val helper = JsonParser.parseString(
        """
        {
          "forward_id": 77,
          "service_name": "helper-dir-sh-jp-1",
          "target": "93.184.216.34:443",
          "listen_port": 12001,
          "mode": "single",
          "backend_mode": "leigod_mode",
          "contract_version": 1,
          "transport": "tcp_udp",
          "service_status": "active",
          "endpoint_mode": "manual",
          "route_rule_helper_only": true,
          "endpoints": [
            {"endpoint_id":1,"node":"n1","host":"198.51.100.10","port":7001,"priority":1,"weight":1,"health_status":"healthy"}
          ]
        }
        """.trimIndent()
    )
    val visible = JsonParser.parseString(
        """
        {
          "forward_id": 78,
          "service_name": "selectable-service",
          "target": "203.0.113.10:443",
          "listen_port": 12002,
          "mode": "single",
          "contract_version": 1,
          "transport": "tcp_udp",
          "service_status": "active",
          "endpoint_mode": "manual",
          "route_rule_helper_only": false,
          "endpoints": [
            {"endpoint_id":2,"node":"n2","host":"198.51.100.11","port":7001,"priority":1,"weight":1,"health_status":"healthy"}
          ]
        }
        """.trimIndent()
    )

    val helperContract = ForwardContractParser.parse(helper)!!
    val visibleContract = ForwardContractParser.parse(visible)!!
    assertEquals(true, helperContract.routeRuleHelperOnly)
    assertEquals(false, visibleContract.routeRuleHelperOnly)

    val catalog = com.leikwan.bridge.runtime.ForwardFilter.buildCatalog(listOf(helperContract, visibleContract), selectedForwardId = null)
    assertEquals(listOf(78L), catalog.visibleForwards.map { it.forwardId })
}
```

- [ ] **Step 2: Write the failing runtime planner test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlannerTest.kt`:

```kotlin
package com.leikwan.bridge.runtime

import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RouteDirectionRuntimePlannerTest {
    @Test
    fun `planner starts one helper runtime per active binding identity and exposes sing-box bindings`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(
                routeRule("rule_1", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE),
                routeRule("rule_2", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE),
                routeRule("rule_3", "dir_sh_hk_1", ClientRuleEffectiveState.RUNTIME_MISSING),
            ),
            bindings = listOf(
                DirectionRuntimeBinding("svc_scope_7", "dir_sh_jp_1", "ready", "crb_svc7_dir_sh_jp_1", "127.0.0.1", 12001, 12001, tcpReady = true, udpReady = true, 77L, selectable = false, transport = "tcp_udp"),
                DirectionRuntimeBinding("svc_scope_7", "dir_sh_hk_1", "missing", null, "", 0, 0, tcpReady = false, udpReady = false, null, selectable = false, transport = "tcp_udp"),
            ),
        )
        val helperForward = helperForward()

        val plan = RouteDirectionRuntimePlanner.plan(
            snapshot = snapshot,
            forwardContractsById = mapOf(77L to helperForward),
            buildRuntimeContext = { binding, forward, listenPort ->
                RuntimeContext(
                    forwardId = forward.forwardId,
                    mode = forward.mode,
                    localListenPort = listenPort,
                    localLegPorts = emptyList(),
                    processPlans = emptyList(),
                    bypassHosts = AndroidEffectiveForwardBuilder.build(forward)!!.bypassHosts,
                    routeRuleProcessKey = RouteDirectionRuntimePlanner.processKey(binding.bindingIdentity),
                )
            },
        )

        assertEquals(listOf(com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner.processKey("crb_svc7_dir_sh_jp_1")), plan.runtimeContexts.map { it.routeRuleProcessKey })
        assertEquals(12001, plan.runtimeContexts.single().localListenPort)
        assertEquals("route-binding-crb_svc7_dir_sh_jp_1-tcp", plan.singboxBindings.single().tcpOutboundTag)
        assertEquals("route-binding-crb_svc7_dir_sh_jp_1-udp", plan.singboxBindings.single().udpOutboundTag)
        assertEquals(setOf("198.51.100.10"), plan.bypassHosts)
    }

    @Test
    fun `planner leaves directions fail closed when binding is not Android ready`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(routeRule("rule_1", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE)),
            bindings = listOf(DirectionRuntimeBinding("svc_scope_7", "dir_sh_jp_1", "ready", "crb_svc7_dir_sh_jp_1", "127.0.0.1", 12001, 12002, tcpReady = true, udpReady = false, 77L, selectable = false, transport = "tcp_udp")),
        )

        val plan = RouteDirectionRuntimePlanner.plan(snapshot, mapOf(77L to helperForward())) { _, _, _ -> error("not called") }

        assertTrue(plan.runtimeContexts.isEmpty())
        assertTrue(plan.singboxBindings.isEmpty())
    }

    private fun routeRule(id: String, directionId: String, state: ClientRuleEffectiveState): ClientRouteRule = ClientRouteRule(
        id = id,
        serviceScopeId = "svc_scope_7",
        destinationIp = "93.184.216.34",
        destinationPort = 443,
        directionId = directionId,
        enabled = true,
        effectiveState = state,
        syncVersion = 1L,
        updatedAtMs = 1L,
    )

    private fun helperForward(): ForwardContract = ForwardContract(
        forwardId = 77L,
        serviceName = "helper-dir-sh-jp-1",
        target = "93.184.216.34:443",
        listenPort = 11077,
        mode = ForwardMode.SINGLE,
        transport = "tcp_udp",
        serviceStatus = "active",
        endpointMode = "manual",
        sni = "",
        pathPrefix = "",
        speedLimitMbps = null,
        endpoints = listOf(
            ForwardEndpoint(
                endpointId = 1,
                node = "n1",
                host = "198.51.100.10",
                port = 7001,
                cdnHost = null,
                priority = 1,
                weight = 1,
                healthStatus = "healthy",
                supportsQuicLeg = false,
                ingressProtocol = null,
                ingressAuthToken = null,
            )
        ),
        bonding = null,
        mptcp = null,
        routeRuleHelperOnly = true,
    )
}
```

- [ ] **Step 3: Run parser/planner tests and verify they fail**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest" --tests "com.leikwan.bridge.runtime.RouteDirectionRuntimePlannerTest"
```

Expected: FAIL with missing `routeRuleHelperOnly`, missing `routeRuleProcessKey`, and missing `RouteDirectionRuntimePlanner`. The missing `routeRuleProcessKey` field must be a `Long?` because `ProcessRunner` keys are numeric.

- [ ] **Step 4: Parse and filter helper forwards**

In `ForwardContract.kt`, add a `routeRuleHelperOnly: Boolean` property to `ForwardContract` with default `false`. In `ForwardContractParser.parse(...)`, read it from the raw JSON:

```kotlin
val routeRuleHelperOnly = obj.get("route_rule_helper_only")?.asBoolean == true
```

Pass it to the `ForwardContract` constructor:

```kotlin
routeRuleHelperOnly = routeRuleHelperOnly,
```

In `ForwardFilter.kt`, ensure visible catalog construction excludes helper-only contracts before sorting/selecting:

```kotlin
val visibleContracts = contracts.filter { !it.routeRuleHelperOnly }
```

Use `visibleContracts` for `visibleForwards` and selected-forward fallback. Keep the original `contracts` list available only for cached runtime lookup in `PanelRepository`.

- [ ] **Step 5: Add route-rule process metadata to RuntimeContext**

In `RuntimeContext.kt`, add a defaulted property so existing constructors continue compiling:

```kotlin
val routeRuleProcessKey: Long? = null,
```

Place it after `nodeLegs` if `nodeLegs` is the final current property, and update all call sites by relying on the default value.

- [ ] **Step 6: Add the runtime planner**

Create `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlanner.kt`:

```kotlin
package com.leikwan.bridge.runtime

import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding

data class ClientRuleSingboxBinding(
    val bindingIdentity: String,
    val serviceScopeId: String,
    val directionId: String,
    val tcpOutboundTag: String,
    val udpOutboundTag: String,
    val localTcpPort: Int,
    val localUdpPort: Int,
)

data class RouteDirectionRuntimePlan(
    val runtimeContexts: List<RuntimeContext>,
    val singboxBindings: List<ClientRuleSingboxBinding>,
    val bypassHosts: Set<String>,
)

object RouteDirectionRuntimePlanner {
    private const val ROUTE_RULE_PROCESS_KEY_BASE = 9_000_000_000L
    private const val ROUTE_RULE_PROCESS_KEY_RANGE = 999_999_999L

    fun processKey(bindingIdentity: String): Long {
        var hash = 1_125_899_906_842_597L
        bindingIdentity.trim().forEach { ch -> hash = 31L * hash + ch.code.toLong() }
        return ROUTE_RULE_PROCESS_KEY_BASE + ((hash and Long.MAX_VALUE) % ROUTE_RULE_PROCESS_KEY_RANGE)
    }

    fun plan(
        snapshot: ClientRouteRuleSnapshot,
        forwardContractsById: Map<Long, ForwardContract>,
        buildRuntimeContext: (binding: DirectionRuntimeBinding, forward: ForwardContract, listenPort: Int) -> RuntimeContext,
    ): RouteDirectionRuntimePlan {
        val activeBindingKeys = snapshot.rules
            .filter { it.installable }
            .map { it.serviceScopeId to it.directionId }
            .distinct()
        val runtimeContexts = mutableListOf<RuntimeContext>()
        val singboxBindings = mutableListOf<ClientRuleSingboxBinding>()
        val bypassHosts = linkedSetOf<String>()

        activeBindingKeys.forEach { (serviceScopeId, directionId) ->
            val binding = snapshot.bindingFor(serviceScopeId, directionId) ?: return@forEach
            if (!binding.readyForAndroid) return@forEach
            val forward = forwardContractsById[binding.runtimeForwardId] ?: return@forEach
            val runtimeContext = buildRuntimeContext(binding, forward, binding.localTcpPort)
            runtimeContexts += runtimeContext
            bypassHosts += runtimeContext.allBypassHosts()
            singboxBindings += ClientRuleSingboxBinding(
                bindingIdentity = binding.bindingIdentity,
                serviceScopeId = serviceScopeId,
                directionId = directionId,
                tcpOutboundTag = "route-binding-${binding.bindingIdentity}-tcp",
                udpOutboundTag = "route-binding-${binding.bindingIdentity}-udp",
                localTcpPort = binding.localTcpPort,
                localUdpPort = binding.localUdpPort,
            )
        }

        return RouteDirectionRuntimePlan(runtimeContexts, singboxBindings, bypassHosts)
    }
}
```

- [ ] **Step 7: Run parser/planner tests and verify they pass**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest" --tests "com.leikwan.bridge.runtime.RouteDirectionRuntimePlannerTest"
```

Expected: PASS.

- [ ] **Step 8: Commit helper forward and planner changes**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlanner.kt leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelModelsContractParsingTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlannerTest.kt
git commit -m "feat(android): plan route direction helper runtimes" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: sing-box exact IP:PORT routing and fail-closed reject rules

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Test: extend `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`

- [ ] **Step 1: Write the failing sing-box route-rule ordering test**

Append this test to `SingboxConfigBuilderTest`:

```kotlin
@Test
fun `client rules are exact ip port rules after protected paths and before country rules`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())
    val runtimeContext = RuntimeContext(
        forwardId = 65L,
        mode = ForwardMode.SINGLE,
        localListenPort = 11065,
        localLegPorts = emptyList(),
        processPlans = emptyList(),
        bypassHosts = linkedSetOf("198.51.100.10"),
    )
    val routeRuleSnapshot = com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot(
        rules = listOf(
            com.leikwan.bridge.clientrules.ClientRouteRule(
                id = "rule_active",
                serviceScopeId = "svc_scope_7",
                destinationIp = "93.184.216.34",
                destinationPort = 443,
                directionId = "dir_sh_jp_1",
                enabled = true,
                effectiveState = com.leikwan.bridge.clientrules.ClientRuleEffectiveState.ACTIVE,
                syncVersion = 1L,
                updatedAtMs = 1L,
            ),
            com.leikwan.bridge.clientrules.ClientRouteRule(
                id = "rule_missing",
                serviceScopeId = "svc_scope_7",
                destinationIp = "203.0.113.9",
                destinationPort = 8443,
                directionId = "dir_sh_hk_1",
                enabled = true,
                effectiveState = com.leikwan.bridge.clientrules.ClientRuleEffectiveState.RUNTIME_MISSING,
                syncVersion = 1L,
                updatedAtMs = 1L,
            ),
        ),
    )
    val routeRuleBindings = listOf(
        com.leikwan.bridge.runtime.ClientRuleSingboxBinding(
            bindingIdentity = "crb_svc7_dir_sh_jp_1",
            serviceScopeId = "svc_scope_7",
            directionId = "dir_sh_jp_1",
            tcpOutboundTag = "route-binding-crb_svc7_dir_sh_jp_1-tcp",
            udpOutboundTag = "route-binding-crb_svc7_dir_sh_jp_1-udp",
            localTcpPort = 12001,
            localUdpPort = 12001,
        )
    )
    val policySnapshot = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    ).compile(
        globalDefault = UserTrafficOutcome.TUNNEL,
        globalDefaultNodeId = null,
        countryRules = listOf(RoutingRule("country-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)),
        categoryRules = emptyList(),
        rulePackVersions = emptyList(),
        compiledAtMs = 1L,
    )

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = emptyList(),
            runtimeContext = runtimeContext,
            routingPolicySnapshot = policySnapshot,
            connectionMode = ProxyConnectionMode.SERVICES,
            routeRuleSnapshot = routeRuleSnapshot,
            routeRuleRuntimeBindings = routeRuleBindings,
        ).configJson
    ).asJsonObject
    val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
    val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

    val tcpOutbound = outbounds.first { it.get("tag").asString == "route-binding-crb_svc7_dir_sh_jp_1-tcp" }
    assertEquals("direct", tcpOutbound.get("type").asString)
    assertEquals("127.0.0.1", tcpOutbound.get("override_address").asString)
    assertEquals(12001, tcpOutbound.get("override_port").asInt)

    val activeTcpIndex = rules.indexOfFirst { rule ->
        rule.get("network")?.asString == "tcp" &&
            rule.get("outbound")?.asString == "route-binding-crb_svc7_dir_sh_jp_1-tcp" &&
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "93.184.216.34/32" } == true &&
            rule.getAsJsonArray("port")?.any { it.asInt == 443 } == true
    }
    val activeUdpIndex = rules.indexOfFirst { rule ->
        rule.get("network")?.asString == "udp" &&
            rule.get("outbound")?.asString == "route-binding-crb_svc7_dir_sh_jp_1-udp" &&
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "93.184.216.34/32" } == true &&
            rule.getAsJsonArray("port")?.any { it.asInt == 443 } == true
    }
    val rejectIndex = rules.indexOfFirst { rule ->
        rule.get("action")?.asString == "reject" &&
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.9/32" } == true &&
            rule.getAsJsonArray("port")?.any { it.asInt == 8443 } == true
    }
    val countryRuleIndex = rules.indexOfFirst { rule ->
        rule.getAsJsonArray("rule_set")?.any { it.asString == "geoip-cn" } == true
    }
    val runtimeBypassIndex = rules.indexOfFirst { rule ->
        rule.getAsJsonArray("ip_cidr")?.any { it.asString == "198.51.100.10/32" } == true
    }

    assertTrue(runtimeBypassIndex >= 0)
    assertTrue(activeTcpIndex > runtimeBypassIndex)
    assertTrue(activeUdpIndex > runtimeBypassIndex)
    assertTrue(rejectIndex > runtimeBypassIndex)
    assertTrue(countryRuleIndex > activeTcpIndex)
    assertTrue(countryRuleIndex > activeUdpIndex)
    assertTrue(countryRuleIndex > rejectIndex)
}
```

- [ ] **Step 2: Run the sing-box test and verify it fails**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest.client rules are exact ip port rules after protected paths and before country rules"
```

Expected: FAIL with `Cannot find a parameter with this name: routeRuleSnapshot` or missing route-rule outbounds.

- [ ] **Step 3: Extend the builder signature and imports**

In `SingboxConfigBuilder.kt`, add imports:

```kotlin
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.runtime.ClientRuleSingboxBinding
```

Change `build(...)` signature by adding defaulted parameters after `enableIpv6Tun`:

```kotlin
routeRuleSnapshot: ClientRouteRuleSnapshot? = null,
routeRuleRuntimeBindings: List<ClientRuleSingboxBinding> = emptyList(),
```

Change the outbounds and route calls:

```kotlin
root.add("outbounds", buildOutbounds(proxyNodes, runtimeContext, connectionMode, routeRuleRuntimeBindings))
root.add("route", buildRoute(runtimeContext?.allBypassHosts() ?: emptySet(), finalProxyTag, routingPolicySnapshot, routeRuleSnapshot, routeRuleRuntimeBindings))
```

- [ ] **Step 4: Emit per-direction direct outbounds**

Change `buildOutbounds(...)` signature to include `routeRuleRuntimeBindings`, then append this block before `return outbounds`:

```kotlin
routeRuleRuntimeBindings.forEach { binding ->
    outbounds.add(routeRuleDirectOutbound(binding.tcpOutboundTag, binding.localTcpPort))
    outbounds.add(routeRuleDirectOutbound(binding.udpOutboundTag, binding.localUdpPort))
}
```

Add this helper below `buildOutbounds(...)`:

```kotlin
private fun routeRuleDirectOutbound(tag: String, port: Int): JsonObject = JsonObject().apply {
    addProperty("type", "direct")
    addProperty("tag", tag)
    addProperty("override_address", "127.0.0.1")
    addProperty("override_port", port)
}
```

- [ ] **Step 5: Insert route-rule route entries at the locked ordering point**

Change `buildRoute(...)` signature:

```kotlin
private fun buildRoute(
    runtimeBypassHosts: Set<String>,
    finalProxyTag: String,
    snapshot: RoutingPolicySnapshot?,
    routeRuleSnapshot: ClientRouteRuleSnapshot?,
    routeRuleRuntimeBindings: List<ClientRuleSingboxBinding>,
): JsonObject {
```

After the runtime reachability bypass block and before the existing comment `// (3) User country/category rules`, insert:

```kotlin
// (3) Server-synced Client Rules: exact destination IP:PORT rules. These sit after
//     protected/runtime reachability and before country/category/default routing.
addClientRuleRoutes(rules, routeRuleSnapshot, routeRuleRuntimeBindings)
```

Renumber the following comments to keep country/category as `(4)`, bypassChina as `(5)`, and custom bypass as `(6)`.

Add these helpers below `buildRoute(...)`:

```kotlin
private fun addClientRuleRoutes(
    rules: JsonArray,
    snapshot: ClientRouteRuleSnapshot?,
    runtimeBindings: List<ClientRuleSingboxBinding>,
) {
    snapshot ?: return
    val bindingByScopeAndDirection = runtimeBindings.associateBy { it.serviceScopeId to it.directionId }
    snapshot.rules
        .filter { it.deletedAtMs == null && it.enabled }
        .forEach { rule ->
            val binding = bindingByScopeAndDirection[rule.serviceScopeId to rule.directionId]
            if (rule.installable && binding != null) {
                rules.add(routeRuleMatch(rule, "tcp", binding.tcpOutboundTag))
                rules.add(routeRuleMatch(rule, "udp", binding.udpOutboundTag))
            } else {
                rules.add(routeRuleReject(rule, "tcp"))
                rules.add(routeRuleReject(rule, "udp"))
            }
        }
}

private fun routeRuleMatch(rule: ClientRouteRule, network: String, outbound: String): JsonObject = routeRuleBase(rule, network).apply {
    addProperty("outbound", outbound)
}

private fun routeRuleReject(rule: ClientRouteRule, network: String): JsonObject = routeRuleBase(rule, network).apply {
    addProperty("action", "reject")
}

private fun routeRuleBase(rule: ClientRouteRule, network: String): JsonObject = JsonObject().apply {
    add("ip_cidr", JsonArray().apply { add(normalizeIpCidr(rule.destinationIp)) })
    add("port", JsonArray().apply { add(rule.destinationPort) })
    addProperty("network", network)
}
```

- [ ] **Step 6: Run sing-box and existing builder tests**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"
```

Expected: PASS.

- [ ] **Step 7: Commit sing-box route-rule generation**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "feat(android): route client rules through sing-box" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: TunnelOrchestrator integration and helper runtime cleanup

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Test: extend `leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt`

- [ ] **Step 1: Add failing orchestrator integration test**

Append this test to `TunnelOrchestratorTest`:

```kotlin
@Test
fun `route rule runtime plan is passed into sing-box after helper runtimes start`() = runBlocking {
    val context = ApplicationProvider.getApplicationContext<Context>()
    db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
        .allowMainThreadQueries()
        .build()
    val prefs = Prefs(context.getSharedPreferences("orchestrator-route-rule-test-prefs", Context.MODE_PRIVATE))
    val processRunner = RecordingProcessRunner()
    val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
    val routeRuleStore = com.leikwan.bridge.data.clientrules.ClientRouteRuleStore(
        context.getSharedPreferences("orchestrator-route-rule-store", Context.MODE_PRIVATE).also { it.edit().clear().commit() },
        clock = { 1L },
    )
    routeRuleStore.replaceFromPull(
        routeDirections = listOf(com.leikwan.bridge.api.RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
        entitlements = emptyList(),
        rules = listOf(com.leikwan.bridge.api.ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
        bindings = listOf(com.leikwan.bridge.api.DirectionRuntimeBindingDto("svc_scope_7", "dir_sh_jp_1", "ready", "crb_svc7_dir_sh_jp_1", com.leikwan.bridge.api.DirectionLocalEndpointDto("127.0.0.1", 12001, 12001), true, true, com.leikwan.bridge.api.DirectionRuntimeRefDto("forward_ref", 77L, false, "tcp_udp"))),
        capabilities = com.leikwan.bridge.api.RouteRuleCapabilitiesDto(true, true),
    )
    val orchestrator = TunnelOrchestrator(
        context = context,
        prefs = prefs,
        db = db,
        userRuleStore = UserRoutingRuleStore(db),
        rulePackManager = RulePackManager(db, prefs),
        explainabilityStore = RoutingExplainabilityStore(db, prefs),
        routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
        processRunnerFactory = { processRunner },
        routeRuleStore = routeRuleStore,
    )

    val helperForward = sampleBondForward(forwardId = 77L, listenPort = 12001)
    val routePlan = orchestrator.buildRouteDirectionRuntimePlanForTest(
        snapshot = routeRuleStore.current(),
        forwardContractsById = mapOf(77L to helperForward),
        binaries = RuntimeBinaryPaths("/runtime/wstunnel", "/runtime/leikwan-ingress", "/runtime/leikwan-bond"),
    )

    assertEquals(listOf(com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner.processKey("crb_svc7_dir_sh_jp_1")), routePlan.runtimeContexts.map { it.routeRuleProcessKey })
    assertEquals("route-binding-crb_svc7_dir_sh_jp_1-tcp", routePlan.singboxBindings.single().tcpOutboundTag)
}
```

- [ ] **Step 2: Run the orchestrator integration test and verify it fails**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.service.TunnelOrchestratorTest.route rule runtime plan is passed into sing-box after helper runtimes start"
```

Expected: FAIL with constructor parameter `routeRuleStore` missing and missing `buildRouteDirectionRuntimePlanForTest`.

- [ ] **Step 3: Inject ClientRouteRuleStore into TunnelOrchestrator**

In `TunnelOrchestrator.kt`, add import:

```kotlin
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlan
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner
```

Add a defaulted constructor parameter after `routingEngine` so existing unit tests and production call sites keep compiling:

```kotlin
private val routeRuleStore: ClientRouteRuleStore = ClientRouteRuleStore.from(context),
```

Update the public constructor block with:

```kotlin
routeRuleStore = ClientRouteRuleStore.from(context),
```

- [ ] **Step 4: Add a route-rule runtime planning seam**

Add this internal method near `startNodeLegs(...)`:

```kotlin
internal fun buildRouteDirectionRuntimePlanForTest(
    snapshot: ClientRouteRuleSnapshot,
    forwardContractsById: Map<Long, ForwardContract>,
    binaries: RuntimeBinaryPaths,
): RouteDirectionRuntimePlan = buildRouteDirectionRuntimePlan(snapshot, forwardContractsById, binaries)

private fun buildRouteDirectionRuntimePlan(
    snapshot: ClientRouteRuleSnapshot,
    forwardContractsById: Map<Long, ForwardContract>,
    binaries: RuntimeBinaryPaths,
): RouteDirectionRuntimePlan = RouteDirectionRuntimePlanner.plan(
    snapshot = snapshot,
    forwardContractsById = forwardContractsById,
    buildRuntimeContext = { binding, forward, listenPort ->
        buildRuntimePlan(forward.withListenPort(listenPort), binaries).copy(
            routeRuleProcessKey = RouteDirectionRuntimePlanner.processKey(binding.bindingIdentity),
        )
    },
)
```

- [ ] **Step 5: Start and stop helper route-rule runtimes in `startForForward()`**

After `val runtimeContext = run { ... }` and before `val activeRulePackManifest = ...`, add:

```kotlin
val routeRuleSnapshot = routeRuleStore.current()
val routeRuleForwardIds = routeRuleSnapshot.bindings.mapNotNull { it.runtimeForwardId }.distinct()
val routeRuleForwardContracts = routeRuleForwardIds.mapNotNull { forwardId ->
    panelRepo.getCachedContract(forwardId)?.let { forwardId to it }
}.toMap()
val routeRuleRuntimePlan = buildRouteDirectionRuntimePlan(routeRuleSnapshot, routeRuleForwardContracts, binaries)
routeRuleRuntimePlan.runtimeContexts.forEach { routeRuntime ->
    val processKey = routeRuntime.routeRuleProcessKey ?: return@forEach
    processRunner.start(processKey, routeRuntime, cleanupStale = false)
}
```

The `binaries` variable is currently scoped inside the `run` block. Move `val binaries = binaryInstaller.prepareBinaryPaths()` above the `run` block so both base runtime and route-rule runtime planning use the same prepared binary paths.

Change the `SingboxConfigBuilder.build(...)` call to use the merged runtime context and pass route-rule data:

```kotlin
runtimeContext = singboxRuntimeContext,
routeRuleSnapshot = routeRuleSnapshot,
routeRuleRuntimeBindings = routeRuleRuntimePlan.singboxBindings,
```

Create a merged runtime context for sing-box so route-rule helper bypass hosts are protected from user routing loops:

```kotlin
val singboxRuntimeContext = runtimeContext?.copy(
    bypassHosts = runtimeContext.bypassHosts + routeRuleRuntimePlan.bypassHosts,
)
```

Pass `singboxRuntimeContext` to `SingboxConfigBuilder.build(...)` and keep `runtimeContext` itself unchanged for `activeRuntime`/shutdown tracking.

- [ ] **Step 6: Stop route-rule helper runtimes with the tunnel**

Add a property near `activeRuntime`:

```kotlin
private var activeRouteRuleRuntimeKeys: Set<Long> = emptySet()
```

After route-rule helper runtimes start successfully, assign:

```kotlin
activeRouteRuleRuntimeKeys = routeRuleRuntimePlan.runtimeContexts.mapNotNull { it.routeRuleProcessKey }.toSet()
```

In `stop()`, before `processRunner.stopAll()`, add:

```kotlin
activeRouteRuleRuntimeKeys.forEach { processRunner.stopForward(it) }
activeRouteRuleRuntimeKeys = emptySet()
```

Keep `processRunner.stopAll()` after that line so existing cleanup behavior remains comprehensive.

- [ ] **Step 7: Run orchestrator and builder tests**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.service.TunnelOrchestratorTest" --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest" --tests "com.leikwan.bridge.runtime.RouteDirectionRuntimePlannerTest"
```

Expected: PASS.

- [ ] **Step 8: Commit orchestrator integration**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt
git commit -m "feat(android): start route direction helper runtimes" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Client Rules ViewModel, Compose screen, navigation, and product-language guard

**Files:**
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt`
- Modify: `leikwan-android/app/src/main/res/values/strings.xml`
- Modify: `leikwan-android/app/src/main/res/values-zh-rCN/strings.xml`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModelTest.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesProductLanguageTest.kt`

- [ ] **Step 1: Write the failing ViewModel test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModelTest.kt`:

```kotlin
package com.leikwan.bridge.ui.clientrules

import android.app.Application
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.api.RouteRuleEntitlementDto
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ClientRulesViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var store: ClientRouteRuleStore
    private lateinit var repository: RecordingClientRuleRepository

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        val context = ApplicationProvider.getApplicationContext<Context>()
        app = context as Application
        val prefs = context.getSharedPreferences("client-rules-vm-test", Context.MODE_PRIVATE)
        prefs.edit().clear().commit()
        store = ClientRouteRuleStore(prefs, clock = { 1L })
        store.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            entitlements = listOf(RouteRuleEntitlementDto("svc_scope_7", 7L, 7007L, 2, 1, listOf("dir_sh_jp_1"))),
            rules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
            bindings = emptyList(),
            capabilities = RouteRuleCapabilitiesDto(true, true),
        )
        repository = RecordingClientRuleRepository()
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `state exposes quota directions and active rules`() = runTest(dispatcher) {
        val vm = ClientRulesViewModel(app, store, repository)
        advanceUntilIdle()

        assertTrue(vm.state.value.enabled)
        assertEquals("svc_scope_7", vm.state.value.selectedServiceScopeId)
        assertEquals("1 / 2", vm.state.value.quotaLabel)
        assertEquals("沪日1", vm.state.value.directions.single().label)
        assertEquals("93.184.216.34:443", vm.state.value.rules.single().destinationLabel)
    }

    @Test
    fun `create validates exact IPv4 port and dispatches repository write`() = runTest(dispatcher) {
        val vm = ClientRulesViewModel(app, store, repository)
        advanceUntilIdle()

        vm.updateDraftDestination("example.com")
        vm.updateDraftPort("443")
        vm.updateDraftDirection("dir_sh_jp_1")
        vm.createRule()
        advanceUntilIdle()
        assertEquals("invalid_destination_ip", vm.state.value.errorCode)
        assertTrue(repository.created.isEmpty())

        vm.updateDraftDestination("203.0.113.10")
        vm.updateDraftPort("8443")
        vm.createRule()
        advanceUntilIdle()

        assertEquals("203.0.113.10", repository.created.single().destinationIp)
        assertEquals(8443, repository.created.single().destinationPort)
        assertEquals("dir_sh_jp_1", repository.created.single().directionId)
    }

    @Test
    fun `toggle and tombstone route through repository`() = runTest(dispatcher) {
        val vm = ClientRulesViewModel(app, store, repository)
        advanceUntilIdle()

        vm.setRuleEnabled("rule_1", false, syncVersion = 1L)
        vm.deleteRule("rule_1")
        advanceUntilIdle()

        assertFalse(repository.updated.single().enabled ?: true)
        assertEquals(1L, repository.updated.single().syncVersion)
        assertEquals(listOf("rule_1"), repository.deleted)
    }

    private class RecordingClientRuleRepository : ClientRuleRepository {
        val created = mutableListOf<ClientRuleWriteDraft>()
        val updated = mutableListOf<ClientRuleWriteDraft>()
        val deleted = mutableListOf<String>()
        override suspend fun createClientRule(draft: ClientRuleWriteDraft) { created += draft }
        override suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft) { updated += draft }
        override suspend fun deleteClientRule(ruleId: String) { deleted += ruleId }
    }
}
```

- [ ] **Step 2: Write the failing product-language/source test**

Create `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesProductLanguageTest.kt`:

```kotlin
package com.leikwan.bridge.ui.clientrules

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class ClientRulesProductLanguageTest {
    private val repoRoot = generateSequence(File(System.getProperty("user.dir"))) { it.parentFile }
        .first { File(it, "settings.gradle.kts").exists() }

    @Test
    fun `client rules screen and navigation resources exist`() {
        val screen = File(repoRoot, "app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt")
        val viewModel = File(repoRoot, "app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt")
        val nav = File(repoRoot, "app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt").readText()
        val strings = File(repoRoot, "app/src/main/res/values/strings.xml").readText()
        val zhStrings = File(repoRoot, "app/src/main/res/values-zh-rCN/strings.xml").readText()

        assertTrue(screen.exists())
        assertTrue(viewModel.exists())
        assertTrue(nav.contains("ClientRulesScreen"))
        listOf(
            "nav_client_rules",
            "client_rules_title",
            "client_rule_destination",
            "client_rule_route_direction",
            "client_rule_quota_format",
            "client_rule_create",
            "client_rule_empty",
        ).forEach { key ->
            assertTrue("missing English string $key", strings.contains("name=\"$key\""))
            assertTrue("missing zh string $key", zhStrings.contains("name=\"$key\""))
        }
    }

    @Test
    fun `new client rule UI surface avoids implementation terms`() {
        val forbidden = listOf(
            "LeiGod",
            "current_mode",
            "cf_mode",
            "forward_combination",
            "cf_location_group",
            "chain",
            "slave group",
            "sidecar",
            "internal line",
            "listener port",
            "runtime mode",
        )
        val files = listOf(
            File(repoRoot, "app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt"),
            File(repoRoot, "app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt"),
        )
        files.forEach { file ->
            val text = file.readText()
            forbidden.forEach { term ->
                assertFalse("${file.name} leaks $term", text.contains(term, ignoreCase = true))
            }
        }

        val routeRuleResourceLines = listOf(
            File(repoRoot, "app/src/main/res/values/strings.xml"),
            File(repoRoot, "app/src/main/res/values-zh-rCN/strings.xml"),
        ).flatMap { file ->
            file.readLines().filter { line ->
                line.contains("client_rule") || line.contains("route_direction") || line.contains("nav_client_rules")
            }
        }
        forbidden.forEach { term ->
            assertFalse("new route-rule resource leaks $term", routeRuleResourceLines.any { it.contains(term, ignoreCase = true) })
        }
    }
}
```

- [ ] **Step 3: Run UI tests and verify they fail before screen/strings exist**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.ui.clientrules.ClientRulesViewModelTest" --tests "com.leikwan.bridge.ui.clientrules.ClientRulesProductLanguageTest"
```

Expected: FAIL with unresolved `ClientRulesViewModel`, missing `ClientRulesScreen.kt`, or missing `nav_client_rules`.

- [ ] **Step 4: Add the ViewModel contract and repository adapter**

Create `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt`:

```kotlin
package com.leikwan.bridge.ui.clientrules

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.RouteDirection
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.api.PanelRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

interface ClientRuleRepository {
    suspend fun createClientRule(draft: ClientRuleWriteDraft)
    suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft)
    suspend fun deleteClientRule(ruleId: String)
}

class PanelClientRuleRepository(private val panelRepository: PanelRepository) : ClientRuleRepository {
    override suspend fun createClientRule(draft: ClientRuleWriteDraft) { panelRepository.createClientRule(draft) }
    override suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft) { panelRepository.updateClientRule(ruleId, draft) }
    override suspend fun deleteClientRule(ruleId: String) { panelRepository.deleteClientRule(ruleId) }
}

data class ClientRulesUiState(
    val enabled: Boolean = false,
    val selectedServiceScopeId: String = "",
    val quotaLabel: String = "0 / 0",
    val directions: List<RouteDirection> = emptyList(),
    val rules: List<ClientRuleRow> = emptyList(),
    val draftDestination: String = "",
    val draftPort: String = "",
    val draftDirectionId: String = "",
    val errorCode: String? = null,
    val saving: Boolean = false,
)

data class ClientRuleRow(
    val id: String,
    val destinationLabel: String,
    val directionLabel: String,
    val enabled: Boolean,
    val stateLabel: String,
    val syncVersion: Long,
)

class ClientRulesViewModel(
    app: Application,
    private val store: ClientRouteRuleStore = ClientRouteRuleStore.from(app),
    private val repository: ClientRuleRepository = PanelClientRuleRepository(PanelRepository(app)),
) : AndroidViewModel(app) {
    private val _state = MutableStateFlow(ClientRulesUiState())
    val state = _state.asStateFlow()

    init {
        refresh()
        viewModelScope.launch {
            store.snapshot.collect { refresh() }
        }
    }

    fun updateDraftDestination(value: String) {
        _state.value = _state.value.copy(draftDestination = value.trim(), errorCode = null)
    }

    fun updateDraftPort(value: String) {
        _state.value = _state.value.copy(draftPort = value.trim(), errorCode = null)
    }

    fun updateDraftDirection(directionId: String) {
        _state.value = _state.value.copy(draftDirectionId = directionId, errorCode = null)
    }

    fun createRule() {
        val draft = ClientRuleWriteDraft(
            serviceScopeId = _state.value.selectedServiceScopeId,
            destinationIp = _state.value.draftDestination,
            destinationPort = _state.value.draftPort.toIntOrNull(),
            directionId = _state.value.draftDirectionId,
            enabled = true,
        )
        ClientRouteRuleStore.validateDraft(draft).error?.let { error ->
            _state.value = _state.value.copy(errorCode = error)
            return
        }
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.createClientRule(draft) }
                .onFailure { _state.value = _state.value.copy(errorCode = it.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
            refresh()
        }
    }

    fun setRuleEnabled(ruleId: String, enabled: Boolean, syncVersion: Long) {
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.updateClientRule(ruleId, ClientRuleWriteDraft(enabled = enabled, syncVersion = syncVersion)) }
                .onFailure { _state.value = _state.value.copy(errorCode = it.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
            refresh()
        }
    }

    fun deleteRule(ruleId: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.deleteClientRule(ruleId) }
                .onFailure { _state.value = _state.value.copy(errorCode = it.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
            refresh()
        }
    }

    private fun refresh() {
        val snapshot = store.current()
        val serviceScopeId = snapshot.entitlements.firstOrNull()?.serviceScopeId.orEmpty()
        val entitlement = snapshot.entitlementFor(serviceScopeId)
        val used = entitlement?.let { it.maxRules - it.remainingRules } ?: 0
        _state.value = _state.value.copy(
            enabled = snapshot.capabilities.routeDirectionRules && snapshot.capabilities.exactDestinationRules,
            selectedServiceScopeId = serviceScopeId,
            quotaLabel = "$used / ${entitlement?.maxRules ?: 0}",
            directions = snapshot.directions,
            draftDirectionId = _state.value.draftDirectionId.ifBlank { snapshot.directions.firstOrNull()?.id.orEmpty() },
            rules = store.activeRulesForScope(serviceScopeId).map { it.toRow(snapshot::directionLabel) },
        )
    }

    private fun ClientRouteRule.toRow(labelForDirection: (String) -> String): ClientRuleRow = ClientRuleRow(
        id = id,
        destinationLabel = "$destinationIp:$destinationPort",
        directionLabel = labelForDirection(directionId),
        enabled = enabled,
        stateLabel = effectiveState.wireValue,
        syncVersion = syncVersion,
    )
}
```

- [ ] **Step 5: Add the Compose Client Rules screen**

Create `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt`:

```kotlin
package com.leikwan.bridge.ui.clientrules

import android.app.Application
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModelProvider.AndroidViewModelFactory.Companion.APPLICATION_KEY
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.leikwan.bridge.R

@Composable
fun ClientRulesScreen(
    viewModel: ClientRulesViewModel = viewModel(
        factory = viewModelFactory {
            initializer { ClientRulesViewModel(this[APPLICATION_KEY] as Application) }
        },
    ),
) {
    val state by viewModel.state.collectAsState()
    ClientRulesScreenContent(
        state = state,
        onDestinationChange = viewModel::updateDraftDestination,
        onPortChange = viewModel::updateDraftPort,
        onDirectionChange = viewModel::updateDraftDirection,
        onCreate = viewModel::createRule,
        onSetEnabled = viewModel::setRuleEnabled,
        onDelete = viewModel::deleteRule,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ClientRulesScreenContent(
    state: ClientRulesUiState,
    onDestinationChange: (String) -> Unit,
    onPortChange: (String) -> Unit,
    onDirectionChange: (String) -> Unit,
    onCreate: () -> Unit,
    onSetEnabled: (String, Boolean, Long) -> Unit,
    onDelete: (String) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text(stringResource(R.string.client_rules_title), style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.client_rule_quota_format, state.quotaLabel),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (!state.enabled) {
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(R.string.client_rule_unavailable),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
        Spacer(Modifier.height(16.dp))

        OutlinedTextField(
            value = state.draftDestination,
            onValueChange = onDestinationChange,
            enabled = state.enabled,
            label = { Text(stringResource(R.string.client_rule_destination)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.draftPort,
            onValueChange = onPortChange,
            enabled = state.enabled,
            label = { Text(stringResource(R.string.client_rule_port)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(8.dp))
        var directionExpanded by remember { mutableStateOf(false) }
        ExposedDropdownMenuBox(
            expanded = directionExpanded,
            onExpandedChange = { if (state.enabled) directionExpanded = it },
        ) {
            val selected = state.directions.firstOrNull { it.id == state.draftDirectionId }
            OutlinedTextField(
                value = selected?.label.orEmpty(),
                onValueChange = {},
                enabled = state.enabled,
                readOnly = true,
                label = { Text(stringResource(R.string.client_rule_route_direction)) },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(directionExpanded) },
                modifier = Modifier.fillMaxWidth().menuAnchor(),
            )
            ExposedDropdownMenu(
                expanded = directionExpanded,
                onDismissRequest = { directionExpanded = false },
            ) {
                state.directions.forEach { direction ->
                    DropdownMenuItem(
                        text = { Text(direction.label) },
                        onClick = {
                            onDirectionChange(direction.id)
                            directionExpanded = false
                        },
                    )
                }
            }
        }
        Spacer(Modifier.height(8.dp))
        Button(
            onClick = onCreate,
            enabled = state.enabled && !state.saving,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(stringResource(R.string.client_rule_create))
        }
        state.errorCode?.let { error ->
            Spacer(Modifier.height(8.dp))
            Text(error, color = MaterialTheme.colorScheme.error)
        }

        Spacer(Modifier.height(24.dp))
        Text(stringResource(R.string.client_rule_saved_rules), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        if (state.rules.isEmpty()) {
            Text(stringResource(R.string.client_rule_empty), color = MaterialTheme.colorScheme.onSurfaceVariant)
        } else {
            state.rules.forEach { rule ->
                ClientRuleRowCard(rule, onSetEnabled, onDelete)
                Spacer(Modifier.height(8.dp))
            }
        }
    }
}

@Composable
private fun ClientRuleRowCard(
    rule: ClientRuleRow,
    onSetEnabled: (String, Boolean, Long) -> Unit,
    onDelete: (String) -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(12.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(rule.destinationLabel, style = MaterialTheme.typography.titleMedium)
                Text(rule.directionLabel, style = MaterialTheme.typography.bodyMedium)
                Text(rule.stateLabel, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Switch(
                checked = rule.enabled,
                onCheckedChange = { onSetEnabled(rule.id, it, rule.syncVersion) },
            )
            IconButton(onClick = { onDelete(rule.id) }) {
                Icon(Icons.Default.Delete, contentDescription = stringResource(R.string.common_delete))
            }
        }
    }
}
```

- [ ] **Step 6: Add navigation entry**

In `AppNavigation.kt`, add imports:

```kotlin
import androidx.compose.material.icons.filled.Route
import com.leikwan.bridge.ui.clientrules.ClientRulesScreen
```

Add enum entry between `Tunnels` and `Proxies`:

```kotlin
ClientRules("client_rules", R.string.nav_client_rules, Icons.Default.Route),
```

Add a composable route in the `NavHost`:

```kotlin
composable(Screen.ClientRules.route) { ClientRulesScreen() }
```

- [ ] **Step 7: Add localized product strings**

In `values/strings.xml`, add these strings after `nav_routing`:

```xml
<string name="nav_client_rules">Client Rules</string>
```

Add these strings near the existing routing strings:

```xml
<string name="client_rules_title">Client Rules</string>
<string name="client_rule_destination">Destination IP</string>
<string name="client_rule_port">Destination port</string>
<string name="client_rule_route_direction">Route Direction</string>
<string name="client_rule_quota_format">Saved rules: %1$s</string>
<string name="client_rule_create">Create Client Rule</string>
<string name="client_rule_saved_rules">Saved Client Rules</string>
<string name="client_rule_empty">No Client Rules yet.</string>
<string name="client_rule_unavailable">Client Rules are not available for this service.</string>
```

In `values-zh-rCN/strings.xml`, add after `nav_routing`:

```xml
<string name="nav_client_rules">客户端规则</string>
```

Add near the existing routing strings:

```xml
<string name="client_rules_title">客户端规则</string>
<string name="client_rule_destination">目标 IP</string>
<string name="client_rule_port">目标端口</string>
<string name="client_rule_route_direction">线路方向</string>
<string name="client_rule_quota_format">已保存规则：%1$s</string>
<string name="client_rule_create">创建客户端规则</string>
<string name="client_rule_saved_rules">已保存的客户端规则</string>
<string name="client_rule_empty">暂无客户端规则。</string>
<string name="client_rule_unavailable">当前服务不可使用客户端规则。</string>
```

- [ ] **Step 8: Run UI and product-language tests**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.ui.clientrules.ClientRulesViewModelTest" --tests "com.leikwan.bridge.ui.clientrules.ClientRulesProductLanguageTest"
```

Expected: PASS.

- [ ] **Step 9: Commit UI changes**

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt leikwan-android/app/src/main/res/values/strings.xml leikwan-android/app/src/main/res/values-zh-rCN/strings.xml leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModelTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesProductLanguageTest.kt
git commit -m "feat(android): add client rule management UI" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Full Android verification and plan handoff checks

**Files:**
- Verify: Android source and tests touched in Tasks 1-7
- Verify: `docs/superpowers/plans/2026-07-22-android-route-direction-client-rules.md`

- [ ] **Step 1: Run the focused Android route-rule test set**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.RouteRulePullModelsTest" --tests "com.leikwan.bridge.data.clientrules.ClientRouteRuleStoreTest" --tests "com.leikwan.bridge.api.PanelRepositoryRouteRuleSyncTest" --tests "com.leikwan.bridge.runtime.RouteDirectionRuntimePlannerTest" --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest" --tests "com.leikwan.bridge.service.TunnelOrchestratorTest" --tests "com.leikwan.bridge.ui.clientrules.ClientRulesViewModelTest" --tests "com.leikwan.bridge.ui.clientrules.ClientRulesProductLanguageTest"
```

Expected: PASS.

- [ ] **Step 2: Run the full Android unit test suite**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest
```

Expected: PASS.

- [ ] **Step 3: Verify no Room migration was introduced for Client Rules**

Run this PowerShell command:

```powershell
$database = Get-Content "leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt" -Raw; if ($database -match "ClientRouteRule|RouteDirectionEntity|DirectionRuntimeBindingEntity|MIGRATION_3_4") { throw "Unexpected Room route-rule schema change" } else { "No Room route-rule migration introduced" }
```

Expected: prints `No Room route-rule migration introduced`.

- [ ] **Step 4: Verify helper forwards stay hidden from selection**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest.route rule helper forwards are parsed but hidden from visible catalog"
```

Expected: PASS.

- [ ] **Step 5: Verify exact route-rule ordering remains locked**

Run:

```powershell
.\leikwan-android\gradlew.bat -p leikwan-android :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest.client rules are exact ip port rules after protected paths and before country rules"
```

Expected: PASS.

- [ ] **Step 6: Run a placeholder scan over this plan**

Run this PowerShell command:

```powershell
$plan = "docs/superpowers/plans/2026-07-22-android-route-direction-client-rules.md"; $patterns = @("T" + "BD", "TO" + "DO", "similar" + " to", "appropriate" + " error handling", "fill" + " in", "<exact-files-shown" + "-by-git-status>"); foreach ($pattern in $patterns) { Select-String -Path $plan -Pattern $pattern -SimpleMatch }
```

Expected: no output.

- [ ] **Step 7: Verify the Android binding contract is shared nested-only**

Run this PowerShell command:

```powershell
$plan = "docs/superpowers/plans/2026-07-22-android-route-direction-client-rules.md"; $text = Get-Content $plan -Raw; $required = @("service_scope_id", "direction_id", "binding_state", "client_runtime_binding_id", "runtime_ref", "local_endpoint", "host", "tcp_port", "udp_port", "tcp_ready", "udp_ready", "readyForAndroid", "bindingFor(serviceScopeId, directionId)"); foreach ($token in $required) { if ($text -notmatch [regex]::Escape($token)) { throw "Missing binding contract token: $token" } }; $forbidden = @("local_" + "tcp_port", "local_" + "udp_port", "bindingFor" + "(directionId)", "route-" + "direction-" + "`$directionId", "processKey" + "(directionId)"); foreach ($token in $forbidden) { if ($text -match [regex]::Escape($token)) { throw "Found private flat or direction-only binding contract: $token" } }; "Shared nested Android binding contract verified"
```

Expected: prints `Shared nested Android binding contract verified`.

- [ ] **Step 8: Commit final verification adjustments**

Run:

```powershell
git status --short
```

Expected: only files from this Android implementation are modified or untracked.

Commit any final Android verification adjustments with exact paths from `git status --short`:

```powershell
git add leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelApi.kt leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt leikwan-android/app/src/main/java/com/leikwan/bridge/clientrules/ClientRouteRuleModels.kt leikwan-android/app/src/main/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStore.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlanner.kt leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModel.kt leikwan-android/app/src/main/java/com/leikwan/bridge/ui/clientrules/ClientRulesScreen.kt leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt leikwan-android/app/src/main/res/values/strings.xml leikwan-android/app/src/main/res/values-zh-rCN/strings.xml leikwan-android/app/src/test/java/com/leikwan/bridge/api/RouteRulePullModelsTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/data/clientrules/ClientRouteRuleStoreTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryRouteRuleSyncTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RouteDirectionRuntimePlannerTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesViewModelTest.kt leikwan-android/app/src/test/java/com/leikwan/bridge/ui/clientrules/ClientRulesProductLanguageTest.kt
git commit -m "test(android): verify route direction client rules" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds. If there are no final adjustments beyond previous task commits, skip this commit and record `No final verification commit required` in the execution notes.

## Self-review checklist

- [ ] The plan consumes `route_directions`, `route_rule_entitlements`, `client_rules`, `direction_runtime_bindings`, and `capabilities` from config pull, with direction runtime bindings using the shared nested `local_endpoint` contract and no Android legacy flat local TCP/UDP port parsing.
- [ ] The plan advertises Android route-rule capabilities through heartbeat before config pull.
- [ ] The plan includes create/update/delete Client Rule APIs and stable error handling through `PanelRepository`.
- [ ] The plan explicitly chooses a non-Room file-backed store and does not add `AppDatabase` migrations.
- [ ] The store contract includes pending create, pending update, pending tombstone, server acknowledgement, and pending error methods.
- [ ] Helper forwards are parsed and cached but hidden from visible service selection.
- [ ] Multi-direction runtime planning starts one helper runtime per active ready service-scope + Route Direction binding identity, preferring opaque `client_runtime_binding_id` over the fallback `service_scope_id::direction_id` identity.
- [ ] sing-box Client Rule entries are exact IPv4 + port, split TCP/UDP, and inserted after protected/runtime reachability but before country/category/default user routing.
- [ ] Enabled but non-installable Client Rules fail closed with reject route rules.
- [ ] The Client Rules UI and new resources use product-only language, never display `client_runtime_binding_id`, and write APIs never accept that opaque identifier.
- [ ] Commands are PowerShell-compatible for the Windows primary environment.
- [ ] Commit commands use exact paths and include the required co-author trailer.
