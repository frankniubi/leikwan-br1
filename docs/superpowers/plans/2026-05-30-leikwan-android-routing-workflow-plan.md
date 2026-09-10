# LeiKwan Android Routing and Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved balanced-enhancement Android workflow so the root `leikwan-android/` app preserves the current `client -> slave -> sidecar -> target` path, keeps Android support focused on `single` / `bond`, and adds curated country/category custom routing with only `TUNNEL` or `DIRECT` outcomes.

**Architecture:** Build the feature in phased slices. First normalize raw panel forwards into Android-effective runtime intent without changing the current single/bond baseline. Next add the safe routing core and protected-path precedence, then add rule-pack ingestion plus explainability persistence, then integrate the compiled routing contract into sing-box generation and runtime orchestration, and finally expose diagnostics in the UI. Keep one authoritative routing snapshot per compile cycle and make every failure degrade conservatively instead of inventing new routing graphs.

**Tech Stack:** Kotlin, Jetpack Compose, Retrofit/OkHttp, Room, EncryptedSharedPreferences, sing-box `libbox.aar`, Android `VpnService`, coroutines, JUnit JVM tests, Android instrumented UI tests.

---

## File structure

### Existing files to modify

- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt` — panel login/pull/cache boundary; add Android-effective contract normalization handoff and device-identity request behavior.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt` — extend panel-facing models only if extra routing/rule-pack metadata truly comes from the approved contract.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt` — keep raw contract parsing, add Android-effective contract types if needed.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt` — preserve only Android-supported `single` / `bond` runtime visibility and deterministic selection behavior.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt` — keep current process-plan structure, or narrow its role once `RuntimeIntent` becomes the orchestration input.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/Prefs.kt` — add routing/rule-pack/explainability metadata while keeping `logout()` auth-only.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt` — add new entities/DAOs and migration for rule-pack and explainability persistence.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt` — consume `RuntimeIntent` + `RoutingPolicySnapshot`, enforce protected-path precedence, and return a derived compiled result.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt` — switch to compile-first orchestration, preserve single/bond baseline, stop cleanly on compile/start failures.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt` — keep TUN startup deterministic and config-driven, validate startup error handling, preserve protected-path semantics.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt` — keep TUN plan conservative and deterministic while threading any required protected metadata.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxySelectionPolicy.kt` — fix zero-selection preflight behavior and keep validation aligned with the compile-first flow.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsViewModel.kt` — expose rule-pack/explainability summaries only.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt` — render curated routing diagnostics and refresh state.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt` — expose runtime routing summary and warnings only.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt` — render diagnostics consistently with settings.

### New files to create

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilder.kt` — normalize raw panel contracts into Android-effective runtime input.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeIntent.kt` — single orchestration input for compile/start.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyModels.kt` — `UserTrafficOutcome`, `ProtectedPathHandling`, `RoutingDecisionReason`, `RoutingRule`, `RoutingRequest`, `RoutingDecision`.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/SafePathRegistry.kt` — authoritative protected destinations and baseline-safe Android paths.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicySnapshot.kt` — single authoritative routing contract for one compile/rebuild cycle.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyEngine.kt` — pure routing decision engine and safe-subset downgrade logic.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RulePackApi.kt` — narrow update-endpoint contract for rule-pack fetch/manifest metadata.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RulePackSnapshot.kt` — pinned active/previous rule-pack state.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RulePackManager.kt` — validation, activation, rollback, freshness handling.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingExplainabilityStore.kt` — persist the last compiled routing summary.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/routing/CompiledConfigResult.kt` — derived compile artifact returned by the sing-box builder.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/shared/RoutingDiagnosticsUiModels.kt` — shared UI-facing diagnostic model.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackEntities.kt` — Room entities for active/previous rule packs.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackDao.kt` — DAO for rule-pack persistence.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityEntities.kt` — Room entity for the latest compiled explainability snapshot.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityDao.kt` — DAO for explainability persistence.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilderTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/routing/SafePathRegistryTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/routing/RoutingPolicyEngineTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/PrefsRoutingStateTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/AppDatabaseRoutingTablesTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RulePackManagerTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RoutingExplainabilityStoreTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/service/LeikwanVpnServiceTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/settings/SettingsViewModelTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModelTest.kt`
- `leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/settings/SettingsScreenTest.kt`
- `leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/tunnels/TunnelsScreenTest.kt`

## Sequencing notes

- Start with contract/baseline cleanup. Do not add routing engine or rule-pack code until the Android-effective contract seam exists.
- Implement the safe routing core in pure JVM code before touching orchestration or UI.
- Pin rule-pack activation to compile points. Never let background refresh mutate the active routing contract mid-session.
- Keep the compile result derived from `RuntimeIntent` + `RoutingPolicySnapshot`; do not let it become a second routing authority.
- UI must only display explainability state. It must not recompute routing policy locally.
- Follow TDD at each slice: failing tests first, then minimal implementation, then targeted test pass, then full suite pass, then commit.

## Plan warnings

- Do not broaden v1 into imported-node routing, arbitrary outbound graphs, app-level split tunneling, or Android `mptcp`/`race` support.
- The current root app already has working single/bond orchestration. Every task must preserve that baseline unless the tests prove an intentional contract change.
- Protected-path precedence is a product invariant, not an optimization. If panel/bootstrap traffic can be captured by user rules, the feature is broken.
- Rule-pack updates are advisory enhancements. Their failure must never take down login, config pull, or the default tunnel path.
- Android lifecycle failures are first-class test scenarios, especially VPN permission loss, foreground-service interruption, process death, and startup timeout.

### Task 1: Contract cleanup and Android-effective forward normalization

**Files:**
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilder.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeIntent.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelModelsContractParsingTest.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/ForwardFilterTest.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/SingleForwardRunnerTest.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilderTest.kt`

- [ ] **Step 1: Write the failing Android-effective normalization tests**

Add a new runtime test file that locks the approved seam between raw panel contract parsing and Android-effective runtime intent.

```kotlin
package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AndroidEffectiveForwardBuilderTest {

    @Test
    fun `builder keeps single and bond but rejects unsupported runtime modes`() {
        val single = sampleContract(forwardId = 11L, mode = ForwardMode.SINGLE)
        val bond = sampleContract(forwardId = 22L, mode = ForwardMode.BOND)
        val race = sampleContract(forwardId = 33L, mode = ForwardMode.RACE)
        val mptcp = sampleContract(forwardId = 44L, mode = ForwardMode.MPTCP)

        val visible = listOfNotNull(
            AndroidEffectiveForwardBuilder.build(single),
            AndroidEffectiveForwardBuilder.build(bond),
            AndroidEffectiveForwardBuilder.build(race),
            AndroidEffectiveForwardBuilder.build(mptcp),
        )

        assertEquals(listOf(11L, 22L), visible.map { it.forwardId })
        assertFalse(visible.any { it.mode == ForwardMode.RACE })
        assertFalse(visible.any { it.mode == ForwardMode.MPTCP })
    }

    @Test
    fun `builder keeps deterministic selection metadata and hidden unsupported ids`() {
        val single = sampleContract(forwardId = 11L, mode = ForwardMode.SINGLE)
        val hidden = sampleContract(forwardId = 22L, mode = ForwardMode.MPTCP)

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(single, hidden),
            selectedForwardId = 22L,
        )

        assertNull(catalog.selectedForwardId)
        assertTrue(catalog.hiddenForwardIds.contains(22L))
        assertEquals(1, catalog.hiddenUnsupportedMptcpCount)
    }

    private fun sampleContract(forwardId: Long, mode: ForwardMode): ForwardContract {
        return ForwardContract(
            forwardId = forwardId,
            serviceName = "forward-$forwardId",
            target = "example.com:443",
            listenPort = 11000 + forwardId.toInt(),
            mode = mode,
            transport = "tcp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "example.com",
            pathPrefix = "/test",
            speedLimitMbps = null,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = forwardId.toInt(),
                    node = "node-$forwardId",
                    host = "8.8.8.${forwardId}",
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
        )
    }
}
```

