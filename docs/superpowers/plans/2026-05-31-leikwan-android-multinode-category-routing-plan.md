# Multi-Node Category Routing ("应用场景分流") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user route traffic categories (Video, AI, Country, custom) to different proxy nodes simultaneously over the bonded transport — adding node-addressable routing, per-node bonded relay legs, a WireGuard node type, and a per-rule node-picker UI — with slave/LAN IPs auto-bypassed and hidden.

**Architecture:** Each `RoutingRule` gains an optional `targetNodeId`. `RoutingPolicyEngine` compiles rules to node-addressable sentinels; `SingboxConfigBuilder` emits one addressable `proxy-{id}` outbound (or WireGuard endpoint) per node and routes each rule's geosite/geoip `rule_set` to its node's tag, with slave/LAN direct-bypass rules emitted first (non-overridable). The runtime runs one bonded relay leg per relay-able node. See the design doc: `docs/superpowers/specs/2026-05-31-leikwan-android-multinode-category-routing-design.md`.

**Tech Stack:** Kotlin, Gson, JUnit4 (`SingboxConfigBuilderTest`, `RoutingPolicyEngineTest`, `RoutingViewModelTest`), Room (DB v2→v3 migration), Jetpack Compose, `io.nekohasekai.libbox`, Gradle (`gradlew.bat`), `adb`.

---

## How to work this plan

- **Branch:** start from current `master` (clean). Create `feature/android-multinode-routing` (do not work directly on master). Commit per task; never `git add -A` — stage only the files each task names.
- **Build/test commands** (run from `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android`):
  - Unit: `.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.<Class>"`
  - Full unit: `.\gradlew.bat :app:testDebugUnitTest`
  - Build: `.\gradlew.bat :app:assembleDebug`
