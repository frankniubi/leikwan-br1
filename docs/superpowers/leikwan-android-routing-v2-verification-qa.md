# LeiKwan Android Routing v2 — Verification & QA (T7)

Branch: `feature/android-routing-v2` (off `master`). Plan: `plans/2026-05-31-leikwan-android-routing-v2-plan.md`.
Design: `specs/2026-05-31-leikwan-android-routing-v2-design.md`.

## What was delivered

Real, user-defined country/website-category 分流 on top of the existing `single`/`bond` workflow, enforced in the
sing-box data path (not a stored no-op), with a user authoring UI. All 5 blockers + major gaps from the ground-truth
plan review are resolved.

Commits (each TDD, each gated on a full `:app:testDebugUnitTest` green; only Android files staged per commit):

| Commit | Task |
|---|---|
| `ae2dcda` | T0 — Robolectric + compose-ui-test infrastructure |
| `3237585` | T1 — Android-effective contract normalization (`AndroidEffectiveForwardBuilder`, `RuntimeIntent`) |
| `e463391` | T2 — routing models + `SafePathRegistry` + `RoutingCategoryCatalog` + `RoutingPolicyEngine` |
| `400c360` | T3 — compile user rules into sing-box `route.rules`/`route.rule_set` (**the no-op fix**); `build()` → `CompiledConfigResult` |
| `e053683` | T4 — Room v2 (`rule_pack_snapshots`, `routing_explainability`, `user_routing_rules`) + stores + `Prefs.routingDefaultOutcome` |
| `0fb381a` | T5 — orchestration: real `RoutingPolicySnapshot` wired into startup, DI seam, `ProxySelectionPolicy` 0L fix, `LeikwanVpnService` testable state |
| `bf9da35` | T6 — Routing authoring screen + `RoutingViewModel` + nav entry + read-only diagnostics in Settings/Tunnels |

## How the feature actually works (data path)

1. User opens the **Routing** screen → sets a global default (TUNNEL/DIRECT) and adds country (ISO) / preset-category
   (`video`/`ai`) rules with a TUNNEL/DIRECT outcome → persisted to `user_routing_rules` via `UserRoutingRuleStore`.
2. On tunnel start, `TunnelOrchestrator.buildRoutingSnapshot()` compiles a `RoutingPolicySnapshot`
   (`RoutingPolicyEngine.compile(prefs.routingDefaultOutcome, userRuleStore.countryRules(), …categoryRules(), …)`).
3. `SingboxConfigBuilder.buildRoute(...)` (the single enforcement point) emits, in precedence order: `sniff` →
   `hijack-dns` → **protected paths** (panel/mirror/loopback, direct) → **runtime slave/sidecar reachability**
   (direct) → **user rules** (country → `geoip-<cc>` rule_set; category → curated `geosite` rule_sets; each → `direct`
   or the proxy tag) → `bypassLan`/`bypassChina`/custom. `route.final` = proxy tag (TUNNEL default) or `direct`
   (DIRECT default). Every referenced remote rule-set is added once to `route.rule_set` as a `.srs` download.
4. libbox routes every connection from that JSON. An explainability snapshot is recorded for diagnostics.

## Automated verification (run on this machine, 2026-05-31)

- `./gradlew :app:testDebugUnitTest` → **BUILD SUCCESSFUL, 91 tests, 0 failures** (was 41 at baseline).
- `./gradlew :app:assembleDebug` → **BUILD SUCCESSFUL** (debug APK packages with `libbox.aar`).
- `./gradlew :app:compileDebugAndroidTestKotlin` → **BUILD SUCCESSFUL** (compose UI tests compile; run on device via `connectedDebugAndroidTest`).
- `./gradlew :app:lintDebug` → **BUILD FAILED on 2 PRE-EXISTING errors unrelated to this feature** (see below).
- Rule-set `.srs` liveness (HTTP 200, raw.githubusercontent.com/SagerNet): `geoip-cn`, `geosite-cn`, `geosite-youtube`,
  `geosite-netflix`, `geosite-disney`, `geosite-primevideo`, `geosite-hbo`, `geosite-bilibili`, `geosite-openai`,
  `geosite-category-ai-!cn` — **all 200**.