- [ ] **Step 2: Extend the existing parser/filter tests so the seam is explicit**

Update `PanelModelsContractParsingTest.kt` to confirm raw parsing still preserves the panel-shaped bond and mptcp fields, and update `ForwardFilterTest.kt` to keep the current “hide unsupported and clear stale selection” behavior as the compatibility anchor.

Run: `./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.api.PanelModelsContractParsingTest --tests com.leikwan.bridge.runtime.ForwardFilterTest --tests com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilderTest`

Expected: FAIL because `AndroidEffectiveForwardBuilder` does not exist yet.

- [ ] **Step 3: Implement the Android-effective model and builder**

Create a small Android-only contract shape and keep it narrower than `ForwardContract`.

```kotlin
package com.leikwan.bridge.runtime

data class AndroidEffectiveForward(
    val forwardId: Long,
    val serviceName: String,
    val target: String,
    val listenPort: Int,
    val mode: ForwardMode,
    val transport: String,
    val endpoints: List<ForwardEndpoint>,
    val bonding: BondingContract?,
    val bypassHosts: Set<String>,
)

object AndroidEffectiveForwardBuilder {
    fun build(contract: ForwardContract): AndroidEffectiveForward? {
        if (contract.mode != ForwardMode.SINGLE && contract.mode != ForwardMode.BOND) {
            return null
        }
        val bypassHosts = linkedSetOf<String>()
        contract.endpoints.forEach { endpoint ->
            bypassHosts += endpoint.host
            endpoint.cdnHost?.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        }
        contract.sni.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        return AndroidEffectiveForward(
            forwardId = contract.forwardId,
            serviceName = contract.serviceName,
            target = contract.target,
            listenPort = contract.listenPort,
            mode = contract.mode,
            transport = contract.transport,
            endpoints = contract.endpoints,
            bonding = contract.bonding,
            bypassHosts = bypassHosts,
        )
    }
}
```

- [ ] **Step 4: Introduce `RuntimeIntent` and thread it through the runtime boundary without changing runner command shapes**

Create a new orchestration input and keep the existing single/bond plan builders stable.

```kotlin
package com.leikwan.bridge.runtime

data class RuntimeIntent(
    val forwardId: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
)

fun RuntimeContext.toRuntimeIntent(): RuntimeIntent {
    return RuntimeIntent(
        forwardId = forwardId,
        mode = mode,
        localListenPort = localListenPort,
        localLegPorts = localLegPorts,
        processPlans = processPlans,
        bypassHosts = bypassHosts,
    )
}
```

At this stage, keep `SingleForwardRunner.buildPlan(...)` and `BondForwardRunner.buildPlan(...)` returning `RuntimeContext`, but add the conversion point so later tasks can move orchestrator/builders onto `RuntimeIntent` without changing command generation in this slice.

- [ ] **Step 5: Update `PanelRepository.pullCatalog()` to build and cache normalized Android-effective contracts alongside raw parsed contracts**

Preserve the current raw cache, but add a second Android-effective cache so later tasks stop reading raw panel-shaped data by accident.

```kotlin
private var cachedEffectiveForwards = mutableMapOf<Long, AndroidEffectiveForward>()

suspend fun pullCatalog(selectedForwardId: Long = prefs.selectedForwardId): ForwardCatalog {
    val resp = api.pullConfigs(authHeader())
    if (!resp.ok) throw Exception("Pull configs failed")
    cachedForwardConfigs.clear()
    cachedForwardContracts.clear()
    cachedEffectiveForwards.clear()
    val contracts = mutableListOf<ForwardContract>()
    for (raw in resp.forwards) {
        try {
            val contract = ForwardContractParser.parse(raw) ?: continue
            contracts += contract
            cachedForwardConfigs[contract.forwardId] = raw
            cachedForwardContracts[contract.forwardId] = contract
            AndroidEffectiveForwardBuilder.build(contract)?.let { effective ->
                cachedEffectiveForwards[effective.forwardId] = effective
            }
        } catch (_: Exception) {
            // skip unparseable forwards
        }
    }
    return ForwardFilter.buildCatalog(
        contracts = contracts,
        selectedForwardId = selectedForwardId.takeIf { it > 0L },
    )
}

fun getCachedEffectiveForward(forwardId: Long): AndroidEffectiveForward? = cachedEffectiveForwards[forwardId]
```

- [ ] **Step 6: Run the targeted tests again and confirm the baseline remains stable**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.api.PanelModelsContractParsingTest --tests com.leikwan.bridge.runtime.ForwardFilterTest --tests com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilderTest --tests com.leikwan.bridge.runtime.SingleForwardRunnerTest --tests com.leikwan.bridge.runtime.BondForwardRunnerTest
```

Expected: PASS. Raw parsing still preserves bond/mptcp payloads, unsupported modes remain hidden from Android visibility, and the new builder produces only `single` / `bond` Android-effective forwards.

- [ ] **Step 7: Commit the Phase A slice**

```bash
git add \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilder.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeIntent.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelModelsContractParsingTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/ForwardFilterTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilderTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/SingleForwardRunnerTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt"

git commit -m "feat: normalize android forward contracts"
```

### Task 2: Safe routing core and protected-path precedence

**Files:**
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyModels.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/SafePathRegistry.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicySnapshot.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyEngine.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/routing/RoutingPolicyEngineTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/routing/SafePathRegistryTest.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`

- [ ] **Step 1: Write the failing tests for protected-path precedence and snapshot shape**

Create `RoutingPolicyEngineTest.kt` with one test that proves system-protected traffic wins before user rules, and one test that proves the compiled snapshot carries the hard-safe entries and conservative fallback:

