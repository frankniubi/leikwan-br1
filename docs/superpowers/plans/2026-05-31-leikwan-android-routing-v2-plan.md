# LeiKwan Android Routing v2 — Implementation Plan (supersedes 2026-05-30 plan)

> Design: `docs/superpowers/specs/2026-05-31-leikwan-android-routing-v2-design.md`.
> Baseline verified GREEN: `./gradlew :app:testDebugUnitTest` → BUILD SUCCESSFUL (warm daemon ≈ seconds).
> Execute task-by-task with TDD: failing test → minimal impl → targeted test → full `:app:testDebugUnitTest` → commit.
> Working dir: `C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android`. Branch off `master`.

## Invariants every task must preserve
- Topology `client -> slave -> sidecar -> target`; Android exposes only `single` / `bond`.
- `runtimeContext.bypassHosts` (slave/sidecar endpoints) MUST stay `direct` and ordered before user rules — never regress `SingboxConfigBuilder.kt:275-299`.
- Enforcement is sing-box `route.rules` only. `RoutingPolicyEngine.decide()` is diagnostics/precedence spec, never the data path.
- Protected paths are non-overridable and replace the ad-hoc `panelDirectDomains()` (one source of truth, no dup panel/mirror rules).
- Assert route behavior by rule **content/order**, never fixed array indices.

---

## Task 0 — Test infrastructure (MUST be first; unblocks all Android-context tests)
**Files:** `app/build.gradle.kts` (Modify); `app/src/test/java/com/leikwan/bridge/InfraSmokeTest.kt` (Create);
`app/src/androidTest/java/com/leikwan/bridge/AndroidInfraSmokeTest.kt` (Create).

Steps:
1. Add to `app/build.gradle.kts`:
   - `defaultConfig { testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner" }`
   - `android { testOptions { unitTests { isIncludeAndroidResources = true } } }`
   - `testImplementation("org.robolectric:robolectric:4.12.2")`, `testImplementation("androidx.test:core-ktx:1.6.1")`
   - `androidTestImplementation(platform("androidx.compose:compose-bom:2024.06.00"))`,
     `androidTestImplementation("androidx.compose.ui:ui-test-junit4")`,
     `debugImplementation("androidx.compose.ui:ui-test-manifest")`,
     `androidTestImplementation("androidx.test.ext:junit:1.2.1")`.
2. `InfraSmokeTest`: `@RunWith(RobolectricTestRunner::class) @Config(sdk=[34])` building an in-memory Room DB + `ApplicationProvider.getApplicationContext()` to prove Robolectric works.
3. Gate: `./gradlew :app:testDebugUnitTest` PASS (Robolectric downloads its jars on first run — allow extra time). Do **not** run `connectedDebugAndroidTest` (no device); just confirm `:app:compileDebugAndroidTestKotlin` compiles for the androidTest smoke file.
4. Commit: `chore(android): add robolectric + compose test infra`.

