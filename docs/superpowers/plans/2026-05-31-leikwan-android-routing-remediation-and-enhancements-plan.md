# Leikwan Android Routing — Phase 3 Remediation + Enhancements (6–9) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Each step is one small action; write the failing test first, run it red, implement, run green, commit.

**Goal:** Fix the two ship-blocking Phase 3 bugs (bonded multi-node doesn't reach distinct real servers; one leg failure kills the whole VPN), then add: a master on/off switch for the traffic selector, an expanded preset catalog with a GitHub online updater (geosite **and** geoip) fetched over the tunnel and shared with Bypass-China, an ISO-code country search, and full Simplified-Chinese localization.

**Architecture:** All work is on top of the implemented multi-node feature (`feature/android-multinode-routing`). Routing is enforced only in sing-box `route.rules`/`endpoints` emitted by `SingboxConfigBuilder`; the runtime (`TunnelOrchestrator` + per-node `NodeLeg`s) decides egress paths; presets/geoip come from a versioned `RulePackManager` store (already built, currently dormant). See design doc §14: `docs/superpowers/specs/2026-05-31-leikwan-android-multinode-category-routing-design.md`.

**Tech Stack:** Kotlin, Gson, JUnit4 + Robolectric, Room (DB migrations), Jetpack Compose + Material3, AndroidX AppCompat (for per-app locale), OkHttp (rule-pack fetch), `io.nekohasekai.libbox`, Gradle (`gradlew.bat`), `adb`.

---

## How to work this plan

- **Branch:** continue on `feature/android-multinode-routing` (worktree `.worktrees/android-multinode-routing`). Commit per task; stage only the files each task names — never `git add -A`.
- **Commands** (run from `.worktrees/android-multinode-routing/leikwan-android`):
  - Unit (one class): `.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.<Class>"`
  - Full unit: `.\gradlew.bat :app:testDebugUnitTest`  ·  Build: `.\gradlew.bat :app:assembleDebug`
- **Deploy** (repo root): `.\platform-tools\adb.exe install -r .worktrees\android-multinode-routing\leikwan-android\app\build\outputs\apk\debug\app-debug.apk`. **Never `:app:connectedDebugAndroidTest`** (wipes VPN consent).
- **TCP canary after every deploy:** `adb shell "curl -4 -s -o /dev/null -w 'HTTP=%{http_code}\n' --max-time 12 https://1.1.1.1/"` must return non-`000`.
- **Order:** 3-FIX → 6 → 7 → 8 → 9. 3-FIX is the blocker; 9 (localization) is last so it captures the final string set.

## File structure (touched by this plan)

- `service/TunnelOrchestrator.kt` — leg-failure isolation, collision-free ports, per-node egress wiring, selector gate, sanitize-warning (Phases 3-FIX, 6)
- `runtime/BondForwardRunner.kt`, `runtime/ForwardContract.kt`, `runtime/SingleForwardRunner.kt` — per-node remoteTarget (Phase 3-FIX, spike)
- `singbox/SingboxConfigBuilder.kt` — SERVICES egress, bypassChina from pack (Phases 3-FIX, 7)
- `data/Prefs.kt` — `routingSelectorEnabled` (Phase 6)
- `ui/routing/RoutingViewModel.kt`, `ui/routing/RoutingScreen.kt` — master switch, ISO search (Phases 6, 8)
- `ui/proxies/ProxiesScreen.kt`, `ui/nav/AppNavigation.kt` — status row, nav badge (Phase 6)
- `routing/RoutingCategoryCatalog.kt` — labelled 16-preset catalog (Phase 7)
- `data/routing/RulePackFetcher.kt` (NEW), `RulePackManager.kt`, `ui/settings/SettingsScreen.kt` — online updater (Phase 7)
- `routing/CountryCatalog.kt` (NEW) — code↔name table (Phase 8)
- `res/values/strings.xml`, `res/values-zh-rCN/strings.xml` (NEW), `res/xml/locales_config.xml` (NEW), `AndroidManifest.xml`, `app/build.gradle.kts`, all `ui/` screens + notification (Phase 9)

---

# Phase 3-FIX — make bonded multi-node real + fail safe (BLOCKER)

Verification (`has-correctness-bugs`) found: **B1** SERVICES legs all bond to the same upstream (`startNodeLegs` builds every leg from one `forward`; `buildProxyOutbound` discards `node.originalServer` in SERVICES mode) → no distinct per-node egress in bonded mode; **B2** `startNodeLegs` has no per-leg try/catch → one failure aborts the whole tunnel; plus a `nodeId % 1000` port collision and a silent dangling-target fallback.

## Task 3F.1 — SPIKE: how a per-node leg reaches that node's real server in bonded mode

- [ ] **Step 1: Investigate (read-only), record findings in this file.** Read `runtime/BondForwardRunner.kt` (esp. `buildPlan` + the `tcp://127.0.0.1:$localPort:$resolvedRemoteTarget` and `wss://${endpoint.host}` lines ~181-194), `runtime/SingleForwardRunner.kt`, `runtime/ForwardContract.kt` (fields incl. `bonding.remoteTarget`, `endpoints`, `withListenPort`), `api/PanelRepository.getCachedContract`/`pullCatalog`, and `data/Entities.kt` `ProxyNode.forwardId/originalServer/originalPort`. Answer and write down:
  1. In SERVICES mode, what is the upstream a bond leg dials — the forward's `remoteTarget`, and is that the *node's* server or a shared sidecar?
  2. Can each `ProxyNode` map to its **own** forward contract (per-node `getCachedContract(node.forwardId)`), or must the node's `originalServer:originalPort` be passed as the bond's inner target?
  3. Decide the mechanism: **(A)** per-node forward contract (each leg = node's own forward → its own `remoteTarget`), or **(B)** keep one bond but make the SS/VLESS outbound dial the node's real server *through* the local relay (i.e. `buildProxyOutbound` keeps `node.originalServer` as the SS `server` while routing the connection via the relay) — likely (A) for true bonded per-node egress.
- [ ] **Step 2: Lock the decision** as a short ADR block appended here (chosen option + why + the exact field that will differ per leg).

## Task 3F.2 — distinct per-node egress (B1)

- [ ] **Step 1: Failing test.** In `SingboxConfigBuilderTest` (or `TunnelOrchestratorTest`), assert two legs resolve to **different upstream targets**, not just different local ports. Example shape (adapt to the 3F.1 decision):

```kotlin
@Test
fun `services mode legs reach distinct upstream targets per node`() {
    // build runtimeContext with two NodeLegs whose forwards have different remoteTargets
    // (node 1 -> forward contract A @ serverA, node 2 -> forward contract B @ serverB)
    // assert the two bond process plans contain serverA and serverB respectively
}
```
Run → fails (today both legs share one `forward`).

- [ ] **Step 2: Implement per 3F.1.** If option (A): in `startForForward` resolve a per-node forward contract (`panelRepo.getCachedContract(node.forwardId)`) and pass it into `startNodeLegs` so each leg's `buildRuntimePlan` uses that node's own `remoteTarget`; change `startNodeLegs` to take `List<Pair<Long, ForwardContract>>` instead of one `forward`. If option (B): in `SingboxConfigBuilder.buildProxyOutbound` keep `outboundServer = node.originalServer` (not `127.0.0.1`) for the SS/VLESS server field while still binding the connection through the leg port. Run the Step-1 test → green.
- [ ] **Step 3: On-device.** Two nodes with **different exit IPs**, Video→A / AI→B, connect (SERVICES/bonded), confirm via `adb logcat | findstr "outbound/"` and an egress-IP check that the two categories leave via the two **different real servers**.

## Task 3F.3 — leg-failure isolation + collision-free ports (B2 + port bug)

- [ ] **Step 1: Failing test.** In `TunnelOrchestratorTest`, make a `RecordingProcessRunner` throw for one node id and assert: the other leg still starts, the failed node is reported, and the tunnel is not aborted.

```kotlin
@Test
fun `a leg that fails to start is dropped and the tunnel continues`() {
    val runner = RecordingProcessRunner(failForKeysContaining = "node-2")
    // orchestrator with that runner; startNodeLegs(forward, listOf(1L, 2L), binaries)
    val result = orchestrator.startNodeLegs(...)
    assertEquals(setOf(1L), result.legs.keys)
    assertEquals(listOf(2L), result.failedNodeIds)
}
```
Run → fails (current `startNodeLegs` throws out).

- [ ] **Step 2: Implement.** Change `startNodeLegs` to per-leg try/catch, return legs + failed ids, and allocate ports by **index** (collision-free), keeping the e3a91fe cleanup-stale semantics (first *successful* leg cleans up):

```kotlin
data class NodeLegsResult(val legs: Map<Long, NodeLeg>, val failedNodeIds: List<Long>)

internal fun startNodeLegs(
    forward: ForwardContract,            // or per-node contracts per 3F.1/3F.2
    nodeIds: List<Long>,
    binaries: RuntimeBinaryPaths,
): NodeLegsResult {
    val legs = linkedMapOf<Long, NodeLeg>()
    val failed = mutableListOf<Long>()
    nodeIds.forEachIndexed { index, nodeId ->
        try {
            val legForward = forward.withListenPort(forward.listenPort + 1 + index)   // index, not nodeId%1000
            val legRuntime = buildRuntimePlan(legForward, binaries, portSalt = (index + 1) * 40)
            val processKey = NodeLeg.processKey(forward.forwardId, nodeId)
            processRunner.start(processKey, legRuntime, cleanupStale = legs.isEmpty())
            legs[nodeId] = NodeLeg(nodeId, forward.forwardId, processKey, legRuntime.mode,
                legRuntime.localListenPort, legRuntime.localLegPorts, legRuntime.processPlans, legRuntime.bypassHosts)
        } catch (e: Exception) {
            Log.w(TAG, "Relay leg for node $nodeId failed; dropping it", e); failed += nodeId
        }
    }
    return NodeLegsResult(legs, failed)
}
```
Update the caller (line 123) to use `result.legs` and feed `result.failedNodeIds` into Step 3F.4. Run → green.

- [ ] **Step 3: Port-collision regression test.** Assert nodes id=1 and id=1001 now get **distinct** ports (they map by index, not `nodeId%1000`). Run → green.

## Task 3F.4 — re-point rules of unavailable nodes to default + warn (no silent drop)

- [ ] **Step 1: Failing test.** `TunnelOrchestratorTest`: a rule pinned to a node whose leg failed (or a disabled/deleted node) compiles to the default outbound AND a warning appears in `snapshot.warnings`.
- [ ] **Step 2: Implement.** Thread the *available* node set (`enabledNodeIds − failedNodeIds`, plus WG nodes) into `buildRoutingSnapshot`, and make `sanitizeNodeTargets` record a warning when it nulls a target:

```kotlin
private fun List<RoutingRule>.sanitizeNodeTargets(
    availableNodeIds: Set<Long>,
    warnings: MutableList<String>,
): List<RoutingRule> = map { rule ->
    val keep = rule.targetNodeId?.takeIf { it in availableNodeIds }
    if (keep == rule.targetNodeId) rule
    else { warnings += "Rule '${rule.id}': target node ${rule.targetNodeId} unavailable; using default."; rule.copy(targetNodeId = keep) }
}
```
Append these warnings to the compiled snapshot (so `RoutingDiagnosticsCard` shows them — §5.6/§8). Run → green.

## Task 3F.5 — polish: de-duplicate the late LAN bypass

- [ ] **Step 1:** Add a `bypassLan=false` test proving the protected-path registry (not the toggle) carries the LAN invariant (slave/LAN still direct before user rules). Run → it passes today via the protected path.
- [ ] **Step 2:** Remove the redundant late `bypassLan` block (`SingboxConfigBuilder.kt:425-435`) OR move an unconditional LAN/private direct rule to **before** the user rules, so the invariant is self-evident. Keep the existing safety test green.

## Task 3F.6 — verify + commit

- [ ] `.\gradlew.bat :app:testDebugUnitTest` green; build; on-device QA (distinct egress for 2 nodes; kill one leg → its category falls back + warning, other node keeps working). Commit each task separately with `fix(android): ...`.

---

# Phase 6 — Selector master switch + Proxies-mode conflict resolution (default OFF)

## Task 6.1 — Prefs flag

- [ ] **Step 1: Failing test** (`PrefsTest` or via `RoutingViewModelTest`): `routingSelectorEnabled` defaults `false`, round-trips.
- [ ] **Step 2: Implement** in `data/Prefs.kt` (mirror the existing `routingDefaultOutcome` accessor style; also clear in `logout()`):

```kotlin
var routingSelectorEnabled: Boolean
    get() = sp.getBoolean("routing_selector_enabled", false)
    set(value) { sp.edit().putBoolean("routing_selector_enabled", value).apply() }
```
Run → green.

## Task 6.2 — gate in buildRoutingSnapshot (OFF = Proxies authoritative)

- [ ] **Step 1: Failing test** (`TunnelOrchestratorTest`): with `routingSelectorEnabled=false` and a category rule present + `selectedProxyId=5`, the snapshot has **no** user rules and `globalDefaultNodeId=5`; with `true`, the rule is present.
- [ ] **Step 2: Implement** at the top of `buildRoutingSnapshot` (TunnelOrchestrator.kt:72):

```kotlin
internal suspend fun buildRoutingSnapshot(nowMs: Long): RoutingPolicySnapshot {
    val enabledNodeIds = db.proxyNodeDao().getEnabled().map { it.id }.toSet()
    if (!prefs.routingSelectorEnabled) {
        return routingEngine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = prefs.selectedProxyId.takeIf { it > 0L && it in enabledNodeIds },
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = rulePackManager.activeVersions(),
            compiledAtMs = nowMs,
        )
    }
    // ...existing compile(...) when enabled...
}
```
Run → green.

## Task 6.3 — master switch UI

- [ ] **Step 1: Failing test** (`RoutingViewModelTest`): `setSelectorEnabled(true)` persists to `Prefs` and flips `RoutingUiState.selectorEnabled`.
- [ ] **Step 2: Implement** `RoutingUiState.selectorEnabled` + `RoutingViewModel.setSelectorEnabled(...)` (mirror `setGlobalDefault`), and a labelled `Switch("User routing rules")` at the top of `RoutingScreen` (under the title). When off, wrap the editor body in `enabled = state.selectorEnabled` / dim alpha. Run → green.

## Task 6.4 — cross-screen clarity

- [ ] **Step 1:** Add a read-only status row to `ProxiesScreen` driven by `Prefs.routingSelectorEnabled`: when ON, "Routing rules: ON — selected proxy may be overridden by Routing" (tap → navigate to Routing); when OFF, "Routing rules: OFF — selected proxy is authoritative."
- [ ] **Step 2:** Add an active-rule badge to the Routing item in `AppNavigation.kt` when the selector is on and rules exist. Build, on-device verify the toggle flips behavior + indicators. Commit.

---

# Phase 7 — Expanded presets + geosite & geoip online updater (over the tunnel) + Bypass-China sharing

## Task 7.1 — labelled 16-preset catalog

- [ ] **Step 1: Failing test** (`RoutingCategoryCatalogTest`): `default()` exposes the 16 keys, each with En+Zh labels and ≥1 geosite tag; `ruleSetsFor("google")` returns the google tags.
- [ ] **Step 2: Implement.** Extend `RoutingCategoryCatalog` to carry labels (replace `Map<String,List<String>>` with a `List<CategoryPreset>`):

```kotlin
data class CategoryPreset(val key: String, val labelEn: String, val labelZh: String,
                          val defaultDirect: Boolean, val tags: List<String>)
```
Seed the 16 verified presets from design §14.3 (google, youtube, streaming, ai, telegram, twitter, meta, tiktok, microsoft, apple, developer, gaming, music, finance, speedtest, social, china_direct). `china_direct.defaultDirect = true`; rest false. Keep `presetKeys()`/`ruleSetsFor()`; add `labelFor(key, zh: Boolean)`. In `geositeUrl()` do **not** re-encode `@` (for `geosite-apple@cn`) — or omit the two `@cn` tags. Update `RoutingViewModel` to surface labels (not raw keys) to the dropdown. Run → green.

## Task 7.2 — manifest schema + parser/validator

- [ ] **Step 1: Failing test** (`RulePackManifestTest`): parse a sample manifest JSON (geosite presets + geoip refs + versions) → a validated model; reject malformed/oversized input.
- [ ] **Step 2: Implement** a `RulePackManifest` model + Gson parse + validation (version present, tags well-formed, geoip `cnTag` present). Schema:
```json
{ "version": "2026.05.31", "geosite": [{"key":"google","labelEn":"Google","labelZh":"谷歌服务","defaultDirect":false,"tags":["geosite-google"]}],
  "geoip": { "version":"2026.05.31", "cnTag":"geoip-cn", "refs":[{"tag":"geoip-cn","url":"https://.../geoip-cn.srs"}] } }
```
Run → green.

## Task 7.3 — RulePackFetcher (over the tunnel) → activate

- [ ] **Step 1: Failing test** (`RulePackFetcherTest` with a mock OkHttp/dispatcher): a successful fetch parses+validates+activates via `RulePackManager`; a failed fetch leaves the active pack unchanged and returns an error; offline → falls back to the baked seed.
- [ ] **Step 2: Implement** `data/routing/RulePackFetcher.kt`: OkHttp GET of the GitHub manifest URL. **Primary path rides the active tunnel** (only auto-trigger/enable Update when `LeikwanVpnService.isRunning`); per the user's decision the GFW-poisoned bare connection is irrelevant because the request egresses through the connected node. DoH (`net/AppDns`) is a best-effort fallback for a disconnected manual check only. On success → `RulePackManager.activate(snapshot)` (store exists). Bake the 16-preset + current geoip refs as the offline **seed** (`RulePackSnapshot` with `sourceLabel="seed"`). Run → green.

## Task 7.4 — bind active pack into catalog + Bypass-China

- [ ] **Step 1: Failing test** (`SingboxConfigBuilderTest`): when an active pack is present, (a) category presets come from the pack; (b) the `bypassChina` block emits the pack's `geoip-cn`/`geosite-cn` refs (not the hardcoded SagerNet URL).
- [ ] **Step 2: Implement.** Source `RoutingCategoryCatalog` from the active pack (else seed). In `SingboxConfigBuilder` (~386-388, 440-443) replace the hardcoded `geoip-cn`/`geosite-cn` URLs with the active pack's refs so Settings→"Bypass China IP/Domain" uses the updated set (design §14.6). Run → green.

## Task 7.5 — updater UI + auto-check

- [ ] **Step 1:** Add to `SettingsScreen`: an "Update rules" button, last-updated/version + integrity/staleness display (from `RulePackManager.current()`), and a **rollback** action (`RulePackManager.previous()`). 
- [ ] **Step 2:** Auto-check on launch and/or a periodic `WorkManager` job, gated to run while connected. On-device: connect → Update → confirm new categories appear in Routing AND Bypass-China uses the refreshed set; test rollback. Commit.

---

# Phase 8 — Country-rule ISO-code search/autocomplete

## Task 8.1 — bundled country table

- [ ] **Step 1: Failing test** (`CountryCatalogTest`): `search("japan")` and `search("日本")` and `search("jp")` all resolve to `JP`; `nameFor("JP", zh=true)=="日本"`.
- [ ] **Step 2: Implement** `routing/CountryCatalog.kt`: a static list of ~250 `Country(code, nameEn, nameZh)` and `search(query): List<Country>` (case/diacritic-insensitive match on code/En/Zh). Run → green.

## Task 8.2 — typeahead UI in Add-country-rule

- [ ] **Step 1: Failing test** (`RoutingViewModelTest` or a UI test): typing a partial name filters suggestions; selecting one sets `matchValue` to the alpha-2 code.
- [ ] **Step 2: Implement.** Replace the raw "ISO code" `TextField` in `RoutingScreen` "Add country rule" with a search `TextField` + filtered dropdown of `CountryCatalog.search(query)`; selecting sets the alpha-2 code; the rule chip shows the friendly name (e.g. "日本 (JP)"). Raw-code entry still accepted. No model change (`matchValue` stays the code). Build, on-device verify. Commit.

---

# Phase 9 — Simplified-Chinese localization (full + in-app toggle, default zh)

> Mechanical but large (~150–200 strings). Do it in slices per screen so each commit builds. Do it LAST so it captures the strings added by Phases 6–8.

## Task 9.1 — externalize strings (slice per screen)

- [ ] For each of `ui/login`, `ui/tunnels`, `ui/proxies` (incl. `AddProxyDialog`, `ProxyNodeEditor`, `ProxyProtocolDraft`, `ProxyListSections`), `ui/routing` (incl. the new switch/labels/search), `ui/settings` (incl. the updater UI), `ui/shared/RoutingDiagnosticsCard`, the ViewModels with user-facing errors, and `service/LeikwanVpnService` (notification): move every literal into `res/values/strings.xml` (English base) and replace with `stringResource(R.string.x)` (Compose) / `context.getString(...)` (VM) / `getString(...)` (notification). Build after each slice. Final sweep: grep for residual `Text("` literals → zero user-facing literals remain.

## Task 9.2 — Chinese translations

- [ ] Add `res/values-zh-rCN/strings.xml` with Simplified-Chinese for every key, including the Phase-7 category labels and Phase-8 country names. Review for terminology consistency (节点/分流/规则/直连/隧道).

## Task 9.3 — in-app language toggle + default zh

- [ ] Add `androidx.appcompat` to `app/build.gradle.kts`; add `res/xml/locales_config.xml` listing `en` + `zh-CN`; reference it via `android:localeConfig` in `AndroidManifest.xml`. Add a language selector in `SettingsScreen` calling `AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(...))`. **Default new installs to `zh-CN`** (set the app locale on first run if unset), English available via the toggle.

## Task 9.4 — verify + commit

- [ ] Build + on-device: app renders fully in Chinese by default; the toggle switches en↔zh live; no screen shows a raw `string/...` id. Commit per slice + a final `feat(android): Simplified-Chinese localization + in-app language toggle`.

---

## Self-Review

- **Spec coverage:** 3-FIX ↔ design §14.1 (B1 egress, B2 isolation, port collision, silent warning, LAN dedup); Phase 6 ↔ §14.2; Phase 7 ↔ §14.3+§14.6 (geosite+geoip, tunnel-fetch, Bypass-China share); Phase 8 ↔ §14.5; Phase 9 ↔ §14.4. The three live user clarifications are in 7.3 (tunnel-fetch), 8 (ISO search), 7.4 (geoip+Bypass-China).
- **Placeholder scan:** concrete code is given for the tractable changes (leg-failure isolation, index-based ports, the selector gate + Prefs flag, the sanitize warning, the catalog preset type). Two steps are deliberately **spike-first** — 3F.1 (per-node bonded egress mechanism, depends on unread `BondForwardRunner`/forward↔node internals) and the exact `RulePackFetcher` wiring — because fabricating that code would violate "no undefined references"; each begins with a read/decision step.
- **Type consistency:** `routingSelectorEnabled` (Prefs) → gate in `buildRoutingSnapshot`; `NodeLegsResult.failedNodeIds` → `sanitizeNodeTargets(availableNodeIds, warnings)` → `snapshot.warnings`; `CategoryPreset`/`labelFor` → ViewModel dropdown; `RulePackManifest` → `RulePackFetcher` → `RulePackManager.activate` → catalog + bypassChina. Names are consistent across phases.
- **Ordering:** 3-FIX first (bonded promise + safety must be real); 6 (user control) before 7 (the thing being controlled); 8 small/independent; 9 last to capture all final strings.