```kotlin
package com.leikwan.bridge.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RoutingPolicyEngineTest {

    @Test
    fun `panel traffic stays protected even when a user rule says tunnel`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )
        val engine = RoutingPolicyEngine(registry)
        val snapshot = engine.compile(
            countryRules = listOf(
                RoutingRule(
                    id = "country-cn",
                    kind = RoutingRuleKind.COUNTRY,
                    matchValue = "CN",
                    outcome = UserTrafficOutcome.TUNNEL,
                )
            ),
            categoryRules = listOf(
                RoutingRule(
                    id = "category-video",
                    kind = RoutingRuleKind.WEBSITE_CATEGORY,
                    matchValue = "video",
                    outcome = UserTrafficOutcome.DIRECT,
                )
            ),
            rulePackVersion = "country-category-2026.05.30",
            compiledAtEpochMillis = 1717046400000,
        )

        val decision = engine.decide(
            snapshot = snapshot,
            request = RoutingRequest(
                destinationHost = "panel.example.com",
                destinationIp = null,
                countryCode = "CN",
                websiteCategory = "video",
                isBootstrapTraffic = true,
            ),
        )

        assertEquals(UserTrafficOutcome.DIRECT, decision.outcome)
        assertEquals(RoutingDecisionReason.SYSTEM_PROTECTED, decision.reason)
        assertEquals("panel-api", decision.protectedPathId)
    }

    @Test
    fun `snapshot keeps protected paths and fallback action`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )
        val snapshot = RoutingPolicyEngine(registry).compile(
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersion = "empty-2026.05.30",
            compiledAtEpochMillis = 1717046400000,
        )

        assertEquals(UserTrafficOutcome.TUNNEL, snapshot.defaultUserTrafficOutcome)
        assertEquals(UserTrafficOutcome.DIRECT, snapshot.fallbackOutcome)
        assertTrue(snapshot.protectedPaths.any { it.id == "panel-api" })
        assertTrue(snapshot.protectedPaths.any { it.id == "panel-mirror" })
        assertTrue(snapshot.precedenceVersion >= 1)
    }
}
```

Create `SafePathRegistryTest.kt` to lock the registry entries that the engine and config builder both rely on:

```kotlin
package com.leikwan.bridge.routing

import org.junit.Assert.assertTrue
import org.junit.Test

class SafePathRegistryTest {

    @Test
    fun `android baseline registry marks panel and mirror domains as protected`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )

        assertTrue(registry.containsDomain("panel.example.com"))
        assertTrue(registry.containsDomain("v3-mirror.leikwanhost.com"))
        assertTrue(registry.containsIpCidr("127.0.0.0/8"))
    }
}
```

Extend `SingboxConfigBuilderTest.kt` with one route-precedence test that proves protected direct rules are emitted before any user bypass rules or runtime bypass hosts:

```kotlin
@Test
fun `builder inserts protected direct routes before user bypass routes`() {
    val builder = SingboxConfigBuilder(
        settings = SingboxSettings(
            panelUrl = "https://panel.example.com",
            dnsServer = "1.1.1.1",
            dnsFallback = "8.8.8.8",
            dnsStrategy = "prefer_ipv4",
            bypassLan = true,
            bypassChina = false,
            customBypassRules = "panel.example.com",
        )
    )
    val snapshot = RoutingPolicySnapshot(
        protectedPaths = listOf(
            SafePathEntry(
                id = "panel-api",
                domains = setOf("panel.example.com"),
                ipCidrs = emptySet(),
                handling = ProtectedPathHandling.BYPASS_DIRECT,
            ),
            SafePathEntry(
                id = "panel-mirror",
                domains = setOf("v3-mirror.leikwanhost.com"),
                ipCidrs = emptySet(),
                handling = ProtectedPathHandling.BYPASS_DIRECT,
            ),
        ),
        userCountryRules = emptyList(),
        userCategoryRules = emptyList(),
        defaultUserTrafficOutcome = UserTrafficOutcome.TUNNEL,
        fallbackOutcome = UserTrafficOutcome.DIRECT,
        activeRulePackVersions = listOf("country-category-2026.05.30"),
        compileTimestampMs = 1717046400000,
        warnings = emptyList(),
        precedenceVersion = 1,
    )

    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            routingPolicySnapshot = snapshot,
            connectionMode = ProxyConnectionMode.DIRECT,
        )
    ).asJsonObject
    val routeRules = config.getAsJsonObject("route").getAsJsonArray("rules")

    assertEquals("sniff", routeRules[0].asJsonObject.get("action").asString)
    assertEquals("hijack-dns", routeRules[1].asJsonObject.get("action").asString)
    assertEquals("direct", routeRules[2].asJsonObject.get("outbound").asString)
    assertEquals("direct", routeRules[3].asJsonObject.get("outbound").asString)
}
```

- [ ] **Step 2: Run the targeted tests and confirm they fail before the new routing code exists**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.routing.RoutingPolicyEngineTest
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest
```

Expected: FAIL because `RoutingPolicyEngine`, `SafePathRegistry`, `RoutingPolicySnapshot`, and the new builder signature do not exist yet.

- [ ] **Step 3: Implement the new routing models, registry, engine, and snapshot with the smallest safe API**

Create `RoutingPolicyModels.kt`:

```kotlin
package com.leikwan.bridge.routing

enum class UserTrafficOutcome { TUNNEL, DIRECT }

enum class ProtectedPathHandling { BYPASS_DIRECT }

enum class RoutingDecisionReason {
    SYSTEM_PROTECTED,
    USER_RULE,
    DEFAULT_TUNNEL,
    FALLBACK_TUNNEL,
    UNSUPPORTED_RULE_REJECTED,
}

enum class RoutingRuleKind { COUNTRY, WEBSITE_CATEGORY }

data class RoutingRule(
    val id: String,
    val kind: RoutingRuleKind,
    val matchValue: String,
    val outcome: UserTrafficOutcome,
)

data class RoutingRequest(
    val destinationHost: String?,
    val destinationIp: String?,
    val countryCode: String?,
    val websiteCategory: String?,
    val isBootstrapTraffic: Boolean,
)

data class RoutingDecision(
    val outcome: UserTrafficOutcome,
    val reason: RoutingDecisionReason,
    val matchedRuleId: String?,
    val protectedPathId: String?,
)

data class SafePathEntry(
    val id: String,
    val domains: Set<String>,
    val ipCidrs: Set<String>,
    val handling: ProtectedPathHandling,
)
```

Create `SafePathRegistry.kt`:

```kotlin
package com.leikwan.bridge.routing