## Task 1 — Contract cleanup + Android-effective normalization
Largely the 2026-05-30 Task 1 (its API assumptions were verified accurate). Corrections:
- Do **not** add `hiddenUnsupportedMptcpCount` — it already exists (`TunnelsViewModel.kt:23`, `ForwardFilter`/`ForwardCatalog`).
- Keep `bonding.quicServerName` in `AndroidEffectiveForward.bypassHosts` (BondForwardRunner adds it today — don't regress).
- Preserve `ForwardEndpoint.dynamicPriority`/`nodeId` when copying endpoints.
- Add `cachedEffectiveForwards.clear()` to `PanelRepository.switchClient()` (`:65-66`) and `logout()` (`:124-125`), not only `pullCatalog()`.

**Files:** Modify `api/PanelRepository.kt`, `api/PanelModels.kt`, `runtime/ForwardContract.kt`, `runtime/ForwardFilter.kt`, `runtime/RuntimeContext.kt`; Create `runtime/AndroidEffectiveForwardBuilder.kt`, `runtime/RuntimeIntent.kt`; Tests `runtime/AndroidEffectiveForwardBuilderTest.kt`, extend `runtime/ForwardFilterTest.kt`, `api/PanelModelsContractParsingTest.kt` (pure JVM — no Robolectric).

TDD: failing `AndroidEffectiveForwardBuilderTest` (single/bond kept, race/mptcp → null; catalog hides mptcp, clears stale selection) → implement `AndroidEffectiveForward`/`Builder` + `RuntimeIntent`/`toRuntimeIntent()` + repo cache → `:app:testDebugUnitTest` PASS → commit `feat(android): normalize android forward contracts`.

## Task 2 — Routing models, registry, category catalog, engine (pure JVM)
**Files (Create):** `routing/RoutingPolicyModels.kt`, `routing/SafePathRegistry.kt`, `routing/RoutingCategoryCatalog.kt`,
`routing/RoutingPolicySnapshot.kt`, `routing/RoutingPolicyEngine.kt`; Tests `routing/RoutingPolicyEngineTest.kt`,
`routing/SafePathRegistryTest.kt`, `routing/RoutingCategoryCatalogTest.kt` (all pure JUnit).

Implement the contracts from the v2 design's "Component contracts". Key behaviors:
- `SafePathRegistry.androidBaseline(panelUrl, mirrorHost)` → entries `panel-api` (panel host), `panel-mirror`
  (mirror), `loopback-control` (`127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`).
- `RoutingCategoryCatalog`: `video`/`ai` → curated geosite tags + `.srs` URLs; `ruleSetsFor(matchValue)` returns
  empty + a warning for unknown categories (never throws).
- `RoutingPolicyEngine.compile(globalDefault, country, category, packVersions, ms)` → snapshot (registry paths,
  rules as-given, `globalDefaultOutcome`, versions, ts, warnings, `precedenceVersion=1`). Reject rules whose
  `kind`/`matchValue` are unsupported (drop + warning), never expand outcomes beyond TUNNEL/DIRECT.
- `decide(snapshot, request)` implements precedence steps 2→4→default and returns the right `RoutingDecisionReason`
  (protected wins over user rule even when a user rule says TUNNEL).
- `compileRouteRules(snapshot)` / `requiredRuleSets(snapshot)` produce the `CompiledRouteRule`/`RuleSetRef` lists
  Task 3 emits (country→`geoip-<cc>`, category→catalog tags; outbound `direct` or sentinel `"<PROXY>"` that
  Task 3 swaps for the real `finalProxyTag`).

TDD: failing tests (protected-beats-user; `CN→DIRECT` yields a `CompiledRouteRule(ruleSetTags=[geoip-cn], outbound=direct)`; `video→TUNNEL` yields catalog tags + proxy sentinel; unknown category → warning, no rule) → implement → `:app:testDebugUnitTest` PASS → commit `feat(android): safe routing precedence + category compiler core`.

## Task 3 — Compile routing into sing-box `route.rules` (THE no-op fix; one return-type change)
**Files:** Modify `singbox/SingboxConfigBuilder.kt`; Create `routing/CompiledConfigResult.kt`; Modify
`singbox/SingboxConfigBuilderTest.kt` (migrate all 6 existing call sites to `.configJson` **in this commit**).

Steps:
1. Create `CompiledConfigResult(configJson, finalProxyTag, warnings=emptyList())`.
2. Change `build(...)` signature **once** to add `routingPolicySnapshot: RoutingPolicySnapshot? = null` and return
   `CompiledConfigResult`. Update the 6 existing test call sites (`SingboxConfigBuilderTest.kt:57,118,161,214,258,300`)
   to `JsonParser.parseString(builder.build(...).configJson)` in the same commit so the module always compiles.
3. Rework `buildRoute(runtimeBypassHosts, finalProxyTag, snapshot)`:
   - sniff, hijack-dns (unchanged).
   - **protected paths**: emit one `direct` rule per `snapshot.protectedPaths` entry (domains rule and/or ip_cidr
     rule); **remove** the separate `panelDirectDomains()` injection and fold panel/mirror into the registry
     baseline passed via the snapshot (when snapshot is null, keep a registry built from `settings.panelUrl` so
     protected coverage never disappears).
   - **runtime bypass** (`runtimeBypassHosts`, slave/sidecar) → `direct`, before user rules (preserve today's split-by-ip/domain logic).
   - **user rules** via `engine.compileRouteRules(snapshot)`: each `CompiledRouteRule` → `{ rule_set:[tags], outbound: direct|finalProxyTag }` (swap the `"<PROXY>"` sentinel for `finalProxyTag`).
   - bypassLan, bypassChina, customBypassRules (unchanged).
   - `route.final = if (snapshot?.globalDefaultOutcome == DIRECT) "direct" else finalProxyTag`.
   - `route.rule_set`: union of existing China sets (if toggle on) + `engine.requiredRuleSets(snapshot)`, **de-duplicated by tag**, each `{tag,type:"remote",format:"binary",url}`.
   - mirror the same direct rule for the DNS `rules` (country/category DIRECT hosts should resolve via `direct-dns`) — minimally, add referenced geosite category/cn sets to the DNS `direct-dns` rule_set list for DIRECT rules.
4. Construct `SingboxConfigBuilder` with an injected `RoutingPolicyEngine` (default `RoutingPolicyEngine(SafePathRegistry.androidBaseline(settings.panelUrl, "v3-mirror.leikwanhost.com"), RoutingCategoryCatalog.default())`).

TDD additions to `SingboxConfigBuilderTest` (assert by content/order): protected panel rule is `direct` and precedes
any user rule; a snapshot with `CN→DIRECT` produces a route rule `{rule_set:[geoip-cn], outbound:"direct"}` and a
`route.rule_set` entry `geoip-cn`; a `video→TUNNEL` snapshot under `globalDefault=DIRECT` produces a rule whose
`outbound == finalProxyTag` and `route.final == "direct"`; no duplicate panel/mirror rule remains.

Gate: `./gradlew :app:testDebugUnitTest` PASS. Commit `feat(android): compile user country/category rules into sing-box route`.

## Task 4 — Persistence: Room v2 (rule packs, explainability, USER RULES) + Prefs
**Files:** Modify `data/Prefs.kt` (internal ctor + keys + `routing_default_outcome`), `data/AppDatabase.kt` (v2 +
MIGRATION_1_2 + 3 entities/3 DAOs); Create `data/routing/{RulePackSnapshot,RoutingExplainabilitySnapshot,
RulePackEntities,RulePackDao,RoutingExplainabilityDao,UserRoutingRuleEntities,UserRoutingRuleDao,RulePackManager,
RoutingExplainabilityStore,UserRoutingRuleStore}.kt`; Tests (Robolectric) `data/PrefsRoutingStateTest.kt`,
`data/AppDatabaseRoutingTablesTest.kt`, `data/routing/{RulePackManagerTest,RoutingExplainabilityStoreTest,UserRoutingRuleStoreTest}.kt`.

Specify (the 2026-05-30 plan left these implicit):
- Entities use PK `slot` (rule pack: `active`/`previous`; explainability: `latest`) with `@Insert(onConflict=REPLACE)`
  upsert; DAOs expose `active()/previous()/latest()/deleteBySlot()`. `user_routing_rules` PK `id` TEXT,
  `@Query` `all()`/`byKind()`/`upsert()`/`deleteById()`/`clear()`.
- `toEntity(slot)`/`toSnapshot(clock)` mappers; `RulePackManager.activate()` moves prior `active`→`previous`,
  recomputes `rollbackAvailable` from `previous` existence, mirrors versions into `Prefs`; 24h freshness window.
- `logout()` stays auth-only (routing metadata + user rules survive). `UserRoutingRuleStore.clear()` is invoked on
  `switchClient()` (rules are per-client). `Prefs.routingDefaultOutcome` getter/setter (default TUNNEL).
- Use `androidx.room.withTransaction` (room-ktx already present); `Room.databaseBuilder(...).addMigrations(MIGRATION_1_2)`.

TDD: Robolectric tests (in-memory Room) prove migration tables exist, manager keeps previous + sets prefs +
`rollbackAvailable`, explainability replaces latest + mirrors revision, user-rule store CRUD + per-kind query +
clear. Gate `:app:testDebugUnitTest` PASS. Commit `feat(android): persist rule packs, explainability, user routing rules`.

## Task 5 — Orchestration: build a real snapshot + DI seam + fixes
**Files:** Modify `service/ProxySelectionPolicy.kt` (add `return` in 0L branch), `service/ProxySelectionPolicyTest.kt`
(**Modify**, add zero-proxy case — do not clobber 4 existing), `service/TunnelOrchestrator.kt` (DI seam + real
snapshot + record explainability + reload-on-change), `service/LeikwanVpnService.kt` (`@VisibleForTesting internal set`
or `resetStartupStateForTest()`); Create `service/TunnelOrchestratorTest.kt`, `service/LeikwanVpnServiceTest.kt` (Robolectric).

Steps:
1. Fix `ProxySelectionPolicy.kt:17-19` (`return ProxySelectionResult.Error(...)`). Add the zero-proxy test to the existing file.
2. `LeikwanVpnService`: expose `startupState`/`lastStartError` via `@VisibleForTesting internal set` (tests are
   same-module) **or** add `internal fun resetStartupStateForTest(state, error)`. Rewrite `LeikwanVpnServiceTest`
   to use whichever is chosen (definite, not hedged).
3. `TunnelOrchestrator`: add a secondary constructor / default-param factory injecting `PanelRepository`, `Prefs`,
   `AppDatabase`, `ProcessRunner`, and a `RoutingPolicyEngine`/`UserRoutingRuleStore`/`RulePackManager`. Build a
   real `RoutingPolicySnapshot` (`engine.compile(prefs.routingDefaultOutcome, userRuleStore.countryRules(),
   userRuleStore.categoryRules(), rulePackManager.activeVersions(), now)`) and pass it into `build(...)` (stop
   passing `null`). Use `compiled.configJson` for all 3 uses (assignment, `writeDebugConfig`, `Log.d`). Record an
   explainability snapshot via `RoutingExplainabilityStore.record(...)` after compile. Move `writeDebugConfig`
   **before** the network pull (or make the test inject a fake `PanelRepository` returning a canned catalog) so
   `TunnelOrchestratorTest` can assert the debug file deterministically.
4. `TunnelOrchestratorTest`: with injected fakes, `startForForward(45L)` writes `latest-singbox-config.json`
   containing the compiled route (and, if a `CN→DIRECT` user rule is seeded, a `geoip-cn` `direct` rule).

Gate `:app:testDebugUnitTest` PASS. Commit `feat(android): wire real routing snapshot into vpn startup`.

## Task 6 — User-rule authoring UI (the 用户自定义分流 surface)
**Files:** Create `ui/routing/RoutingScreen.kt`, `ui/routing/RoutingViewModel.kt`, `ui/shared/RoutingDiagnosticsUiModels.kt`,
`routing/RoutingDiagnosticsSource.kt`; Modify `ui/nav/AppNavigation.kt` (add `Screen.Routing` + composable),
`ui/settings/SettingsViewModel.kt`+`SettingsScreen.kt` and `ui/tunnels/TunnelsViewModel.kt`+`TunnelsScreen.kt`
(read-only diagnostics card; split into `*Content` composables); Tests `ui/routing/RoutingViewModelTest.kt`
(Robolectric), `ui/settings/SettingsViewModelTest.kt`, `ui/tunnels/TunnelsViewModelTest.kt` (Robolectric),
`androidTest/.../RoutingScreenTest.kt` (compose).

Steps:
1. `RoutingDiagnosticsSource.from(app).latest(): RoutingDiagnosticsUiModel?` reads `RoutingExplainabilityStore.latest()`;
   when empty, synthesize a deterministic default-from-registry summary (`defaultActionLabel` from
   `Prefs.routingDefaultOutcome`, protected-paths label, `"system-protected traffic is enforced"` warning) so it is non-null.
2. `RoutingViewModel(app, store=UserRoutingRuleStore.from(app), prefs=Prefs.get(app))`: state = global default +
   list of country rules + list of category rules; actions `setGlobalDefault`, `addCountryRule(cc,outcome)`,
   `addCategoryRule(video|ai,outcome)`, `toggleRule(id)`, `deleteRule(id)`; persist + request a reload
   (`TunnelOrchestrator` reload if running). Categories limited to `RoutingCategoryCatalog.presetKeys()`.
3. `RoutingScreen`/`RoutingScreenContent`: global-default segmented control; "Add country" (ISO dropdown) and
   "Add category" (`video`/`ai`) with TUNNEL/DIRECT switch; rule list with enable toggle + delete. Add to bottom bar.
4. Settings/Tunnels: add read-only `RoutingDiagnosticsUiModel` card (Default action, Decision reason, Protected
   traffic, Active rule packs, Warnings) via `RoutingDiagnosticsSource`; split screens into testable `*Content`.

TDD: `RoutingViewModelTest` (add/persist/toggle/delete a rule reaches `UserRoutingRuleStore`; default toggle hits
prefs); `Settings/TunnelsViewModelTest` expose non-null diagnostics; compose `RoutingScreenTest` renders the
add-rule controls and a seeded rule row. Gate `:app:testDebugUnitTest` PASS (+ `:app:compileDebugAndroidTestKotlin`).
Commit `feat(android): user-defined country/category routing UI + diagnostics`.

## Task 7 — Final verification
1. `./gradlew :app:testDebugUnitTest` (full) PASS; `./gradlew :app:assembleDebug :app:lintDebug` clean.
2. `:app:compileDebugAndroidTestKotlin` compiles (instrumented tests run on a device when available).
3. Verify the SagerNet `.srs` tags chosen in `RoutingCategoryCatalog` return HTTP 200 (curl each URL).
4. Produce a manual real-device QA checklist (login, single, bond, one country rule, one category rule,
   protected-path non-override, rule-pack failure behavior, crash/restart cap, background/foreground, process death).
5. Commit any final fixes. Summarize delivered vs deferred.

## Sequencing & gates
0 → 1 → 2 → 3 → 4 → 5 → 6 → 7, strictly sequential (cross-task type deps + shared module). Every task ends with a
full `:app:testDebugUnitTest` green and a commit. A task is not "done" until its gate passes; on red, repair before
proceeding (no skipping).