### lint status (honest)

`lintDebug` fails, but on **two pre-existing issues that predate this branch — the routing v2 changes introduce ZERO
new lint errors** (the routing/UI files added here are lint-clean):

1. `ui/MainActivity.kt:61` — `UnrememberedMutableState`: `var isLoggedIn by mutableStateOf(Prefs.get(this).isLoggedIn())`
   created during composition without `remember` (introduced by commit `36df395`, before this branch).
2. `AndroidManifest.xml:8` — `PermissionImpliesUnsupportedChromeOsHardware`: camera permission without a
   `<uses-feature android:name="android.hardware.camera" android:required="false"/>` tag (pre-existing QR-scanner manifest).

These were not touched because they are outside the routing feature's scope. Trivial opt-in fixes if desired:
wrap the `MainActivity` state in `remember { mutableStateOf(...) }`, and add the `uses-feature required="false"` tag.
`assembleDebug` already succeeds, so neither blocks building/installing the app.

Key data-path tests (in `SingboxConfigBuilderTest`): a `CN→DIRECT` rule emits `{rule_set:[geoip-cn], outbound:"direct"}`
plus a `geoip-cn` `route.rule_set` entry; a `video→TUNNEL` rule under `DIRECT` global default routes the geosite set to
the proxy tag with `route.final:"direct"`; protected panel rule is `direct` and ordered *before* any user rule; no
duplicate panel rule.

## Manual real-device QA (MANDATORY before release — needs a device + a real panel account)

Run on a physical Android device (min SDK 26). Mark each:

- [ ] Login + config pull + heartbeat succeed.
- [ ] Start/stop a `single` forward; traffic flows; stop is clean.
- [ ] Start/stop a `bond` forward; traffic flows; stop is clean.
- [ ] VPN permission grant / deny / retry all behave (no half-open state on deny).
- [ ] App background → foreground keeps the tunnel; status correct.
- [ ] Process death / recreation recovers without data wipe.
- [ ] Add a **country** rule (e.g. `CN→DIRECT`); restart tunnel; confirm CN destinations go direct (e.g. an
      ip.cn-style check), non-CN still tunneled.
- [ ] Add a **category** rule (e.g. `video→DIRECT` or `ai→TUNNEL`); restart tunnel; confirm the category splits.
- [ ] Toggle the **global default** TUNNEL↔DIRECT; confirm `route.final` behavior flips (everything-tunnel vs
      everything-direct-except-rules).
- [ ] **Protected-path non-override:** add a user rule that would tunnel the panel host; confirm panel/login/pull
      still go direct and stay reachable (protected paths win).
- [ ] Rule-set offline/failure: with no network for the `.srs` download, confirm the tunnel still starts on the
      default path (rule packs are advisory; failure must not down login/pull/default tunnel).
- [ ] Repeated start failures hit the retry cap with a user-visible fault (no infinite flapping).
- [ ] Diagnostics card on Settings + Tunnels shows the default action / protected-traffic / warnings consistently.
- [ ] No `mptcp`/`race` options appear anywhere in the UI.

## Known limitations / deferred (by design, v1)

- DNS-side optimization for DIRECT user-rule domains is minimal (route rules enforce the split correctly; resolving
  DIRECT-category domains via `direct-dns` is a future refinement).
- Live hot-reload on rule edit is not implemented; edits apply on the next tunnel start/restart.
- Category taxonomy is curated in `RoutingCategoryCatalog` (`video`/`ai`); extending it is a code change (the
  remotely-refreshable rule-pack path exists via `RulePackManager` but is not yet sourced by the panel).
- Compose UI tests compile but require a device for `connectedDebugAndroidTest`.
- `MIGRATION_1_2` is exercised by a real on-disk v1→v2 upgrade; verify once on a device that had a prior install.