class SafePathRegistry private constructor(
    private val entries: List<SafePathEntry>,
) {
    fun protectedEntries(): List<SafePathEntry> = entries

    fun containsDomain(domain: String): Boolean =
        entries.any { entry -> entry.domains.any { it.equals(domain, ignoreCase = true) } }

    fun containsIpCidr(cidr: String): Boolean =
        entries.any { entry -> entry.ipCidrs.contains(cidr) }

    companion object {
        fun androidBaseline(panelUrl: String, mirrorHost: String): SafePathRegistry {
            val panelHost = panelUrl.removePrefix("https://").removePrefix("http://").substringBefore("/")
            return SafePathRegistry(
                listOf(
                    SafePathEntry(
                        id = "panel-api",
                        domains = setOf(panelHost.lowercase()),
                        ipCidrs = emptySet(),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                    SafePathEntry(
                        id = "panel-mirror",
                        domains = setOf(mirrorHost.lowercase()),
                        ipCidrs = emptySet(),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                    SafePathEntry(
                        id = "loopback-control",
                        domains = emptySet(),
                        ipCidrs = setOf("127.0.0.0/8", "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"),
                        handling = ProtectedPathHandling.BYPASS_DIRECT,
                    ),
                )
            )
        }
    }
}
```

Create `RoutingPolicySnapshot.kt`:

```kotlin
package com.leikwan.bridge.routing

data class RoutingPolicySnapshot(
    val protectedPaths: List<SafePathEntry>,
    val userCountryRules: List<RoutingRule>,
    val userCategoryRules: List<RoutingRule>,
    val defaultUserTrafficOutcome: UserTrafficOutcome,
    val fallbackOutcome: UserTrafficOutcome,
    val activeRulePackVersions: List<String>,
    val compileTimestampMs: Long,
    val warnings: List<String>,
    val precedenceVersion: Int,
)
```

Create `RoutingPolicyEngine.kt`:

```kotlin
package com.leikwan.bridge.routing

class RoutingPolicyEngine(
    private val safePathRegistry: SafePathRegistry,
) {
    fun compile(
        countryRules: List<RoutingRule>,
        categoryRules: List<RoutingRule>,
        rulePackVersion: String,
        compiledAtEpochMillis: Long,
    ): RoutingPolicySnapshot {
        return RoutingPolicySnapshot(
            protectedPaths = safePathRegistry.protectedEntries(),
            userCountryRules = countryRules,
            userCategoryRules = categoryRules,
            defaultUserTrafficOutcome = UserTrafficOutcome.TUNNEL,
            fallbackOutcome = UserTrafficOutcome.DIRECT,
            activeRulePackVersions = listOf(rulePackVersion),
            compileTimestampMs = compiledAtEpochMillis,
            warnings = emptyList(),
            precedenceVersion = 1,
        )
    }

    fun decide(snapshot: RoutingPolicySnapshot, request: RoutingRequest): RoutingDecision {
        val protectedMatch = snapshot.protectedPaths.firstOrNull { entry ->
            val host = request.destinationHost?.lowercase()
            val ip = request.destinationIp
            host != null && entry.domains.any { it.equals(host, ignoreCase = true) } ||
                ip != null && entry.ipCidrs.contains(ip)
        }
        if (protectedMatch != null) {
            return RoutingDecision(
                outcome = UserTrafficOutcome.DIRECT,
                reason = RoutingDecisionReason.SYSTEM_PROTECTED,
                matchedRuleId = null,
                protectedPathId = protectedMatch.id,
            )
        }

        val countryRule = snapshot.userCountryRules.firstOrNull { it.matchValue.equals(request.countryCode, ignoreCase = true) }
        if (countryRule != null) {
            return RoutingDecision(countryRule.outcome, RoutingDecisionReason.USER_RULE, countryRule.id, null)
        }

        val categoryRule = snapshot.userCategoryRules.firstOrNull { it.matchValue.equals(request.websiteCategory, ignoreCase = true) }
        if (categoryRule != null) {
            return RoutingDecision(categoryRule.outcome, RoutingDecisionReason.USER_RULE, categoryRule.id, null)
        }

        return RoutingDecision(
            outcome = snapshot.defaultUserTrafficOutcome,
            reason = RoutingDecisionReason.DEFAULT_TUNNEL,
            matchedRuleId = null,
            protectedPathId = null,
        )
    }
}
```

Update `SingboxConfigBuilder.kt` so route generation consumes `RoutingPolicySnapshot` and emits protected direct rules before any runtime or user bypass rules:

```kotlin
fun build(
    proxyNodes: List<ProxyNode>,
    runtimeContext: RuntimeContext?,
    routingPolicySnapshot: RoutingPolicySnapshot? = null,
    connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
): String
```

In `buildRoute(...)`, keep the existing `sniff` and `hijack-dns` actions first, then add protected direct rules from `routingPolicySnapshot?.protectedPaths`, then add `runtimeContext?.bypassHosts`, then add `bypassLan`, `bypassChina`, and `customBypassRules`.

- [ ] **Step 4: Run the targeted tests again and confirm the precedence behavior passes**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.routing.RoutingPolicyEngineTest
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest
```

Expected: PASS. Protected panel traffic resolves to `DIRECT` with reason `SYSTEM_PROTECTED`, and protected direct rules appear before user bypass rules in the route array.

- [ ] **Step 5: Run the full app JVM suite and then commit the slice**

Run: `./gradlew :app:testDebugUnitTest`

Expected: PASS. Existing single/bond tests still pass because runtime start behavior has not been widened or reordered.

Commit:

```bash
git add \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyModels.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/SafePathRegistry.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicySnapshot.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyEngine.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/routing/RoutingPolicyEngineTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/routing/SafePathRegistryTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt"

git commit -m "feat: add safe routing precedence core"
```

### Task 3: Rule-pack ingestion and explainability persistence

**Files:**
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/Prefs.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/build.gradle.kts`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackSnapshot.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackEntities.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackDao.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilitySnapshot.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityDao.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackManager.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityStore.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/PrefsRoutingStateTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/AppDatabaseRoutingTablesTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RulePackManagerTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RoutingExplainabilityStoreTest.kt`

- [ ] **Step 1: Extend `Prefs` for durable routing metadata**

Change the constructor from `private` to `internal` so tests can instantiate a `Prefs` wrapper over a plain `SharedPreferences`, and add routing keys while keeping `logout()` auth-only.

```kotlin
class Prefs internal constructor(private val prefs: SharedPreferences) {
    var activeRulePackCountryVersion: String?
        get() = prefs.getString("active_rule_pack_country_version", null)
        set(v) = prefs.edit().putString("active_rule_pack_country_version", v).apply()

    var activeRulePackCategoryVersion: String?
        get() = prefs.getString("active_rule_pack_category_version", null)
        set(v) = prefs.edit().putString("active_rule_pack_category_version", v).apply()

    var activeRulePackSourceLabel: String?
        get() = prefs.getString("active_rule_pack_source_label", null)
        set(v) = prefs.edit().putString("active_rule_pack_source_label", v).apply()

    var activeRulePackFetchedAtMs: Long
        get() = prefs.getLong("active_rule_pack_fetched_at_ms", 0L)
        set(v) = prefs.edit().putLong("active_rule_pack_fetched_at_ms", v).apply()

    var activeRulePackStale: Boolean
        get() = prefs.getBoolean("active_rule_pack_stale", false)
        set(v) = prefs.edit().putBoolean("active_rule_pack_stale", v).apply()

    var latestExplainabilityRevision: Long
        get() = prefs.getLong("latest_explainability_revision", 0L)
        set(v) = prefs.edit().putLong("latest_explainability_revision", v).apply()

    var latestExplainabilityCompiledAtMs: Long
        get() = prefs.getLong("latest_explainability_compiled_at_ms", 0L)
        set(v) = prefs.edit().putLong("latest_explainability_compiled_at_ms", v).apply()
}
```

Keep `logout()` exactly on auth/session keys:

```kotlin
fun logout() {
    prefs.edit()
        .remove("token").remove("username").remove("email").remove("password")
        .remove("client_id").remove("client_name").remove("clients_json")
        .remove("selected_forward_id")
        .remove("selected_proxy_id")
        .remove("proxy_connection_mode")
        .apply()
}
```

- [ ] **Step 2: Write the failing prefs/Room/manager tests**

Create `PrefsRoutingStateTest.kt`:

```kotlin
package com.leikwan.bridge.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

class PrefsRoutingStateTest {
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        val shared = ApplicationProvider.getApplicationContext<Context>()
            .getSharedPreferences("prefs-routing-state-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
    }

    @Test
    fun `logout preserves routing metadata but clears auth fields`() {
        prefs.token = "token-123"
        prefs.activeRulePackCountryVersion = "country-2026-05-30a"
        prefs.latestExplainabilityRevision = 7L

        prefs.logout()

        assertNull(prefs.token)
        assertEquals("country-2026-05-30a", prefs.activeRulePackCountryVersion)
        assertEquals(7L, prefs.latestExplainabilityRevision)
    }
}
```

Create `AppDatabaseRoutingTablesTest.kt`:

```kotlin
package com.leikwan.bridge.data

import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.routing.RulePackSnapshotEntity
import com.leikwan.bridge.data.routing.RoutingExplainabilityEntity
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

class AppDatabaseRoutingTablesTest {
    @Test
    fun `routing tables are available in version 2 schema`() = runBlocking {
        val db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        try {
            db.rulePackDao().upsert(
                RulePackSnapshotEntity(
                    slot = "active",
                    countryPackVersion = "country-2026-05-30a",
                    categoryPackVersion = "category-2026-05-30a",
                    sourceLabel = "panel://routing/rule-packs",
                    fetchedAtMs = 1717027200000,
                    validatedAtMs = 1717027205000,
                    integrityState = "VERIFIED",
                    stale = false,
                    rollbackAvailable = false,
                    payloadJson = "{}",
                )
            )
            db.routingExplainabilityDao().upsert(
                RoutingExplainabilityEntity(
                    slot = "latest",
                    compiledAtMs = 1717027300000,
                    rulePackCountryVersion = "country-2026-05-30a",
                    rulePackCategoryVersion = "category-2026-05-30a",
                    activeUserRulesJson = "[]",
                    protectedPathsJson = "[]",
                    warningsJson = "[]",
                    payloadJson = "{}",
                )
            )
            assertEquals("active", db.rulePackDao().active()!!.slot)
            assertEquals("latest", db.routingExplainabilityDao().latest()!!.slot)
        } finally {
            db.close()
        }
    }
}
```

Create `RulePackManagerTest.kt` and `RoutingExplainabilityStoreTest.kt`:

```kotlin
package com.leikwan.bridge.data.routing

import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class RulePackManagerTest {
    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs
    private lateinit var manager: RulePackManager

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        val shared = ApplicationProvider.getApplicationContext<android.content.Context>()
            .getSharedPreferences("rule-pack-manager-test", android.content.Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
        manager = RulePackManager(db, prefs) { 1717027300000 }
    }

    @Test
    fun `activate keeps previous snapshot and updates prefs`() = runBlocking {
        val first = RulePackSnapshot(
            countryPackVersion = "country-2026-05-30a",
            categoryPackVersion = "category-2026-05-30a",
            sourceLabel = "panel://routing/rule-packs",
            fetchedAtMs = 1717027200000,
            validatedAtMs = 1717027205000,
            integrityState = RulePackSnapshot.IntegrityState.VERIFIED,
            stale = false,
            rollbackAvailable = false,
            payloadJson = "{}",
        )
        val second = first.copy(
            countryPackVersion = "country-2026-05-30b",
            validatedAtMs = 1717027300000,
        )

        manager.activate(first)
        manager.activate(second)

        assertEquals("country-2026-05-30b", manager.current()!!.countryPackVersion)
        assertEquals("country-2026-05-30a", manager.previous()!!.countryPackVersion)
        assertEquals("country-2026-05-30b", prefs.activeRulePackCountryVersion)
        assertTrue(manager.current()!!.rollbackAvailable)
    }
}
```

```kotlin
package com.leikwan.bridge.data.routing

import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

class RoutingExplainabilityStoreTest {
    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs
    private lateinit var store: RoutingExplainabilityStore

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        val shared = ApplicationProvider.getApplicationContext<android.content.Context>()
            .getSharedPreferences("routing-explainability-store-test", android.content.Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
        store = RoutingExplainabilityStore(db, prefs)
    }

    @Test
    fun `record replaces latest snapshot and mirrors revision into prefs`() = runBlocking {
        val first = RoutingExplainabilitySnapshot(
            revision = 1L,
            compiledAtMs = 1717027400000,
            rulePackCountryVersion = "country-2026-05-30a",
            rulePackCategoryVersion = "category-2026-05-30a",
            activeUserRules = listOf("country:JP=tunnel"),
            protectedPaths = listOf("panel", "heartbeat"),
            warnings = emptyList(),
            payloadJson = "{}",
        )
        val second = first.copy(revision = 2L, warnings = listOf("stale country pack"))

        store.record(first)
        store.record(second)

        assertEquals(2L, store.latest()!!.revision)
        assertEquals(listOf("stale country pack"), store.latest()!!.warnings)
        assertEquals(2L, prefs.latestExplainabilityRevision)
    }
}
```

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.data.PrefsRoutingStateTest --tests com.leikwan.bridge.data.AppDatabaseRoutingTablesTest --tests com.leikwan.bridge.data.routing.RulePackManagerTest --tests com.leikwan.bridge.data.routing.RoutingExplainabilityStoreTest
```

Expected: FAIL because the new prefs keys, Room tables, entities, DAOs, and stores do not exist yet.

- [ ] **Step 3: Add Room routing tables and migration support**

Update `AppDatabase.kt` from version `1` to version `2`, add the DAOs, and register a migration.

```kotlin
@Database(
    entities = [
        ProxyNode::class,
        Subscription::class,
        RulePackSnapshotEntity::class,
        RoutingExplainabilityEntity::class,
    ],
    version = 2,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun proxyNodeDao(): ProxyNodeDao
    abstract fun subscriptionDao(): SubscriptionDao
    abstract fun rulePackDao(): RulePackDao
    abstract fun routingExplainabilityDao(): RoutingExplainabilityDao

    companion object {
        val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `rule_pack_snapshots` (
                        `slot` TEXT NOT NULL,
                        `country_pack_version` TEXT NOT NULL,
                        `category_pack_version` TEXT NOT NULL,
                        `source_label` TEXT NOT NULL,
                        `fetched_at_ms` INTEGER NOT NULL,
                        `validated_at_ms` INTEGER NOT NULL,
                        `integrity_state` TEXT NOT NULL,
                        `stale` INTEGER NOT NULL,
                        `rollback_available` INTEGER NOT NULL,
                        `payload_json` TEXT NOT NULL,
                        PRIMARY KEY(`slot`)
                    )
                    """.trimIndent()
                )
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `routing_explainability` (
                        `slot` TEXT NOT NULL,
                        `revision` INTEGER NOT NULL,
                        `compiled_at_ms` INTEGER NOT NULL,
                        `rule_pack_country_version` TEXT NOT NULL,
                        `rule_pack_category_version` TEXT NOT NULL,
                        `active_user_rules_json` TEXT NOT NULL,
                        `protected_paths_json` TEXT NOT NULL,
                        `warnings_json` TEXT NOT NULL,
                        `payload_json` TEXT NOT NULL,
                        PRIMARY KEY(`slot`)
                    )
                    """.trimIndent()
                )
            }
        }

        fun get(context: Context): AppDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "leikwan_bridge.db"
                ).addMigrations(MIGRATION_1_2)
                    .build()
                    .also { INSTANCE = it }
            }
    }
}
```

- [ ] **Step 4: Implement the routing persistence models and managers**

Create the new routing persistence package under `data/routing/`.

```kotlin
package com.leikwan.bridge.data.routing

