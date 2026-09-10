# LeiKwan Android Routing v2 — Corrected Design (supersedes 2026-05-30 design)

> **Status:** This document supersedes `2026-05-30-leikwan-android-routing-workflow-design.md` after a full
> ground-truth review of the plan against the real `leikwan-android/` codebase plus NekoBox/sing-box routing
> research. The 2026-05-30 design's *intent* (balanced enhancement, two-outcome user routing, protected paths,
> rule packs, explainability) is preserved. This v2 fixes the parts that made the feature a **runtime no-op**
> and adds the **user-authoring surface** that the original plan never specified.

## Why v2 exists (review findings)

The 2026-05-30 plan was verified to be **not executable as-is**. Five blockers:

1. **User country/category rules were a stored no-op.** `RoutingPolicyEngine.decide()` is pure Kotlin invoked
   only by a unit test. Real per-packet routing is done entirely by **libbox/sing-box from the JSON config**
   (`LeikwanVpnService` → `commandServer.startOrReloadService(config)`). The plan never translated
   `userCountryRules`/`userCategoryRules` into sing-box `route.rules`, so a "CN→DIRECT" rule changed nothing.
2. **No rule-authoring UI or persistence** existed anywhere — the "用户自定义分流" requirement had no surface and
   `compile(countryRules, categoryRules)` had no producer; the orchestrator passed `routingPolicySnapshot = null`.
3. **Missing test infrastructure** — no Robolectric / `androidx.test:core` / compose-ui-test / `androidTest`
   source set, yet Tasks 3/4/5 wrote Android-context tests under `src/test`.
4. **`build()` return-type churn** (String → `CompiledConfigResult`) split across two commits would break 6
   existing test call sites + 3 orchestrator uses mid-slice (compile failure).