- **Deploy** (from repo root): `.\platform-tools\adb.exe install -r leikwan-android\app\build\outputs\apk\debug\app-debug.apk`. **Never `:app:connectedDebugAndroidTest`** (uninstalls app, wipes VPN consent).
- **On-device reconnect (Direct mode):** open app → Proxies → Direct → tap the proxy connect toggle. Confirm the new config via `adb shell run-as com.leikwan.bridge cat files/latest-singbox-config.json`.
- **TCP canary after every device deploy:** `adb shell "curl -4 -s -o /dev/null -w 'HTTP=%{http_code}\n' --max-time 12 https://1.1.1.1/"` must return non-`000` (don't regress the gvisor fix).
- **Phases are gates:** finish and verify a phase before the next. Phases 0–2 are pure code/unit-tested; Phase 3 needs device QA; Phase 4 is blocked on a libbox version pin; Phase 5 ships the UI.

---

## File Structure

- `routing/RoutingPolicyModels.kt` — add `targetNodeId` to `RoutingRule`/`CompiledRouteRule` (Phase 1)
- `routing/RoutingPolicySnapshot.kt` — add `globalDefaultNodeId` (Phase 1)
- `routing/RoutingPolicyEngine.kt` — node-aware compile + sentinels (Phase 1)
- `singbox/SingboxConfigBuilder.kt` — per-node outbound emission, node-sentinel resolution, safety-bypass invariant, WG endpoint emission (Phases 2, 4)
- `proxy/ProxyUriParser.kt`, `ui/proxies/ProxyProtocolDraft.kt`, `ui/proxies/ProxyListSections.kt` — WireGuard node type (Phase 4)
- `runtime/RuntimeContext.kt`, `service/TunnelOrchestrator.kt`, `service/SingleForwardRunner.kt`, `service/BondForwardRunner.kt`, `service/VpnStartupMonitor.kt` — per-node relay legs (Phase 3)
- `ui/routing/RoutingScreen.kt`, `ui/routing/RoutingViewModel.kt` — crash fix (Phase 0), node-picker (Phase 5)
- `data/routing/UserRoutingRuleEntities.kt`, `UserRoutingRuleDao.kt`, `UserRoutingRuleStore.kt`, `data/AppDatabase.kt` — `target_node_id` column + migration (Phase 5)
- Tests: `RoutingPolicyEngineTest.kt`, `SingboxConfigBuilderTest.kt`, `RoutingViewModelTest.kt`, a new migration test.

---

# Phase 0 — Fix the Routing-tab crash (prerequisite)

**Why:** Opening Routing throws `RuntimeException: Cannot create an instance of class RoutingViewModel` (bare `viewModel()` can't build the injectable VM). The whole feature lives on this screen.

**Files:** Modify `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/routing/RoutingScreen.kt`

- [ ] **Step 1: Reproduce (red, on-device)**

Build/install current `master`, then:
```
.\platform-tools\adb.exe shell am start -n com.leikwan.bridge/.ui.MainActivity
.\platform-tools\adb.exe shell input tap 678 2292   # Routing nav (OnePlus LE2123 coords)
.\platform-tools\adb.exe shell dumpsys window | findstr mCurrentFocus
```
Expected: focus shows `Application Error: com.leikwan.bridge` (crash dialog).

- [ ] **Step 2: Add the factory at the call site**

In `RoutingScreen.kt`, add imports near the top:
```kotlin
import android.app.Application
import androidx.lifecycle.ViewModelProvider.AndroidViewModelFactory.Companion.APPLICATION_KEY
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
```
Change the function signature (currently line 47 `fun RoutingScreen(viewModel: RoutingViewModel = viewModel()) {`):
```kotlin
fun RoutingScreen(
    viewModel: RoutingViewModel = viewModel(
        factory = viewModelFactory {
            initializer { RoutingViewModel(this[APPLICATION_KEY] as Application) }
        }
    )
) {
```

- [ ] **Step 3: Build**

`.\gradlew.bat :app:assembleDebug` → `BUILD SUCCESSFUL`. (No JVM unit test applies — this is Compose VM wiring; `RoutingViewModelTest` already covers the VM and must still pass: `.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.ui.routing.RoutingViewModelTest"`.)

- [ ] **Step 4: Verify on-device (green)**

Install, launch, tap Routing (as Step 1). Expected: the Routing screen renders (global default + Video/AI rule rows), `mCurrentFocus` stays `com.leikwan.bridge/.ui.MainActivity`, no `Application Error`.

- [ ] **Step 5: Commit**
```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/routing/RoutingScreen.kt
git commit -m "fix(android): provide viewModel factory for RoutingScreen (crash on Routing tab)"
```

---

# Phase 1 — Node-addressable routing model (pure JVM)

**Why:** A rule must be able to name a target node. Add `targetNodeId` and node-aware compile, preserving today's behavior when it is null.

**Files:** Modify `routing/RoutingPolicyModels.kt`, `routing/RoutingPolicySnapshot.kt`, `routing/RoutingPolicyEngine.kt`; Test `routing/RoutingPolicyEngineTest.kt`

- [ ] **Step 1: Write failing tests**

Add to `leikwan-android/app/src/test/java/com/leikwan/bridge/routing/RoutingPolicyEngineTest.kt` (engine helper: `RoutingPolicyEngine(SafePathRegistry.androidBaseline("https://panel.example.com","v3-mirror.leikwanhost.com"), RoutingCategoryCatalog.default())`):
```kotlin
@Test
fun `tunnel category rule with target node compiles to node sentinel`() {
    val engine = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )
    val snapshot = engine.compile(
        globalDefault = UserTrafficOutcome.TUNNEL,
        globalDefaultNodeId = 7L,
        countryRules = emptyList(),
        categoryRules = listOf(
            RoutingRule("video", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL, targetNodeId = 3L),
        ),
        rulePackVersions = emptyList(),
        compiledAtMs = 0L,
    )
    val compiled = engine.compileRouteRules(snapshot)
    assertEquals("<NODE:3>", compiled.single().outbound)
}

@Test
fun `tunnel rule without target node uses default proxy sentinel`() {
    val engine = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )
    val snapshot = engine.compile(
        UserTrafficOutcome.TUNNEL, null, emptyList(),
        listOf(RoutingRule("ai", RoutingRuleKind.WEBSITE_CATEGORY, "ai", UserTrafficOutcome.TUNNEL, targetNodeId = null)),
        emptyList(), 0L,
    )
    assertEquals("<PROXY>", engine.compileRouteRules(snapshot).single().outbound)
}

@Test
fun `direct rule ignores target node`() {
    val engine = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )
    val snapshot = engine.compile(
        UserTrafficOutcome.TUNNEL, 7L, emptyList(),
        listOf(RoutingRule("ai", RoutingRuleKind.WEBSITE_CATEGORY, "ai", UserTrafficOutcome.DIRECT, targetNodeId = 9L)),
        emptyList(), 0L,
    )
    assertEquals("direct", engine.compileRouteRules(snapshot).single().outbound)
}
```

- [ ] **Step 2: Run tests — verify they fail**

`.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.routing.RoutingPolicyEngineTest"`
Expected: compile error (`targetNodeId`/`globalDefaultNodeId` unknown) → the tests can't build yet. That is the red state.

- [ ] **Step 3: Add `targetNodeId` to the model**

In `routing/RoutingPolicyModels.kt`, change `RoutingRule` (currently lines 31-37):
```kotlin
data class RoutingRule(
    val id: String,
    val kind: RoutingRuleKind,
    val matchValue: String,
    val outcome: UserTrafficOutcome,
    val enabled: Boolean = true,
    /** When [outcome] is TUNNEL: null = use the global-default node; non-null = pin to this node id. */
    val targetNodeId: Long? = null,
)
```
And change `CompiledRouteRule` (currently lines 71-75) — no field add needed (the node id is encoded in `outbound`), but update its doc:
```kotlin
data class CompiledRouteRule(
    val ruleSetTags: List<String>,
    /** "direct" | "<PROXY>" (default node) | "<NODE:{id}>" (pinned node). Resolved in SingboxConfigBuilder. */
    val outbound: String,
    val sourceRuleId: String?,
)
```

- [ ] **Step 4: Add `globalDefaultNodeId` to the snapshot**

In `routing/RoutingPolicySnapshot.kt`, add the field (after `globalDefaultOutcome`, line 14):
```kotlin
    val globalDefaultOutcome: UserTrafficOutcome,
    /** When [globalDefaultOutcome] is TUNNEL: null = the default proxy/selector; non-null = pin route.final to this node. */
    val globalDefaultNodeId: Long? = null,
```

- [ ] **Step 5: Make the engine node-aware**

In `routing/RoutingPolicyEngine.kt`:
- Add the node sentinel helper + keep the existing default sentinel. In the `companion object` (after line 23):
```kotlin
        /** Sentinel for a TUNNEL rule pinned to a specific node; SingboxConfigBuilder swaps it for "proxy-{id}". */
        fun nodeSentinel(nodeId: Long): String = "<NODE:$nodeId>"
```
- Add `globalDefaultNodeId` param to `compile(...)` (after `globalDefault`, line 38) and pass it into the returned `RoutingPolicySnapshot(...)`:
```kotlin
    fun compile(
        globalDefault: UserTrafficOutcome,
        globalDefaultNodeId: Long?,
        countryRules: List<RoutingRule>,
        categoryRules: List<RoutingRule>,
        rulePackVersions: List<String>,
        compiledAtMs: Long,
    ): RoutingPolicySnapshot {
```
and in the `return RoutingPolicySnapshot(` block add `globalDefaultNodeId = globalDefaultNodeId,` after `globalDefaultOutcome = globalDefault,`.
- Replace `outboundFor` (lines 177-180) with a rule-aware version and update both call sites in `compileRouteRules` (lines 142, 151) to pass the rule:
```kotlin
    private fun outboundFor(rule: RoutingRule): String = when (rule.outcome) {
        UserTrafficOutcome.DIRECT -> "direct"
        UserTrafficOutcome.TUNNEL ->
            rule.targetNodeId?.let { nodeSentinel(it) } ?: PROXY_OUTBOUND_SENTINEL
    }
```
In `compileRouteRules`, change `outbound = outboundFor(rule.outcome)` → `outbound = outboundFor(rule)` at both occurrences.

- [ ] **Step 6: Run tests — verify green**

`.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.routing.RoutingPolicyEngineTest"` → PASS. Then run the full suite to catch the `compile(...)` callers (RoutingViewModel/orchestrator) — they will fail to compile until updated:
`.\gradlew.bat :app:testDebugUnitTest`
Expected: compile errors at every `engine.compile(...)` call. Fix each by inserting `globalDefaultNodeId = <existing-default-or-null>` (for now pass `null` — Phase 5 wires the real value). Re-run until PASS.

- [ ] **Step 7: Commit**
```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyModels.kt leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicySnapshot.kt leikwan-android/app/src/main/java/com/leikwan/bridge/routing/RoutingPolicyEngine.kt leikwan-android/app/src/test/java/com/leikwan/bridge/routing/RoutingPolicyEngineTest.kt
git commit -m "feat(android): add per-rule targetNodeId + node-aware routing compile"
```

---

# Phase 2 — Per-node outbound emission + node-sentinel resolution + safety-bypass invariant

**Why:** Make the config builder emit each node as an addressable `proxy-{id}` and route each rule to its node tag; resolve `<NODE:id>`; and lock the slave/LAN auto-bypass as a tested invariant. Validate end-to-end in DIRECT mode (no relay rework yet).

**Files:** Modify `singbox/SingboxConfigBuilder.kt`; Test `singbox/SingboxConfigBuilderTest.kt`

- [ ] **Step 1: Write failing tests**

Add to `SingboxConfigBuilderTest.kt` (helpers `baseSettings()`, `ssNode(id)`, `engine()` already exist). These build a DIRECT-mode config with two nodes and a category rule pinned to node 2:
```kotlin
@Test
fun `category rule pinned to a node routes to that node tag`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())
    val engine = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )
    val snapshot = engine.compile(
        globalDefault = UserTrafficOutcome.TUNNEL,
        globalDefaultNodeId = 1L,
        countryRules = emptyList(),
        categoryRules = listOf(
            RoutingRule("video", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL, targetNodeId = 2L),
        ),
        rulePackVersions = emptyList(),
        compiledAtMs = 0L,
    )
    val config = JsonParser.parseString(
        builder.build(
            proxyNodes = listOf(ssNode(1), ssNode(2)),
            runtimeContext = null,
            routingPolicySnapshot = snapshot,
            connectionMode = ProxyConnectionMode.DIRECT,
        ).configJson
    ).asJsonObject

    val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject.get("tag").asString }
    assertTrue(outbounds.contains("proxy-1"))
    assertTrue(outbounds.contains("proxy-2"))

    val routeRules = config.getAsJsonObject("route").getAsJsonArray("rules")
    val videoRule = routeRules.first { r ->
        r.asJsonObject.has("rule_set") &&
            r.asJsonObject.getAsJsonArray("rule_set").any { it.asString == "geosite-youtube" }
    }.asJsonObject
    assertEquals("proxy-2", videoRule.get("outbound").asString)
}

@Test
fun `slave and lan ips are direct and precede any node-targeted user rule`() {
    val builder = SingboxConfigBuilder(settings = baseSettings())   // baseSettings has bypassLan = true
    val engine = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )
    val snapshot = engine.compile(
        UserTrafficOutcome.TUNNEL, 2L, emptyList(),
        listOf(RoutingRule("video", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL, targetNodeId = 2L)),
        emptyList(), 0L,
    )
    val runtime = RuntimeContext(
        forwardId = 1L, mode = ForwardMode.BOND, localListenPort = 12000,
        localLegPorts = emptyList(), processPlans = emptyList(),
        bypassHosts = linkedSetOf("38.207.137.131"),   // a slave/sidecar ip
    )
    val config = JsonParser.parseString(
        builder.build(listOf(ssNode(2)), runtime, snapshot, ProxyConnectionMode.DIRECT).configJson
    ).asJsonObject
    val rules = config.getAsJsonObject("route").getAsJsonArray("rules")

    fun indexOfFirst(pred: (com.google.gson.JsonObject) -> Boolean) =
        rules.indexOfFirst { pred(it.asJsonObject) }

    val slaveIdx = indexOfFirst { it.has("ip_cidr") && it.getAsJsonArray("ip_cidr").any { c -> c.asString.startsWith("38.207.137.131") } && it.get("outbound")?.asString == "direct" }
    val lanIdx = indexOfFirst { it.has("ip_cidr") && it.getAsJsonArray("ip_cidr").any { c -> c.asString == "192.168.0.0/16" } && it.get("outbound")?.asString == "direct" }
    val nodeRuleIdx = indexOfFirst { it.get("outbound")?.asString == "proxy-2" }

    assertTrue("slave bypass present", slaveIdx >= 0)
    assertTrue("lan bypass present", lanIdx >= 0)
    assertTrue("node rule present", nodeRuleIdx >= 0)
    assertTrue("slave bypass precedes node rule", slaveIdx < nodeRuleIdx)
    assertTrue("lan bypass precedes node rule", lanIdx < nodeRuleIdx)
}
```

- [ ] **Step 2: Run tests — verify they fail**

`.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"`
Expected: the pinned-node test fails — `videoRule.outbound` is currently the collapsed `finalProxyTag` (`proxy-1` or `proxy`), not `proxy-2`. (The slave/LAN ordering test should already pass given the existing precedence; if it fails, fix ordering in Step 3.)

- [ ] **Step 3: Emit all referenced nodes + resolve the node sentinel**

In `singbox/SingboxConfigBuilder.kt`:
- **Ensure every node referenced by a pinned rule is emitted as an outbound.** In `build()` (lines 57-79), the `proxyNodes` passed in must include all nodes any rule targets. Add a guard helper and union: collect `snapshot` target node ids and ensure `activeProxyNodes` includes them (in DIRECT mode `activeProxyNodes` already returns all enabled nodes — confirm the UI enables every targeted node; Phase 5 enforces this). No code change if all targeted nodes are enabled; otherwise add them to the active set. (Document this as an invariant: a pinned node must be enabled.)
- **Resolve `<NODE:id>` in `buildRoute`.** At the sentinel swap (lines 358-368), extend the mapping:
```kotlin
            val outbound = when {
                compiled.outbound == RoutingPolicyEngine.PROXY_OUTBOUND_SENTINEL -> finalProxyTag
                compiled.outbound.startsWith("<NODE:") ->
                    "proxy-" + compiled.outbound.removePrefix("<NODE:").removeSuffix(">")
                else -> compiled.outbound
            }
```
- **Resolve the global-default node for `route.final`.** Where `route.final` is set (line ~427), if `snapshot?.globalDefaultNodeId != null` use `"proxy-${snapshot.globalDefaultNodeId}"`, else keep the existing `finalProxyTag`/`"direct"` logic.
- **Stop forcing the pick-one selector when rules pin nodes:** keep `buildOutbounds` emitting `proxy-{id}` per node (lines 161-166, unchanged); the `>1` selector (lines 168-174) stays only as the `finalProxyTag` fallback for unpinned `<PROXY>`/`route.final`. No collapse of the individual tags is needed — they are now referenced directly.

(The slave/LAN bypass already lives in `buildRoute` steps 1, 2, 4 — emitted before user rules. If Step 1's ordering test failed, ensure the protected-paths/runtime-bypass/`bypassLan` blocks remain ordered before the compiled user rules; do not reorder user rules above them.)

- [ ] **Step 4: Run tests — verify green**

`.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"` → PASS (incl. the existing tests).

- [ ] **Step 5: Build + on-device validate (DIRECT mode, two nodes)**

Build/install. In the app, enable two proxies, set Video→node B in Routing (Phase 5 UI not built yet — for this phase, drive it via a temporary seeded rule or `RoutingViewModelTest`-style seeding, OR validate purely by inspecting the generated config). Reconnect, then:
```
.\platform-tools\adb.exe shell run-as com.leikwan.bridge cat files/latest-singbox-config.json
```
Expected: `outbounds` contains `proxy-{A}` and `proxy-{B}`; the `geosite-youtube` route rule's `outbound` is `proxy-{B}`; slave/LAN `ip_cidr → direct` rules appear before it. TCP canary still passes.

- [ ] **Step 6: Commit**
```
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt
git commit -m "feat(android): emit per-node outbounds and route pinned rules to specific node tags"
```

---

# Phase 3 — Per-node bonded relay legs (multi-forward runtime) — HIGH RISK

**Why:** You chose bonded multi-node. Today every outbound shares one `127.0.0.1:localListenPort` leg, so per-node tags are not distinct paths. This phase gives each relay-able node its own bonded leg. The orchestrator is single-forward today, so this phase **starts with a design spike** before code.

**Files:** `service/TunnelOrchestrator.kt`, `runtime/RuntimeContext.kt`, `service/SingleForwardRunner.kt`, `service/BondForwardRunner.kt`, `service/VpnStartupMonitor.kt`, `singbox/SingboxConfigBuilder.kt` (`buildProxyOutbound` server/port rewrite)

- [ ] **Step 1: SPIKE — map the current single-forward runtime (read-only, produce notes)**

Read fully and write findings into this plan file (a new `### Phase 3 spike findings` subsection): `service/TunnelOrchestrator.kt` (esp. `startForForward` ~77-160), `runtime/RuntimeContext.kt`, `service/SingleForwardRunner.kt`, `service/BondForwardRunner.kt`, `service/ProcessRunner` (if present), `service/VpnStartupMonitor.kt`, and how `RuntimeContext.localListenPort`/`localLegPorts`/`processPlans` are produced and consumed. Answer: (a) does each node map 1:1 to a forwardId, or can nodes share a forward? (b) what is the lifecycle (start/health/stop) of one leg? (c) port allocation strategy? (d) can N runners run concurrently in one process, or is there shared global state (single `activeRuntime`)? Lock the per-node-leg data shape from these answers before coding.

- [ ] **Step 2: Write failing test for the multi-leg config rewrite**

Once the spike fixes the shape, add a `SingboxConfigBuilderTest` asserting that in SERVICES mode with a per-node leg map, node `2`'s outbound `server/port` is rewritten to `127.0.0.1:<port_for_node_2>` (distinct from node `1`'s). (Exact API depends on the Step-1 shape, e.g. `RuntimeContext.legs: Map<Long, NodeLeg>`.) Run → fail.

- [ ] **Step 3: Implement per-node leg model**

Per the spike: change `RuntimeContext.localListenPort: Int` into a per-node mapping (e.g. add `legs: Map<Long, NodeLeg>` where `NodeLeg(nodeId, listenPort, forwardId, processPlans)`); keep the old single field during migration if other call sites need it. Update `buildProxyOutbound` (lines 195-202) so each node rewrites to its own `127.0.0.1:legs[node.id].listenPort` (fall back to the old single port if no leg map, preserving single-node behavior). Run the Step-2 test → green.

- [ ] **Step 4: Implement concurrent leg lifecycle in `TunnelOrchestrator`**

Per the spike: start one runner per active relay-able node (each with its own listen-port + bond/wstunnel transport), health-check each via `VpnStartupMonitor`, and tear all down on stop. A leg that fails to come up disables only its node (its rules fall back to the global default with a warning via the existing `warnings` pipeline) and never aborts the whole tunnel. Add unit tests for the lifecycle where the runtime is mockable; otherwise cover via the device QA in Step 6.

- [ ] **Step 5: Build + full unit suite**

`.\gradlew.bat :app:testDebugUnitTest` → PASS. `.\gradlew.bat :app:assembleDebug` → SUCCESS.

- [ ] **Step 6: On-device QA — distinct egress per node**

Enable two nodes whose exit IPs differ (e.g. two different SS servers), pin Video→node A and AI→node B, reconnect, then from a captured UID confirm each category egresses via the right node:
```
.\platform-tools\adb.exe shell "curl -4 -s --max-time 12 https://api.ipify.org"     # default node IP
# fetch a youtube-categorised host and an openai-categorised host; confirm via sing-box logs the outbound used
.\platform-tools\adb.exe logcat -d | findstr "outbound/"   # expect proxy-A for youtube flows, proxy-B for openai flows
```
Confirm slave/LAN traffic stays direct and the TCP canary passes. A leg failure must degrade gracefully (kill node B's leg; node B's category falls back to default with a warning; node A keeps working).

- [ ] **Step 7: Commit** (stage only the runtime/builder files + tests)
```
git commit -m "feat(android): per-node bonded relay legs (multi-forward runtime)"
```

---

# Phase 4 — WireGuard node type — blocked on libbox version pin

**Why:** Add WireGuard as a node type so a category can egress via a WG node. WG is a userspace endpoint (direct to the WG server, protected by the VpnService) — not a relay leg.

**Files:** `proxy/ProxyUriParser.kt`, `ui/proxies/ProxyProtocolDraft.kt`, `ui/proxies/ProxyListSections.kt`, `singbox/SingboxConfigBuilder.kt` (`build` endpoints array + `buildProxyOutbound`); Tests: `SingboxConfigBuilderTest`, `ProxyUriParserTest`

- [ ] **Step 1: BLOCKER — pin the bundled libbox/sing-box version**

Determine the sing-box version inside `leikwan-android/app/libs/libbox.aar` (check the AAR/jar manifest, any version string, or the SagerNet release the bundled `.so`/classes correspond to). Decide: **endpoint form** (`endpoints[]`, `"type":"wireguard"`) for sing-box ≥1.11, or the **deprecated `wireguard` outbound** for older. Record the decision here. Do not write WG emission until this is pinned — the wrong form makes libbox reject the whole config at startup.

- [ ] **Step 2: Write failing tests**

`ProxyUriParserTest`: parsing a WireGuard config/URI yields `ProxyNode(type="wireguard", configJson=…)` with `private_key`, peer `public_key`, `endpoint`, `allowed_ips`, optional `pre_shared_key`/`reserved`/`address`. `SingboxConfigBuilderTest`: a `wireguard` node emits the chosen sing-box form (endpoint in `endpoints[]` or a `wireguard` outbound) with a `proxy-{id}`/endpoint tag a route rule can target. Run → fail.

- [ ] **Step 3: Implement parser + draft + type lists**

Add `wg://`/WireGuard parsing in `ProxyUriParser.parse` → `ProxyNode(type="wireguard", …)`; add a `ProxyProtocolDraft.WireGuard` variant (parse/serialize/summaryLines); add `"wireguard" to "WireGuard"` in `ProxyListSections`/type maps. Ensure subscription fetch does not drop `wg` lines.

- [ ] **Step 4: Implement WG emission (version-gated per Step 1)**

In `SingboxConfigBuilder`: add an `endpoints[]` array to `build()` (only when WG nodes exist) and a `"wireguard"` branch (endpoint or legacy outbound) in `buildProxyOutbound`/a new `buildEndpoint`. Add a startup self-check that logs a clear error if the emitted form mismatches the libbox version, rather than letting the config be silently rejected.

- [ ] **Step 5: Run tests → green; build.** `.\gradlew.bat :app:testDebugUnitTest` + `:app:assembleDebug`.

- [ ] **Step 6: On-device QA**

Add a WG node, pin a category to it, reconnect; confirm the config loads (no libbox rejection), the WG endpoint connects, and that category egresses via the WG server IP. TCP canary passes.

- [ ] **Step 7: Commit** `git commit -m "feat(android): add WireGuard node type (parser + endpoint emission)"`

---

# Phase 5 — Per-rule node-picker UI + Room migration

**Why:** Surface the feature: assign each category/country/custom rule to a node, choose a global-default node, and persist it. Slave IPs are never listed (only user nodes).

**Files:** `data/routing/UserRoutingRuleEntities.kt`, `UserRoutingRuleDao.kt`, `UserRoutingRuleStore.kt`, `data/AppDatabase.kt` (migration 2→3), `ui/routing/RoutingViewModel.kt`, `ui/routing/RoutingScreen.kt`; Tests: a Room migration test, `RoutingViewModelTest`

- [ ] **Step 1: SPIKE — read the persistence + UI as-is (read-only)**

Read `UserRoutingRuleEntities.kt`, `UserRoutingRuleDao.kt`, `UserRoutingRuleStore.kt`, `data/AppDatabase.kt` (current `version` + `MIGRATION_1_2`), `ui/routing/RoutingViewModel.kt`, `ui/routing/RoutingScreen.kt`. Confirm the current DB version (design assumes v2 → new migration adds v3) and the rule add/list flow. Record the exact column/DAO names here.

- [ ] **Step 2: Write failing migration test**

Add a Room migration test (in `src/test` with Robolectric, matching the existing migration-test pattern) that migrates a v2 DB with a `user_routing_rules` row to v3 and asserts the new `target_node_id` column exists and is null for the old row. Run → fail.

- [ ] **Step 3: Add the column + migration**

Add `target_node_id INTEGER` (nullable) to `UserRoutingRuleEntity`; bump `AppDatabase.version` to 3; add `MIGRATION_2_3` = `ALTER TABLE user_routing_rules ADD COLUMN target_node_id INTEGER`; register it. Update `UserRoutingRuleDao`/`UserRoutingRuleStore` to read/write `targetNodeId`. Run the migration test → green.

- [ ] **Step 4: Write failing ViewModel test**

`RoutingViewModelTest`: setting a category rule's target node persists `targetNodeId` and surfaces it in `RoutingUiState` (extend the state with the per-rule target + a list of selectable nodes + global-default node). Run → fail.

- [ ] **Step 5: Implement ViewModel state + actions**

Extend `RoutingViewModel`/`RoutingUiState` with the available nodes (id+name from the proxy store, **user nodes only — never slave IPs**), a per-rule `setTargetNode(ruleId, nodeId?)`, and a `setGlobalDefaultNode(nodeId?)`. Wire `compile(...)` to pass `globalDefaultNodeId` and per-rule `targetNodeId`. Run → green.

- [ ] **Step 6: Build the picker UI**

In `RoutingScreen`, add a node dropdown to each rule row (`Direct` / `Auto (default)` / each node name) and a global-default node picker. Dangling/disabled target shows a warning chip and falls back to default. Build + install.

- [ ] **Step 7: On-device QA (full feature)**

Open Routing (no crash — Phase 0), assign Video→node A, AI→node B, set global default→node C, reconnect. Confirm via config + logs each category egresses via its node, slave/LAN stay direct and are absent from the UI, and a removed node's rule falls back with a warning. TCP canary passes.

- [ ] **Step 8: Commit** `git commit -m "feat(android): per-rule node picker UI + target_node_id persistence (migration 2->3)"`

---

## Self-Review

**1. Spec coverage:** Every spec section maps to a phase — §7 crash fix→Phase 0; §5.1 model→Phase 1; §5.2 emission + §5.5 safety bypass→Phase 2; §5.3 relay legs→Phase 3; §5.4 WireGuard→Phase 4; §5.6 UI/migration→Phase 5. The §5.5 slave/LAN privacy+bypass invariant has an explicit test in Phase 2 Step 1 and device re-verification in Phases 3/5.

**2. Placeholder scan:** Phases 0–2 contain complete code and exact commands. Phases 3–5 begin with a read-only SPIKE step because their exact code depends on files not yet read (orchestrator internals, libbox version, persistence/UI internals) — this is deliberate, not a placeholder: each implementation step is gated on the spike that defines its types. No "TBD/handle-edge-cases" left in requirement positions.

**3. Type consistency:** `targetNodeId: Long?` (Phase 1) flows into `outboundFor(rule)` → `<NODE:id>` sentinel → `buildRoute` resolution to `proxy-{id}` (Phase 2) → `target_node_id` column (Phase 5). `globalDefaultNodeId` added to `compile()` + `RoutingPolicySnapshot` (Phase 1) and consumed for `route.final` (Phase 2) and set from the UI (Phase 5). The `compile(...)` signature change is explicitly fixed at all call sites in Phase 1 Step 6.

**4. Ordering:** Phase 0 (crash) unblocks the UI; Phase 1 (model) precedes Phase 2 (emission); Phase 2 validates routing in DIRECT mode before the Phase 3 relay rework; Phase 4 (WG) is independently gated on the libbox pin; Phase 5 (UI) ships last so users can drive what Phases 1–4 enable. Each phase ends green + committed.

---

# APPENDED PHASES — Revision 2026-05-31 (evening)

Phases 0–5 are implemented on `feature/android-multinode-routing` (builds, 134 tests pass). A 5-agent
adversarial verification (verdict `has-correctness-bugs`) and four new user requirements add the
phases below. See design doc §14. Same working rules as above (branch, scoped commits, TCP canary,
`adb install -r`, no `connectedDebugAndroidTest`). Order: **3-FIX → 6 → 7 → 8 → 9**.

## Phase 3-FIX — make bonded multi-node actually work + fail safe (BLOCKER)

The verification found two high-severity Phase 3 bugs (design §14.1). This phase fixes them.

- [ ] **3F.1 SPIKE — distinct per-node egress in bonded (SERVICES) mode (bug B1).** Today every leg
  is built from one forward contract (`TunnelOrchestrator.kt:116-132 getCachedContract(selectedForward)`)
  and all bond to the same `bonding.remoteTarget` (`BondForwardRunner.kt:181-194`), and
  `buildProxyOutbound` discards `node.originalServer` in SERVICES mode (`SingboxConfigBuilder.kt:248`).
  Read `BondForwardRunner`, `ForwardContract`, `PanelRepository.getCachedContract`, and how a
  `ProxyNode.forwardId` maps to a forward/remoteTarget. **Decide**: does each node carry its own
  forward contract / remoteTarget (so leg N dials node N's real upstream), or does the node's
  `originalServer` need to be threaded as the bond's inner target? Lock the mechanism, record it here.
- [ ] **3F.2 Implement distinct egress + a test that asserts it.** Per the spike, build each per-node
  leg from that node's own contract/remoteTarget so two categories reach two **different real
  servers** over the bond. Add a `TunnelOrchestratorTest` (and/or `SingboxConfigBuilderTest`) that
  asserts leg A and leg B resolve to **different** upstream targets — not just different local ports
  (the current test `startNodeLegs starts one isolated runtime per target node` only checks local
  ports and masks B1).
- [ ] **3F.3 Leg-failure isolation (bug B2).** Wrap each `processRunner.start` in `startNodeLegs`
  (`TunnelOrchestrator.kt:200-223`) in try/catch: on failure, drop that node from the leg map,
  re-point its rules to the global default, push a warning into `RoutingPolicySnapshot.warnings`, and
  continue — never abort the tunnel. Add a test where a `RecordingProcessRunner` throws for one node
  and assert the other leg still starts + a warning is recorded.
- [ ] **3F.4 Collision-free port allocation.** Replace `listenPortForNode = base+1+(nodeId%1000)` /
  `portSaltForNode = (nodeId%1000)*20` (`TunnelOrchestrator.kt:234-237`) with a per-startup unique
  allocator (e.g. assign sequential offsets by leg index, or a free-port probe) so ids congruent mod
  1000 can't collide. Add a regression test (nodes id=1 and id=1001 → distinct ports).
- [ ] **3F.5 Wire the silent dangling-target fallback into diagnostics.** When `sanitizeNodeTargets`
  (`TunnelOrchestrator.kt:239-243`) nulls a target, add a `snapshot.warnings` entry (surfaced in
  `RoutingDiagnosticsCard`) per design §5.6/§8 (the UI chip already exists; the runtime channel does not).
- [ ] **3F.6 (polish) Remove the redundant late LAN-bypass block** (`SingboxConfigBuilder.kt:425-435`)
  or move the unconditional LAN/private bypass before user rules, so the safety invariant is
  self-evident at the call site rather than relying solely on the `SafePathRegistry` copy. Keep the
  existing safety test green; add a `bypassLan=false` case proving the protected-path copy carries it.
- [ ] **3F.7** Build + full unit suite green; on-device QA with **two nodes whose exit IPs differ**:
  Video→A, AI→B, confirm each category egresses via the correct **real server** (not just a local
  port), kill node B's leg and confirm node A keeps working + a warning shows. Commit.

## Phase 6 — Selector master switch + Proxies-mode conflict resolution (§14.2; default OFF)

- [ ] **6.1** `Prefs.kt`: add `var routingSelectorEnabled: Boolean` (key `routing_selector_enabled`,
  default `false`); clear in `logout()`. Test the default + round-trip.
- [ ] **6.2** Gate in `TunnelOrchestrator.buildRoutingSnapshot`: when `!routingSelectorEnabled`, pass
  empty country/category rule lists AND `globalDefault = TUNNEL, globalDefaultNodeId = selectedProxyId`
  (if enabled), so `route.final = proxy-{selectedProxyId}` and no user rules emit — Proxies selection
  authoritative. Add a `TunnelOrchestrator`/`SingboxConfigBuilder` test for both on/off states.
- [ ] **6.3** `RoutingUiState.selectorEnabled` + `RoutingViewModel.setSelectorEnabled`; a prominent
  switch at the top of `RoutingScreen` that dims the editor when off. `RoutingViewModelTest` for the toggle.
- [ ] **6.4** `ProxiesScreen` read-only status row ("Routing rules: ON — may override selected proxy",
  tap → Routing); active-rule badge on the Routing nav item (`AppNavigation.kt`).
- [ ] **6.5** SERVICES pinned-node guard: when on + SERVICES + a rule targets a node with no relay
  leg, warn (don't emit a dangling `proxy-{id}`). (Overlaps 3F.) Build + on-device verify the toggle
  flips behavior; commit.

## Phase 7 — Expanded presets + geosite & geoip online updater over the tunnel (§14.3/§14.6)

- [ ] **7.1** Extend `RoutingCategoryCatalog` to carry **labels** (En + Zh) per preset (today
  `Map<String,List<String>>` with no label); seed the **16 verified presets** (google, youtube,
  streaming, ai, telegram, twitter, meta, tiktok, microsoft, apple, developer, gaming, music,
  finance, speedtest, social, china_direct) with their geosite tags + En/Zh labels (from design
  §14.3). `china_direct` defaults DIRECT; others TUNNEL. Handle the `@cn` tag in `geositeUrl()` (don't
  re-encode `@`) or omit those two. Update `RoutingViewModel.presetKeys` → label-bearing list. Tests.
- [ ] **7.2** Define the **GitHub manifest schema** (JSON: `{version, geosite:[{key,labelEn,labelZh,
  outcomeDefault,tags[]}], geoip:{version, refs:[{tag,url}], cnTag}}`) and a parser + validator.
- [ ] **7.3** `RulePackFetcher`: OkHttp GET of the manifest (rides the active tunnel — §14.3 decision;
  DoH fallback only for disconnected manual check) → validate → `RulePackManager.activate()` (store
  exists). Bake the 16-preset + current geoip refs as the **offline seed**. Unit-test parse/validate/
  fallback.
- [ ] **7.4** Bind the active pack into `RoutingCategoryCatalog` (presets from pack, else seed) and
  into the **geoip** path: country rules + the **`bypassChina` block** (`SingboxConfigBuilder.kt`
  ~386-388, 440-443) emit the pack's current `geoip-cn`/`geosite-cn` refs instead of hardcoded URLs —
  so "Update rules" refreshes Settings→Bypass-China too (§14.6). Tests for the shared source.
- [ ] **7.5** Updater UI in `SettingsScreen`: "Update rules" button + last-updated/version +
  integrity/staleness + **rollback** (store keeps `previous`); auto-check on launch / periodic
  `WorkManager` while connected. On-device: connect, Update, confirm new categories appear + Bypass-China
  uses the updated set. Commit.

## Phase 8 — Country-rule ISO-code search/autocomplete (§14.5)

- [ ] **8.1** Add a bundled **country table** (`~250`: alpha-2 code + En name + Zh name) as a static
  resource/Kotlin map. Test lookups (name→code, code→name, both locales).
- [ ] **8.2** Replace the raw "ISO code" field in `RoutingScreen` "Add country rule" with a typeahead
  `TextField` + filtered dropdown (match code/En/Zh, case/diacritic-insensitive); selecting sets
  `matchValue` to the alpha-2 code; chip shows friendly name (e.g. "日本 (JP)"). Raw code still accepted.
  No model change. `RoutingViewModelTest`/UI test for the filter. Commit.

## Phase 9 — Simplified-Chinese localization (§14.4; full + in-app toggle, default zh)

- [ ] **9.1** Externalize EVERY user-facing literal (~150–200 across ~16 UI files + ViewModels + the
  VPN notification) into `res/values/strings.xml` (English base); replace with `stringResource(...)`
  (Compose) / `context.getString(...)` (VMs) / `getString(...)` (notification). Sweep to confirm zero
  remaining literals.
- [ ] **9.2** Add `res/values-zh-rCN/strings.xml` with Simplified-Chinese translations for every key
  (incl. category labels from Phase 7 + country names from Phase 8).
- [ ] **9.3** In-app language toggle: add `androidx.appcompat`, `res/xml/locales_config.xml` (en,
  zh-CN), `android:localeConfig` in the manifest, an `AppCompatDelegate.setApplicationLocales` toggle
  in `SettingsScreen`, and **default new installs to zh-CN** (en available via the toggle).
- [ ] **9.4** Build + on-device: verify the app renders fully in Chinese by default, the toggle
  switches en/zh live, and no screen shows a raw `string/...` key. Commit.

## Appended self-review

- **Coverage:** Phase 3-FIX maps to design §14.1 (B1+B2+polish); Phase 6 → §14.2; Phase 7 → §14.3+§14.6;
  Phase 8 → §14.5; Phase 9 → §14.4. The user's three live clarifications (tunnel-fetch, ISO search,
  geoip+Bypass-China sharing) are in 7.3/8/7.4 respectively.
- **Spike-gating:** only 3F.1 needs a spike (the bonded-egress mechanism depends on unread
  forward↔node mapping internals) — deliberately not fabricated. Everything else is file-specific with
  concrete changes.
- **Ordering:** 3-FIX first (the bonded promise + safety must be real before layering features), then
  the toggle (so users control it), then presets/updater, ISO search, and localization last (it
  depends on the final string set from Phases 6–8).