data class RulePackSnapshot(
    val countryPackVersion: String,
    val categoryPackVersion: String,
    val sourceLabel: String,
    val fetchedAtMs: Long,
    val validatedAtMs: Long,
    val integrityState: IntegrityState,
    val stale: Boolean,
    val rollbackAvailable: Boolean,
    val payloadJson: String,
) {
    enum class IntegrityState { VERIFIED, STALE, REJECTED }
}
```

```kotlin
package com.leikwan.bridge.data.routing

data class RoutingExplainabilitySnapshot(
    val revision: Long,
    val compiledAtMs: Long,
    val rulePackCountryVersion: String,
    val rulePackCategoryVersion: String,
    val activeUserRules: List<String>,
    val protectedPaths: List<String>,
    val warnings: List<String>,
    val payloadJson: String,
)
```

Implement `RulePackManager` with slot-based activation and a 24-hour freshness window:

```kotlin
class RulePackManager(
    private val db: AppDatabase,
    private val prefs: Prefs,
    private val clock: () -> Long = System::currentTimeMillis,
) {
    suspend fun activate(snapshot: RulePackSnapshot): RulePackSnapshot = db.withTransaction {
        val previous = db.rulePackDao().active()
        db.rulePackDao().deleteBySlot("previous")
        if (previous != null) {
            db.rulePackDao().upsert(previous.copy(slot = "previous", rollbackAvailable = true))
        }
        db.rulePackDao().upsert(snapshot.toEntity(slot = "active"))
        prefs.activeRulePackCountryVersion = snapshot.countryPackVersion
        prefs.activeRulePackCategoryVersion = snapshot.categoryPackVersion
        prefs.activeRulePackSourceLabel = snapshot.sourceLabel
        prefs.activeRulePackFetchedAtMs = snapshot.fetchedAtMs
        prefs.activeRulePackStale = snapshot.stale
        snapshot.copy(rollbackAvailable = previous != null)
    }

    suspend fun current(): RulePackSnapshot? = db.rulePackDao().active()?.toSnapshot(clock)
    suspend fun previous(): RulePackSnapshot? = db.rulePackDao().previous()?.toSnapshot(clock)
}
```

Implement `RoutingExplainabilityStore` as a latest-only upsert store:

```kotlin
class RoutingExplainabilityStore(
    private val db: AppDatabase,
    private val prefs: Prefs,
) {
    suspend fun record(snapshot: RoutingExplainabilitySnapshot) = db.withTransaction {
        db.routingExplainabilityDao().upsert(snapshot.toEntity(slot = "latest"))
        prefs.latestExplainabilityRevision = snapshot.revision
        prefs.latestExplainabilityCompiledAtMs = snapshot.compiledAtMs
    }

    suspend fun latest(): RoutingExplainabilitySnapshot? =
        db.routingExplainabilityDao().latest()?.toSnapshot()
}
```

- [ ] **Step 5: Run the targeted persistence tests, then the full JVM suite**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.data.PrefsRoutingStateTest --tests com.leikwan.bridge.data.AppDatabaseRoutingTablesTest --tests com.leikwan.bridge.data.routing.RulePackManagerTest --tests com.leikwan.bridge.data.routing.RoutingExplainabilityStoreTest
./gradlew :app:testDebugUnitTest
```