5. **`LeikwanVpnServiceTest`** mutated `private set` companion fields (won't compile).

Confirmed-good: the Task-1 contract/runtime API assumptions are accurate; the `ProxySelectionPolicy` zero-proxy
bug is real; **the repo already contains the exact mechanism we need** — the China-bypass `geoip-cn`/`geosite-cn`
remote `rule_set` blocks at `SingboxConfigBuilder.kt:313-370`.

## The single most important correction: enforcement lives in sing-box `route.rules`

sing-box/libbox routes every connection from the JSON config; there is **no per-connection Kotlin callback**.
Therefore the **only** place user routing can be enforced is the JSON that `SingboxConfigBuilder.build()` emits.

`RoutingPolicyEngine` is reframed as a **compiler + precedence authority**, not a runtime decision hook:

- `compile(...)` produces the authoritative `RoutingPolicySnapshot` for one rebuild cycle.
- `decide(snapshot, request)` is kept **only** as the canonical, unit-testable statement of precedence and to
  power explainability/diagnostics. It is explicitly **not** in the data path.
- `SingboxConfigBuilder.buildRoute(...)` is the **single enforcement point**. It projects the snapshot's
  precedence into `route.rules` + `route.rule_set` using the proven SagerNet `.srs` mechanism, in the **same
  order** that `decide()` documents.

## User-facing routing model (the "分流" the user asked for)

Decisions captured from the user:
- **Data source:** reuse SagerNet `geoip`/`geosite` remote `rule_set` (same as China-bypass).
- **Granularity for v1:** **country** (ISO code) + **preset website categories** (`video`, `ai`) → **TUNNEL** or **DIRECT**.

### Two-outcome model + a user-settable global default

- **Global default outcome** (new, user-settable, `Prefs.routingDefaultOutcome`, default `TUNNEL`):
  - `TUNNEL` → `route.final = <proxy tag>` (global VPN; rules are DIRECT exceptions). This is the current behavior.
  - `DIRECT` → `route.final = "direct"` (selective tunneling; rules are TUNNEL exceptions).
- **User rules** are explicit exceptions, each compiled into a concrete `route.rule`:
  - `COUNTRY` + matchValue ISO `cc` → `{ "rule_set": ["geoip-<cc>"], "outbound": "direct" | "<proxy tag>" }`.
  - `WEBSITE_CATEGORY` + matchValue (`video`/`ai`) → `{ "rule_set": [<category geosite tags…>], "outbound": … }`.
  - outcome `DIRECT` → `outbound: "direct"`; outcome `TUNNEL` → `outbound: <finalProxyTag>`.
- Every referenced remote rule-set tag is added once to `route.rule_set` with its `.srs` URL (generalizing the
  existing China block), de-duplicated by tag.

### Category taxonomy (curated, code-owned, verified against the SagerNet rule-set branch)

`RoutingCategoryCatalog` maps each preset category to a small set of **known-good** SagerNet `geosite` rule-set
tags. Tags must be verified to resolve (`HTTP 200` on the `.srs` URL) during implementation; unknown tags are
dropped with a warning rather than poisoning the whole config.

- `video` → e.g. `geosite-youtube`, `geosite-netflix`, `geosite-disney`, `geosite-primevideo`, `geosite-hbo`,
  `geosite-bilibili` (final list pinned to tags that return 200).
- `ai` → e.g. `geosite-openai`, `geosite-category-ai-!cn` (final list pinned to tags that return 200).

`.srs` URL template: `https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/<tag>.srs` and
`https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/<tag>.srs` for geoip.

## Precedence (enforced identically in `decide()` and `buildRoute()`)

1. **`sniff`** then **`hijack-dns`** (unchanged sing-box housekeeping).
2. **System-protected paths** (`SafePathRegistry` baseline: panel host, `v3-mirror.leikwanhost.com`, loopback +
   RFC-1918) → `direct`. **Non-overridable.** This *replaces* the ad-hoc `panelDirectDomains()` so there is one
   source of truth (no duplicate panel/mirror rules).
3. **Runtime slave/sidecar reachability** (`runtimeContext.bypassHosts`: endpoint hosts, cdnHost, sni, bonding
   quicServerName) → `direct`. **Non-overridable**, emitted before user rules so a user TUNNEL rule can never
   capture the slave egress and create a loop. *(Must not regress the current behavior at
   `SingboxConfigBuilder.kt:275-299`.)*
4. **User rules** (country, then category), each compiled to its `direct`/`<proxy>` outbound.
5. **`bypassLan`** toggle → `direct` (if enabled).
6. **`bypassChina`** toggle → `direct` (if enabled; kept for back-compat — also expressible as a `CN→DIRECT` rule).
7. **`customBypassRules`** → `direct`.
8. **`route.final`** = `<proxy tag>` if global default is `TUNNEL`, else `"direct"`.

## Component contracts (the integration backbone — fixed across all tasks)

New package `com.leikwan.bridge.routing`:

```kotlin
enum class UserTrafficOutcome { TUNNEL, DIRECT }
enum class RoutingRuleKind { COUNTRY, WEBSITE_CATEGORY }
enum class ProtectedPathHandling { BYPASS_DIRECT }
enum class RoutingDecisionReason { SYSTEM_PROTECTED, RUNTIME_REACHABILITY, USER_RULE, DEFAULT, UNSUPPORTED_RULE_REJECTED }

data class RoutingRule(val id: String, val kind: RoutingRuleKind, val matchValue: String,
                       val outcome: UserTrafficOutcome, val enabled: Boolean = true)
data class SafePathEntry(val id: String, val domains: Set<String>, val ipCidrs: Set<String>,
                         val handling: ProtectedPathHandling = ProtectedPathHandling.BYPASS_DIRECT)
data class RoutingRequest(val destinationHost: String?, val destinationIp: String?,
                          val countryCode: String?, val websiteCategory: String?, val isBootstrapTraffic: Boolean)
data class RoutingDecision(val outcome: UserTrafficOutcome, val reason: RoutingDecisionReason,
                           val matchedRuleId: String?, val protectedPathId: String?)

// route.rule_set ref produced by the compiler for buildRoute to emit
data class RuleSetRef(val tag: String, val url: String)            // format "binary"
data class CompiledRouteRule(val ruleSetTags: List<String>, val outbound: String, val sourceRuleId: String?)

data class RoutingPolicySnapshot(
    val protectedPaths: List<SafePathEntry>,
    val userCountryRules: List<RoutingRule>,
    val userCategoryRules: List<RoutingRule>,
    val globalDefaultOutcome: UserTrafficOutcome,     // user-settable; was "defaultUserTrafficOutcome"
    val activeRulePackVersions: List<String>,
    val compileTimestampMs: Long,
    val warnings: List<String>,
    val precedenceVersion: Int,
)
```

`RoutingPolicyEngine(registry, categoryCatalog)`:
- `compile(globalDefault, countryRules, categoryRules, rulePackVersions, compiledAtMs): RoutingPolicySnapshot`
- `decide(snapshot, request): RoutingDecision`  *(diagnostics/precedence spec only)*
- `compileRouteRules(snapshot): List<CompiledRouteRule>` and `requiredRuleSets(snapshot): List<RuleSetRef>`
  *(consumed by `SingboxConfigBuilder.buildRoute`)*

`SingboxConfigBuilder` (the one return-type change happens **once**, in the routing-compiler task):
```kotlin
fun build(proxyNodes, runtimeContext, routingPolicySnapshot: RoutingPolicySnapshot? = null,
          connectionMode = SERVICES): CompiledConfigResult
data class CompiledConfigResult(val configJson: String, val finalProxyTag: String, val warnings: List<String> = emptyList())
```
All 6 existing `SingboxConfigBuilderTest` call sites and the 3 `TunnelOrchestrator` uses migrate to
`.configJson` in the **same** commit.

Persistence (`data/routing/`):
- Room **v2**, migration `1→2`, entities: `rule_pack_snapshots`, `routing_explainability`, **`user_routing_rules`**
  (`id` TEXT PK, `kind`, `match_value`, `outcome`, `enabled`, `created_at`).
- `UserRoutingRuleStore` (CRUD over `user_routing_rules`), `RulePackManager`, `RoutingExplainabilityStore`.
- `Prefs`: `internal constructor`; new keys `routing_default_outcome`, rule-pack metadata, explainability
  revision. `logout()` stays auth-only; user routing rules persist across logout **but are cleared on
  `switchClient()`** (rules are per-client). `PanelRepository.switchClient()`/`logout()` also clear
  `cachedEffectiveForwards`.

Orchestration:
- `TunnelOrchestrator` gains a DI seam (secondary constructor / default-param factory for `PanelRepository`,
  `Prefs`, `AppDatabase`, `ProcessRunner`) so it is unit-testable; it **builds a real `RoutingPolicySnapshot`**
  (registry protected paths + `RulePackManager.current()` versions + `UserRoutingRuleStore.all()` +
  `Prefs.routingDefaultOutcome`) via `RoutingPolicyEngine.compile(...)` and passes it into `build(...)`
  (**stops passing `null`**). It records the explainability snapshot after compile and triggers a sing-box
  reload when rules change.
- `LeikwanVpnService`: `startupState`/`lastStartError` setters become `@VisibleForTesting internal set` (or a
  `resetStartupStateForTest()` helper) so the service test compiles.

UI:
- New **Routing** screen + `RoutingViewModel`: global-default toggle; add/edit/delete country rules (ISO picker)
  and preset-category rules (`video`/`ai`) with a TUNNEL/DIRECT switch; persists via `UserRoutingRuleStore`;
  triggers a reload so edits take effect. Added to `AppNavigation` bottom bar (`Screen.Routing`).
- `RoutingDiagnosticsSource.from(app)` reads `RoutingExplainabilityStore.latest()` and falls back to a
  **deterministic default-from-registry summary** so first-launch diagnostics are non-null. Settings/Tunnels
  show read-only diagnostics cards (the original Task 5).

## Non-goals (unchanged from v1)

No Android `mptcp`/`race`; no arbitrary outbound graphs or per-rule imported-proxy selection; no app-level
split tunneling; no NekoBox-scale DSL. User rules resolve only to `TUNNEL` or `DIRECT`.

## Test strategy

- Pure-JVM JUnit for `RoutingPolicyEngine`, `RoutingCategoryCatalog`, `SafePathRegistry`, and the
  `SingboxConfigBuilder` route-content assertions (no Android context needed — assert by rule **content/order**,
  never fixed indices).
- Robolectric (`@RunWith(RobolectricTestRunner)`, `@Config(sdk=[34])`) for `Prefs`, Room, stores, orchestrator —
  enabled by the test-infra task first.
- Compose instrumented tests for the Routing/Settings/Tunnels screens under a new `androidTest` source set.
- Real-device QA gate (login, single, bond, a country rule, a category rule, protected-path non-override,
  rule-pack failure, crash/restart cap) before "done".

## Release gates

All v1 gates from the 2026-05-30 design **plus**: a compiled config for a `CN→DIRECT` rule contains a
`route.rule` `{rule_set:[geoip-cn], outbound:"direct"}` and a `route.rule_set` entry for `geoip-cn`; a
`video→TUNNEL` rule (under `DIRECT` global default) contains a rule with `outbound == finalProxyTag`; protected
panel/mirror/slave paths are `direct` and ordered before all user rules; toggling a rule in the UI changes the
emitted config on the next start/reload.