Expected: PASS. The rule-pack manager preserves the previous snapshot, prefs keep routing metadata across logout, and the explainability store always replaces the latest row deterministically.

- [ ] **Step 6: Commit the persistence slice**

```bash
git add \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/Prefs.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/build.gradle.kts" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackSnapshot.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackEntities.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackDao.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilitySnapshot.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityDao.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RulePackManager.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/data/routing/RoutingExplainabilityStore.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/PrefsRoutingStateTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/AppDatabaseRoutingTablesTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RulePackManagerTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/data/routing/RoutingExplainabilityStoreTest.kt"

git commit -m "feat: persist rule-pack snapshots and routing explainability"
```

### Task 4: Config compiler and orchestration integration

**Files:**
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxySelectionPolicy.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunSetupPlannerTest.kt`
- Test: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/ProxySelectionPolicyTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/LeikwanVpnServiceTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/CompiledConfigResult.kt`

- [ ] **Step 1: Fix the zero-proxy guard in `ProxySelectionPolicy` with a failing test**

The current implementation does not return early for `selectedProxyId == 0L`. Lock the bug with a test first.

```kotlin
package com.leikwan.bridge.service

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Test

class ProxySelectionPolicyTest {
    @Test
    fun `zero proxy id returns selection error`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 0L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "proxy-1",
                    type = "ss",
                    originalServer = "remote.example.com",
                    originalPort = 443,
                    configJson = "{}",
                    enabled = true,
                    forwardId = null,
                )
            ),
        )

        assertEquals(
            ProxySelectionResult.Error("Select a proxy before starting this service"),
            result,
        )
    }
}
```

Run: `./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.service.ProxySelectionPolicyTest`

Expected: FAIL because the guard currently does not `return`.

- [ ] **Step 2: Write the failing compile-result and orchestrator tests**

Extend `SingboxConfigBuilderTest.kt` so the builder returns a derived compile artifact instead of a bare string, and add a new `TunnelOrchestratorTest.kt` for compile-first orchestration.

```kotlin
@Test
fun `builder returns compiled config result with proxy tag`() {
    val result = builder.build(
        proxyNodes = nodes,
        runtimeContext = runtimeContext,
        routingPolicySnapshot = null,
        connectionMode = ProxyConnectionMode.SERVICES,
    )

    assertEquals("proxy-1", result.finalProxyTag)
    assertTrue(result.configJson.contains("\"route\""))
    assertTrue(result.warnings.isEmpty())
}
```

```kotlin
package com.leikwan.bridge.service

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class TunnelOrchestratorTest {
    @Test
    fun `start writes compiled config before launching vpn`() = runTest {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val orchestrator = TunnelOrchestrator(context)

        runCatching { orchestrator.startForForward(45L) }

        val debugFile = File(context.filesDir, "latest-singbox-config.json")
        assertTrue(debugFile.exists())
    }
}
```

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest --tests com.leikwan.bridge.service.TunnelOrchestratorTest --tests com.leikwan.bridge.service.ProxySelectionPolicyTest
```

Expected: FAIL because `CompiledConfigResult` does not exist yet and because the zero-proxy guard is still broken.

- [ ] **Step 3: Implement `CompiledConfigResult` and thread it through the builder**

Create `CompiledConfigResult.kt`:

```kotlin
package com.leikwan.bridge.routing

data class CompiledConfigResult(
    val configJson: String,
    val finalProxyTag: String,
    val warnings: List<String> = emptyList(),
)
```

Update `SingboxConfigBuilder.build(...)` to return `CompiledConfigResult`:

```kotlin
fun build(
    proxyNodes: List<ProxyNode>,
    runtimeContext: RuntimeContext?,
    routingPolicySnapshot: RoutingPolicySnapshot? = null,
    connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
): CompiledConfigResult {
    val activeProxyNodes = activeProxyNodes(proxyNodes, runtimeContext, connectionMode)
    val finalProxyTag = when {
        activeProxyNodes.isEmpty() -> "proxy"
        activeProxyNodes.size == 1 -> "proxy-${activeProxyNodes.first().id}"
        else -> "proxy"
    }
    val root = JsonObject()
    root.add("log", JsonObject().apply { addProperty("level", "debug") })
    root.add("dns", buildDns(finalProxyTag, connectionMode))
    root.add("inbounds", buildInbounds())
    root.add("outbounds", buildOutbounds(proxyNodes, runtimeContext, connectionMode))
    root.add("route", buildRoute(runtimeContext?.bypassHosts ?: emptySet(), finalProxyTag, routingPolicySnapshot))
    return CompiledConfigResult(
        configJson = gson.toJson(root),
        finalProxyTag = finalProxyTag,
        warnings = emptyList(),
    )
}
```

Update the route helper signature accordingly:

```kotlin
private fun buildRoute(
    runtimeBypassHosts: Set<String>,
    finalProxyTag: String,
    routingPolicySnapshot: RoutingPolicySnapshot?,
): JsonObject
```

- [ ] **Step 4: Integrate compile-first behavior into `TunnelOrchestrator` and keep VPN startup deterministic**

Use the compiled result instead of a bare JSON string, write it once, then pass the exact same payload into `LeikwanVpnService.configJson`.

```kotlin
val compiled = SingboxConfigBuilder
    .fromPrefs(prefs)
    .build(
        proxyNodes = listOf(selection.selectedNode),
        runtimeContext = runtimeContext,
        routingPolicySnapshot = null,
        connectionMode = connectionMode,
    )
writeDebugConfig(compiled.configJson)
LeikwanVpnService.configJson = compiled.configJson
```

Fix `ProxySelectionPolicy.validate(...)` to return immediately on `selectedProxyId == 0L`:

```kotlin
if (selectedProxyId == 0L) {
    return ProxySelectionResult.Error("Select a proxy before starting this service")
}
```

Keep `TunSetupPlanner.plan(...)` unchanged except for any tiny signature adjustments needed by tests. Do not move routing policy into the planner.

- [ ] **Step 5: Add a focused `LeikwanVpnServiceTest` for startup-state error handling**

```kotlin
package com.leikwan.bridge.service

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class LeikwanVpnServiceTest {
    @Test
    fun `startup snapshot reports failure when config is missing`() {
        LeikwanVpnService.configJson = null
        LeikwanVpnService.startupState = VpnStartupState.STARTING
        LeikwanVpnService.lastStartError = null

        val snapshot = LeikwanVpnService.startupSnapshot()

        assertEquals(VpnStartupState.STARTING, snapshot.state)
        assertTrue(snapshot.errorMessage == null)
    }
}
```

If direct companion-object mutation proves awkward, refactor `LeikwanVpnService` just enough to expose a testable state-reset helper rather than weakening the assertions.

- [ ] **Step 6: Run the targeted tests and then the full JVM suite**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.service.ProxySelectionPolicyTest --tests com.leikwan.bridge.singbox.SingboxConfigBuilderTest --tests com.leikwan.bridge.service.TunnelOrchestratorTest --tests com.leikwan.bridge.service.LeikwanVpnServiceTest --tests com.leikwan.bridge.service.TunSetupPlannerTest
./gradlew :app:testDebugUnitTest
```

Expected: PASS. The zero-proxy guard returns a deterministic error, the builder emits a `CompiledConfigResult`, the orchestrator writes the compiled config before VPN launch, and the broader JVM suite still keeps the single/bond baseline green.

- [ ] **Step 7: Commit the integration slice**

```bash
git add \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxySelectionPolicy.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunSetupPlanner.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/routing/CompiledConfigResult.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/ProxySelectionPolicyTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunnelOrchestratorTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/LeikwanVpnServiceTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/service/TunSetupPlannerTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt"

git commit -m "feat: integrate compiled routing config into vpn startup"
```

### Task 5: UI exposure, diagnostics, and final verification

**Files:**
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/shared/RoutingDiagnosticsUiModels.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsViewModel.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- Modify: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/ui/settings/SettingsViewModelTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModelTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/settings/SettingsScreenTest.kt`
- Create: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/tunnels/TunnelsScreenTest.kt`

- [ ] **Step 1: Write failing tests for the shared routing diagnostics model and screen content**

Add tests that reference the new shared model, the new `routingDiagnostics` state field, and the split content composables before they exist.

```kotlin
@Test
fun settings_view_model_exposes_routing_diagnostics() = runTest {
    val app = ApplicationProvider.getApplicationContext<Application>()
    val viewModel = SettingsViewModel(app)

    assertEquals("TUNNEL", viewModel.state.value.routingDiagnostics?.defaultActionLabel)
    assertTrue(viewModel.state.value.routingDiagnostics?.warnings?.contains("system-protected traffic is enforced") == true)
}
```

```kotlin
@get:Rule
val composeRule = createComposeRule()

@Test
fun settings_screen_content_renders_routing_diagnostics() {
    composeRule.setContent {
        SettingsScreenContent(
            state = SettingsUiState(
                routingDiagnostics = RoutingDiagnosticsUiModel(
                    defaultActionLabel = "TUNNEL",
                    decisionReasonLabel = "DEFAULT_TUNNEL",
                    protectedTrafficLabel = "Panel, bootstrap, and rule-pack endpoints",
                    activeRulePackLabel = "country@2026-05-30 / category@2026-05-30",
                    warnings = listOf("stale country pack"),
                ),
            ),
            onLogout = {},
            onSwitchClient = {},
            onUpdateDnsServer = {},
            onUpdateDnsFallback = {},
            onUpdateDnsStrategy = {},
            onUpdateBypassLan = {},
            onUpdateBypassChina = {},
            onUpdateCustomBypass = {},
        )
    }

    composeRule.onNodeWithText("Routing diagnostics").assertExists()
    composeRule.onNodeWithText("stale country pack").assertExists()
}
```

```kotlin
@Test
fun tunnels_screen_content_renders_routing_diagnostics() {
    composeRule.setContent {
        TunnelsScreenContent(
            state = TunnelsUiState(
                routingDiagnostics = RoutingDiagnosticsUiModel(
                    defaultActionLabel = "DIRECT",
                    decisionReasonLabel = "USER_RULE",
                    protectedTrafficLabel = "Panel, bootstrap, and rule-pack endpoints",
                    activeRulePackLabel = "country@2026-05-30 / category@2026-05-30",
                    warnings = listOf("system-protected traffic is enforced"),
                ),
            ),
            onRefreshForwards = {},
            onToggleVpn = {},
            onSelectForward = {},
        )
    }

    composeRule.onNodeWithText("Routing diagnostics").assertExists()
    composeRule.onNodeWithText("system-protected traffic is enforced").assertExists()
}
```

- [ ] **Step 2: Run the targeted tests and confirm they fail for the right reasons**

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.ui.settings.SettingsViewModelTest --tests com.leikwan.bridge.ui.tunnels.TunnelsViewModelTest
```

Expected: FAIL because `RoutingDiagnosticsUiModel`, `routingDiagnostics`, `SettingsScreenContent`, and `TunnelsScreenContent` do not exist yet.

- [ ] **Step 3: Implement the shared routing diagnostics model and wire both screens to it**

Create `RoutingDiagnosticsUiModels.kt`:

```kotlin
package com.leikwan.bridge.ui.shared

data class RoutingDiagnosticsUiModel(
    val defaultActionLabel: String,
    val decisionReasonLabel: String,
    val protectedTrafficLabel: String,
    val activeRulePackLabel: String,
    val warnings: List<String> = emptyList(),
)
```

Update `SettingsUiState` and `TunnelsUiState`:

```kotlin
data class SettingsUiState(
    val dnsServer: String = "1.1.1.1",
    val dnsFallback: String = "8.8.8.8",
    val dnsStrategy: String = "prefer_ipv4",
    val bypassLan: Boolean = true,
    val bypassChina: Boolean = false,
    val customBypassRules: String = "",
    val username: String = "",
    val clientId: Long = 0L,
    val clientName: String = "",
    val clients: List<ClientInfo> = emptyList(),
    val isSwitchingClient: Boolean = false,
    val switchError: String? = null,
    val routingDiagnostics: RoutingDiagnosticsUiModel? = null,
)
```

```kotlin
data class TunnelsUiState(
    val vpnStatus: VpnStatus = VpnStatus.DISCONNECTED,
    val forwards: List<ForwardListItem> = emptyList(),
    val selectedForwardId: Long = 0L,
    val hiddenUnsupportedMptcpCount: Int = 0,
    val connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
    val isLoading: Boolean = false,
    val error: String? = null,
    val routingDiagnostics: RoutingDiagnosticsUiModel? = null,
)
```

Split the screens into route wrappers plus testable content composables:

```kotlin
@Composable
fun SettingsScreen(
    onLogout: () -> Unit = {},
    viewModel: SettingsViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    SettingsScreenContent(
        state = state,
        onLogout = onLogout,
        onSwitchClient = viewModel::switchClient,
        onUpdateDnsServer = viewModel::updateDnsServer,
        onUpdateDnsFallback = viewModel::updateDnsFallback,
        onUpdateDnsStrategy = viewModel::updateDnsStrategy,
        onUpdateBypassLan = viewModel::updateBypassLan,
        onUpdateBypassChina = viewModel::updateBypassChina,
        onUpdateCustomBypass = viewModel::updateCustomBypass,
    )
}

@Composable
internal fun SettingsScreenContent(
    state: SettingsUiState,
    onLogout: () -> Unit,
    onSwitchClient: (Long) -> Unit,
    onUpdateDnsServer: (String) -> Unit,
    onUpdateDnsFallback: (String) -> Unit,
    onUpdateDnsStrategy: (String) -> Unit,
    onUpdateBypassLan: (Boolean) -> Unit,
    onUpdateBypassChina: (Boolean) -> Unit,
    onUpdateCustomBypass: (String) -> Unit,
)
```

```kotlin
@Composable
fun TunnelsScreen(viewModel: TunnelsViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    TunnelsScreenContent(
        state = state,
        onRefreshForwards = viewModel::refreshForwards,
        onToggleVpn = viewModel::toggleVpn,
        onSelectForward = viewModel::selectForward,
    )
}

@Composable
internal fun TunnelsScreenContent(
    state: TunnelsUiState,
    onRefreshForwards: () -> Unit,
    onToggleVpn: () -> Unit,
    onSelectForward: (Long) -> Unit,
)
```

Render the same read-only diagnostics card in both content composables, with labels in this order: `Default action`, `Decision reason`, `Protected traffic`, `Active rule packs`, and `Warnings`.

- [ ] **Step 4: Wire both viewmodels to the explainability source and run the verification pass**

Add an injectable diagnostics source to both viewmodels and keep it read-only.

```kotlin
class SettingsViewModel(
    app: Application,
    private val diagnosticsSource: RoutingDiagnosticsSource = RoutingDiagnosticsSource.from(app),
) : AndroidViewModel(app) {
    // populate routingDiagnostics from diagnosticsSource.latest()
}
```

```kotlin
class TunnelsViewModel(
    app: Application,
    private val diagnosticsSource: RoutingDiagnosticsSource = RoutingDiagnosticsSource.from(app),
) : AndroidViewModel(app) {
    // populate routingDiagnostics during init and refresh
}
```

Run:

```bash
./gradlew :app:testDebugUnitTest --tests com.leikwan.bridge.ui.settings.SettingsViewModelTest --tests com.leikwan.bridge.ui.tunnels.TunnelsViewModelTest
./gradlew :app:connectedDebugAndroidTest
./gradlew :app:assembleDebug :app:lintDebug
```

Expected: PASS. The unit tests prove the state objects expose diagnostics, the instrumented UI tests render the same card on both screens, and the app still assembles cleanly.

Manual device smoke checks:
- Open `Settings` and confirm the diagnostics card shows the same default action and warnings as `Tunnels`.
- Toggle VPN from `Tunnels` and confirm the diagnostics card remains visible while status changes.
- Switch client, refresh forwards, and confirm diagnostics remain read-only.
- Verify no unsupported `mptcp` or `race` labels appear anywhere in the UI.

- [ ] **Step 5: Commit the UI/diagnostics slice**

```bash
git add \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/shared/RoutingDiagnosticsUiModels.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsViewModel.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/ui/settings/SettingsViewModelTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/test/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModelTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/settings/SettingsScreenTest.kt" \
  "C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/androidTest/java/com/leikwan/bridge/ui/tunnels/TunnelsScreenTest.kt"

git commit -m "feat: expose routing diagnostics in settings and tunnels"
```

## Self-review notes

- Spec coverage is complete across all approved phases: contract/baseline cleanup, safe routing core, rule-pack/explainability, config/orchestration integration, and UI/diagnostics exposure.
- The plan keeps the user-traffic outcome model limited to `TUNNEL` and `DIRECT` and does not introduce imported-node routing or arbitrary outbound graphs.
- The tasks intentionally stop short of app-level split tunneling and Android `mptcp` / `race`, matching the approved v1 scope.
- The plan uses concrete file paths, tests, commands, and code skeletons with real names from the current repo. The only soft dependency left for implementation is whether device-identity request headers are folded into Task 1 or handled immediately afterward; if you want that included in Task 1, add the exact worktree-derived file names before execution.
- One repo bug is already captured directly in the plan: `ProxySelectionPolicy.validate(...)` currently does not return early when `selectedProxyId == 0L`. Task 4 locks and fixes that behavior.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-30-leikwan-android-routing-workflow-plan.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?