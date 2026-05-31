# Leikwan Android — Multi-Node Category Routing ("应用场景分流") Design

**Date:** 2026-05-31
**Status:** Design (approved scope; pending spec review)
**Module:** `leikwan-android` (`com.leikwan.bridge`)
**Related:** `docs/2026-05-31-android-direct-vpn-still-bad-handoff.md` (gvisor data-plane fix),
`docs/superpowers/specs/2026-05-31-leikwan-android-routing-v2-design.md` (the binary v2 routing core this extends)

---

## 1. Goal

Let the user run **multiple proxy nodes simultaneously** (Shadowsocks, VLESS, VMess, and a new
**WireGuard** node type) and route **traffic categories** to **different nodes at the same time** —
the "应用场景分流 / Traffic Selector by Site Type" surface. Example the user wants:

- **Video** (YouTube + all its domains, Netflix, Disney+, …) → node A
- **AI** (ChatGPT, Claude + all their domains) → node B
- **Country** (e.g. all JP-geo traffic) → node C
- **Custom** domain/IP lists → any node
- Everything else → a user-chosen **global-default** node (or direct)

…with the **bonded/MPTCP transport preserved** for relay-able nodes (the product's speed
differentiator), not only plain direct connections.

## 2. Scope

**In scope**
- Per-rule **node-addressable** routing: each category / country / custom rule targets a specific
  node (or "direct", or "use global default").
- Multiple nodes active concurrently, each carrying different categories.
- Node protocols: Shadowsocks, VLESS, VMess (existing) + **WireGuard** (new).
- **Bonded multi-node transport** — one relay leg per relay-able node (not a single shared leg).
- An automatic, user-invisible, non-overridable **safety bypass** of slave/sidecar IPs and LAN/
  private IP ranges in every generated config.
- Fixing the Routing-tab crash (prerequisite — the screen this feature lives on currently crashes).

**Out of scope (explicitly)**
- **Per-Android-app (by-package) routing.** The user's "different apps" means *site-type
  categories*, not Android packages. `LeikwanVpnService.findConnectionOwner` stays a stub and
  `useProcFS()` stays `false`; no `package_name`/`process_name` route rules are emitted. Removing
  this from scope deletes the highest-risk hot-path work.
- Auto-failover **node groups** (selector/urltest per category). Deferred; see §10 Alt B.
- Any change to the sing-box engine itself (it natively supports everything here).

## 3. Current state (what blocks this today)

Verified by source investigation (4-agent sweep, adversarially confirmed):

1. **Binary outcome model.** `enum UserTrafficOutcome { TUNNEL, DIRECT }`
   (`RoutingPolicyModels.kt:13`); `RoutingRule` has no node field. `RoutingPolicyEngine` compiles
   `DIRECT→"direct"`, `TUNNEL→"<PROXY>"` sentinel (`:177-179`).
2. **TUNNEL collapses to one tag.** `buildRoute` swaps every `<PROXY>` for a single `finalProxyTag`
   (`SingboxConfigBuilder.kt:358-368,427`).
3. **Multi-node = pick-one selector, and it's test-only.** With >1 active node `buildOutbounds`
   wraps them in one `type:"selector"` tag `"proxy"` (`:158-180`); but the production caller passes
   exactly one node (`TunnelOrchestrator.kt:129 listOf(selection.selectedNode)`), so even that
   branch never runs in production. The per-node `proxy-{id}` tags exist but no rule references them.
4. **Single shared relay leg.** In SERVICES mode every outbound is rewritten to
   `127.0.0.1:localListenPort` (`SingboxConfigBuilder.kt:195-202`); `RuntimeContext.localListenPort`
   is a single `Int` and `TunnelOrchestrator` runs one `forwardId`. So even distinct `proxy-{id}`
   tags would share one upstream — **not distinct network paths**.
5. **No WireGuard anywhere.** `buildProxyOutbound when(node.type){"ss","vmess","vless" else→null}`
   (`:204-292`); `ProxyUriParser` only emits ss/vmess/vless. (`mptcp-wireguard` in `ForwardMode.kt:15`
   is the unrelated bond *transport*, not a client node.)
6. **The Routing screen crashes on open.** `RoutingScreen.kt:47` uses bare `viewModel()` but
   `RoutingViewModel` has an injectable (non-`Application`-only) constructor → `AndroidViewModelFactory`
   cannot build it → `RuntimeException`. Must be fixed first.

sing-box itself natively supports the target: `route.rules` (action `route`) can target *any*
outbound/endpoint tag; ss/vless/vmess/WireGuard-endpoint/selector outbounds coexist; geosite/geoip
`rule_set` + `ip_cidr` + `domain` matches coexist freely. **The limitation is entirely in the
Leikwan client.**

## 4. Approach (chosen)

**Per-rule node target.** Each rule carries an optional `targetNodeId`. The config builder emits every
referenced node as its own addressable outbound (`proxy-{id}`) or WireGuard endpoint, and routes each
rule's matchers to that node's tag. Multiple relay-able nodes each get their own bonded relay leg.

Chosen over Alt B (category→node *group* with auto-failover) and Alt C (fixed profiles) — see §10.

Per the user's two decisions:
- **Rule-model shape:** additive `targetNodeId: Long?` on the existing `RoutingRule`
  (keeps `UserTrafficOutcome` for the direct-vs-tunnel distinction; smaller, safer diff than a sealed
  `RuleTarget`). `targetNodeId == null` ⇒ "use the global-default node" (preserves today's semantics).
- **WireGuard egress:** a WG node is a sing-box **WireGuard endpoint** — a userspace WG tunnel that
  dials the WG server directly, protected by the VpnService — **not** routed through the local bond
  relay. (Bonded-WG already exists separately as the `mptcp-wireguard` forward transport and is not
  conflated here.)

## 5. Architecture & components

```
ProxiesScreen ──enables N nodes──► ProxyNode rows (ss/vless/vmess/wireguard, enabled, forwardId?)
RoutingScreen ──per-row node pick─► UserRoutingRule(kind, matchValue, outcome, targetNodeId?)
                                          │
                            RoutingPolicyEngine.compileRouteRules
                                          │  CompiledRouteRule(matchers, outboundTag = "direct" | "proxy-{id}")
                                          ▼
TunnelOrchestrator ─builds RoutingPolicySnapshot + per-node RuntimeContexts─► SingboxConfigBuilder.build()
                                          │
            ┌─────────────────────────────┼──────────────────────────────────────┐
            ▼                             ▼                                       ▼
   outbounds[]: one proxy-{id}     route.rules: per-rule → proxy-{id}      endpoints[]: wireguard nodes
   per relay-able node             + AUTO safety bypass (slave/LAN→direct, first)   (direct userspace WG)
            │                             │
            ▼                             ▼
   per-node relay leg            route.final → global-default node tag
   (127.0.0.1:port_i, own bond/wstunnel)
```

### 5.1 Routing model (`routing/RoutingPolicyModels.kt`, `RoutingPolicyEngine.kt`)
- Add `targetNodeId: Long?` to `RoutingRule` and `CompiledRouteRule`.
- Add a node-valued **global default** to `RoutingPolicySnapshot` (`globalDefaultNodeId: Long?` +
  the existing `globalDefaultOutcome`).
- `compileRouteRules`: `DIRECT → "direct"`; `TUNNEL + targetNodeId=null → "<DEFAULT_PROXY>"`;
  `TUNNEL + targetNodeId=id → "<NODE:id>"`. (Sentinels resolved in the builder, keeping the engine
  pure-JVM and unit-testable.)
- `decide()`/diagnostics carry the resolved node for explainability ("Video → HKG-HKG1").
- **Precedence is unchanged and remains a hard invariant:** protected paths > runtime reachability >
  user rules > toggles. A node-targeted user rule can never reorder above the safety bypass (§5.5).

### 5.2 Outbound + route emission (`singbox/SingboxConfigBuilder.kt`)
- `buildOutbounds`: emit one `proxy-{id}` per referenced node (already exists at `:161-166`); **stop
  collapsing into the single `"proxy"` selector**. Keep a `"proxy"` selector tag ONLY as the
  fallback target for `<DEFAULT_PROXY>`/`route.final` when the global default is "any tunnel".
- Add an `"endpoints"` array to `build()` (alongside inbounds/outbounds/route) for WireGuard nodes.
- `buildProxyOutbound`: add a `"wireguard"` branch (see §5.4).
- `buildRoute`: resolve `<NODE:id> → "proxy-{id}"`, `<DEFAULT_PROXY> → global-default tag`;
  `route.final → global-default node tag` (or `"direct"`).

### 5.3 Per-node bonded relay legs (`runtime/RuntimeContext.kt`, `service/TunnelOrchestrator.kt`, `*ForwardRunner`)
**The load-bearing change (highest risk).** Today: one `localListenPort`, one `forwardId`, one
runner. Target: a **concurrent multi-leg runtime** — each active relay-able node gets its own local
listen-port and its own bond/wstunnel transport, so `proxy-{id} → 127.0.0.1:port_i` is a genuinely
separate bonded upstream.
- `RuntimeContext.localListenPort: Int` → a per-node mapping (e.g. `legs: Map<Long, NodeLeg>` where
  `NodeLeg` holds `listenPort`, `forwardId`, `processPlans`). `buildProxyOutbound` rewrites each
  node's `server/port` to its own `127.0.0.1:port_i`.
- `TunnelOrchestrator` starts N legs concurrently, health-checks each (`VpnStartupMonitor` per leg),
  and tears them down on stop / node removal. A leg that fails to come up disables only its node
  (its rules fall back to the global default with a warning), never the whole tunnel.
- **WireGuard nodes do not get a relay leg** — they are self-contained userspace endpoints (§5.4).
- *This phase requires a focused design spike* (the orchestrator is single-forward today); the plan
  allocates a spike step before implementation.

### 5.4 WireGuard node type (`proxy/ProxyUriParser.kt`, `ui/proxies/ProxyProtocolDraft.kt`, `data/Entities.kt`, builder, UI editor)
- Parse `wg://` / standard WireGuard config → `ProxyNode(type="wireguard", configJson=…)` with
  fields: `private_key`, peer `public_key`, `endpoint` host:port, `allowed_ips`, optional
  `pre_shared_key`, `reserved`, local `address`.
- Emit a sing-box **WireGuard endpoint** (`"type":"wireguard"` in `endpoints[]`, libbox ≥1.11) or the
  **deprecated `wireguard` outbound** for older libbox — **version-gated**. **Prerequisite:** pin the
  bundled `app/libs/libbox.aar` sing-box version (couldn't be read from the stripped `.aar`); the
  endpoint-vs-outbound choice is config-fatal if wrong (startup rejects the config).
- `ProxyProtocolDraft` gains a `WireGuard` variant (parse/serialize/summary); `ProxyListSections` /
  type lists gain `"wireguard" → "WireGuard"`. Subscription fetch must not silently drop `wg` lines.

### 5.5 Automatic safety bypass — slave & LAN IPs (user-invisible, non-overridable)
**Firm requirement.** Every generated config MUST route the following **direct**, emitted **before**
any node-targeted user rule, and **non-overridable** by user rules:
- **Slave/sidecar reachability IPs** — sourced from the runtime (`RuntimeContext`/per-node legs'
  upstream IPs, the existing `bypassHosts` channel + `SafePathRegistry` runtime-reachability entries).
- **LAN / private IP ranges** — `10/8`, `172.16/12`, `192.168/16`, `127/8` (the existing `bypassLan`
  set), always on.

Privacy invariant: **slave IPs are never surfaced to the user** — they are injected into the config's
direct-bypass rules automatically by the runtime/builder and never appear as user rules, never in the
routing UI, and never as raw IPs in the diagnostics card (which already shows abstract labels like
"panel-api, panel-mirror, loopback-control"). This guarantees that even a user rule pinning "all
traffic → node X" can never (a) route slave/relay egress into a tunnel and create a loop, nor (b)
leak/expose the slave IPs. This strengthens the existing `protected paths > runtime reachability >
user rules` precedence into an explicit, tested invariant for the multi-node case.

### 5.6 UI (`ui/routing/RoutingScreen.kt`, `RoutingViewModel.kt`, `data/routing/UserRoutingRuleEntities.kt` + migration)
- **Phase 0 prerequisite:** fix the crash — give `RoutingScreen`'s `viewModel()` a factory that calls
  `RoutingViewModel(app)` directly (keeps the test-used injectable constructor; see §7).
- Each category/country/custom row gains a **node picker**: `Direct` / `Auto (global default)` /
  `<Node name>` for each enabled node. A **global-default node** picker sits with the global default
  outcome.
- Persist `target_node_id: Long?` — new **nullable Room column** on `user_routing_rules` via a new
  migration. Dangling/disabled target (node deleted or off) → fall back to global default + a warning
  (reuse the existing `warnings` pipeline; surfaced in `RoutingDiagnosticsCard`).
- The picker lists only **user nodes** (ss/vless/vmess/wireguard from the Proxies screen) — never any
  slave/relay IP (§5.5).

## 6. Data flow (request → egress)

1. User enables nodes (Proxies) and assigns categories→nodes (Routing). Rules persist with
   `target_node_id`.
2. On VPN start, `TunnelOrchestrator` builds a `RoutingPolicySnapshot` (rules + node targets +
   global default) and, for each relay-able node, a relay leg / `RuntimeContext`; WG nodes need no leg.
3. `SingboxConfigBuilder.build()` emits: outbounds (`proxy-{id}` per relay-able node, rewritten to
   that node's `127.0.0.1:port_i`), `endpoints[]` (WG nodes), `route.rules` (auto safety bypass first,
   then per-rule `rule_set`/`ip_cidr`/`domain` → node tag), `route.final` → global-default tag.
4. libbox loads the config; gVisor TUN captures traffic; each category's flows egress via its assigned
   node (its own bonded leg, or its WG tunnel); slave/LAN traffic always egresses direct.

## 7. Routing-tab crash fix (Phase 0, prerequisite)

`RoutingScreen.kt:47`:
```kotlin
fun RoutingScreen(
    viewModel: RoutingViewModel = viewModel(
        factory = viewModelFactory { initializer { RoutingViewModel(this[APPLICATION_KEY] as Application) } }
    )
) { ... }
```
The lambda calls the constructor directly (defaults resolve — no reflection), so the default
`AndroidViewModelFactory` is bypassed and `RoutingViewModelTest` keeps the 4-arg injectable
constructor unchanged.

## 8. Error handling & safety invariants

- **Loop / leak prevention:** slave + LAN IPs are unconditionally direct, before user rules,
  non-overridable (§5.5). Tested explicitly.
- **Dangling node target:** a rule pointing at a deleted/disabled node falls back to the global
  default with a warning; never drops to a null outbound or crashes.
- **WG version mismatch:** endpoint-vs-outbound is chosen from the detected libbox version; on an
  unsupported/unknown version, WG nodes are emitted in the legacy-outbound form and a startup
  self-check logs a clear error rather than letting libbox reject the whole config silently.
- **Leg startup failure:** a relay leg that fails disables only its node (rules fall back to default),
  never the whole tunnel.
- **Slave-IP privacy:** never surfaced in UI/diagnostics/rules (§5.5).

## 9. Testing strategy

- **Pure-JVM unit tests** (no device): `RoutingPolicyEngineTest` for the new `targetNodeId` compile
  mapping + precedence (safety bypass still wins); `SingboxConfigBuilderTest` for: one `proxy-{id}`
  per node, per-rule `rule_set → node tag`, WG `endpoints[]` shape, `route.final → default tag`, and
  **the safety-bypass invariant** (slave/LAN direct rules present and ordered before any user rule
  even when a rule pins "all → node X").
- **Migration test:** Room 2→3 migration adds `target_node_id` without data loss.
- **On-device QA** (captured-UID curl harness from the gvisor handoff): per category, confirm the
  egress IP matches the assigned node; confirm slave/LAN traffic stays direct; confirm a disabled
  node's category falls back. Required for Phase 3 (multi-leg runtime) and Phase 4 (WG).

## 10. Alternatives considered

- **Alt B — category → node *group* (selector/urltest):** each category maps to a failover group;
  sing-box auto-picks/healthchecks within it. More resilient, but adds group management + health
  policy. Deferred as a follow-on; the `targetNodeId` model is forward-compatible (a future
  `targetGroupId`).
- **Alt C — fixed routing profiles:** predefined bundles. Too rigid for the per-category/per-node
  control the user wants. Rejected.
- **DIRECT-only multi-node** (no relay rework): cheaper, but the user explicitly chose **bonded
  multi-node from the start**, so the per-node relay legs are a committed core phase, not deferred.

## 11. Phased delivery (cheap → expensive; each independently testable)

| Phase | Scope | Risk | Notes |
|------|-------|------|-------|
| **0** | Routing-tab crash fix (§7) | Low | Prerequisite; ~½ day; on-device verify tab opens |
| **1** | Routing model: `targetNodeId` + global-default node + engine compile (§5.1) | Low | Pure JVM, fully unit-tested |
| **2** | Config builder: emit all nodes addressably + per-rule node routing + drop selector-collapse (§5.2); validate end-to-end in DIRECT mode | Low-Med | `SingboxConfigBuilderTest`; proves routing before the relay rework |
| **3** | Per-node bonded relay legs / multi-forward runtime (§5.3) | **High** | Design spike first; device QA; this delivers "bonded multi-node" |
| **4** | WireGuard node type end-to-end (§5.4) | Med | **Pin libbox version first**; endpoint-vs-outbound gated |
| **5** | Per-rule node-picker UI + Room migration + dangling fallback (§5.6) | Med | Migration bugs corrupt rules table — test the migration |

The safety-bypass invariant (§5.5) is implemented/tested in Phase 2 (config) and re-verified on
device in Phase 3.

## 12. Files touched (map)

- `routing/RoutingPolicyModels.kt`, `routing/RoutingPolicyEngine.kt`, `routing/RoutingPolicySnapshot.kt`
- `singbox/SingboxConfigBuilder.kt` (`build`, `buildOutbounds`, `buildProxyOutbound`, `buildRoute`)
- `runtime/RuntimeContext.kt`, `service/TunnelOrchestrator.kt`, `service/*ForwardRunner.kt`,
  `service/VpnStartupMonitor.kt`
- `proxy/ProxyUriParser.kt`, `ui/proxies/ProxyProtocolDraft.kt`, `ui/proxies/ProxyListSections.kt`,
  `data/Entities.kt`
- `ui/routing/RoutingScreen.kt`, `ui/routing/RoutingViewModel.kt`,
  `data/routing/UserRoutingRuleEntities.kt` (+ Room migration), `data/AppDatabase.kt`
- Tests: `SingboxConfigBuilderTest`, `RoutingPolicyEngineTest`, `RoutingViewModelTest`, a migration test.

## 13. Open items / assumptions to confirm at implementation

- **libbox/sing-box version** of `app/libs/libbox.aar` — gates WG endpoint vs outbound (Phase 4
  blocker; pin before coding WG).
- **Node↔forward mapping** for relay legs — confirm during the Phase 3 spike whether each node maps to
  its own forward/bond or several nodes can share a forward.
- **Category catalog growth** — today `RoutingCategoryCatalog` has `video` + `ai`; adding Google/
  social/streaming categories is additive data (curated geosite tags), not an architecture change.

---

# 14. Revision — 2026-05-31 (evening): verification verdict + new requirements

The feature was implemented on `feature/android-multinode-routing` (7 commits, builds, 134 unit
tests pass). A 5-agent adversarial verification produced verdict **`has-correctness-bugs`**. This
section records the verdict and adds three approved follow-on features.

## 14.1 Verification verdict (what's correct vs. broken)

| Phase | Verdict |
|------|---------|
| 0 crash fix | ✅ correct (also confirmed on-device: Routing tab opens) |
| 1 routing model (`targetNodeId`) | ✅ correct |
| 2 config emission + **safety invariant** | ✅ correct — slave/LAN are direct, before user rules, non-overridable; **privacy invariant holds** (picker sources only `proxy_nodes`, slave IPs can never appear) |
| 3 per-node bonded relay legs | ❌ **correct: false — 2 high-severity bugs (below)** |
| 4 WireGuard | ✅ correct — verifier extracted `libbox.so`, confirmed it's sing-box ≥1.12 devel with WireGuard **endpoint** support; emitted form will load (no config-fatal rejection) |
| 5 UI + migration 2→3 | ✅ correct |

**Phase 3 high-severity bugs (must fix — this is the bonded multi-node you specifically chose):**

- **B1 — Bonded (SERVICES) mode does NOT achieve distinct per-node egress.** `startNodeLegs`
  (`TunnelOrchestrator.kt:200-223`) builds every per-node leg from a **single** forward contract
  (`getCachedContract(selectedForward)`); all legs bond to the **same** `bonding.remoteTarget` /
  same `forward.endpoints` (`BondForwardRunner.kt:181-194`), and `buildProxyOutbound` **discards
  `node.originalServer`** in SERVICES mode (`SingboxConfigBuilder.kt:248`). Result: legs are
  locally distinct (own ports/processes) but terminate at the **identical upstream** — "Video→node A,
  AI→node B" only differ by inner ss/vless credentials against the same bond, not different real
  servers. **End-to-end distinct egress works only in DIRECT mode.** The design's core §5.3/§6
  promise is unmet in bonded mode.
- **B2 — One leg failure aborts the whole VPN.** `startNodeLegs` has no per-leg try/catch;
  `ProcessRunner.start` throwing propagates and kills the entire tunnel. Design §5.3/§8 graceful
  per-node degradation (drop the node, re-point its rules to the default, warn) is unimplemented.
  No per-leg health check. A latent trigger: per-node port allocation collides for node ids
  congruent mod 1000 (`listenPortForNode = base+1+(nodeId%1000)`), and a bind failure → B2 → no VPN.

**Lower-severity polish:** runtime dangling-target fallback is **silent** (UI chip exists, but no
warning into the diagnostics/`snapshot.warnings` channel per §5.6/§8); a redundant late LAN-bypass
block (`SingboxConfigBuilder.kt:425-435`) duplicates the protected-path CIDRs (footgun); no
build-pinned libbox version / startup self-check for WG (verified-correct, defensive only); WG
parser doesn't require the interface `address` (UI editor does).

**Conclusion:** ship-blocking for the *bonded* promise = B1 + B2. DIRECT-mode multi-node category
routing already works correctly end-to-end.

## 14.2 Selector on/off master switch + Proxies-mode conflict resolution (approved: master switch, default OFF)

**Problem (verified):** routing rules apply in **both** modes with **no master toggle** and **no
indicator**; the Routing layer silently overrides the Proxies "single selected proxy" (`finalProxyTag`
ignores `selectedProxyId`; per-category pins and the global default-node supersede it). Two screens
both claim authority over egress.

**Design:**
- New `Prefs.routingSelectorEnabled: Boolean` (key `routing_selector_enabled`, **default false**;
  cleared on logout). Surfaced in `RoutingUiState.selectorEnabled` + `RoutingViewModel.setSelectorEnabled`.
- A prominent **"User routing rules" switch** at the top of `RoutingScreen` (under the title). When
  **off**, dim/disable the global-default chips, add-forms, and rule list (editor reads as inactive).
- **Single gate in `TunnelOrchestrator.buildRoutingSnapshot`:** when `!routingSelectorEnabled`, emit
  **no** user rules and force `globalDefault = TUNNEL, globalDefaultNodeId = selectedProxyId` → so
  `route.final = proxy-{selectedProxyId}` and the Proxies-screen selection is **authoritative**.
  (Protected paths / slave / LAN / bypassChina stay.) When **on**, the selected proxy becomes the
  **default node** and category rules override per-category.
- **Cross-screen clarity:** a read-only status row on `ProxiesScreen` ("Routing rules: ON — selected
  proxy may be overridden by Routing" → taps through to Routing), and an active-rule **badge** on the
  Routing bottom-nav item (`AppNavigation.kt`).
- **SERVICES pinned-node guard:** when on in SERVICES mode and a rule's `targetNodeId` resolves to a
  node with no relay leg, surface a warning (don't emit a dangling `proxy-{id}`) — folds into the B1
  fix.

## 14.3 Expanded category catalog + online GitHub rule-pack updater (approved: seed baked-in + GitHub updater together)

**Expanded catalog — 16 presets** (all geosite tags verified present in the live
`SagerNet/sing-geosite@rule-set` branch — 1801 entries set-checked):
`google, youtube, streaming, ai, telegram, twitter, meta, tiktok, microsoft, apple, developer,
gaming, music, finance, speedtest, social, china_direct`. (See the workflow result for exact tag
lists + En/Zh labels.) `china_direct` defaults to **DIRECT**; all others **TUNNEL**. `RoutingCategoryCatalog`
must gain a **label field** (En + Zh) — today it's `Map<String,List<String>>` with no labels.
Caveat: `@cn` tags (`geosite-apple@cn`) are valid filenames — ensure `geositeUrl()` doesn't strip/re-encode `@`,
or drop them (the `geosite-cn`/`geolocation-cn` already cover the bulk).

**Online updater — fill the dormant rule-pack store:** the store (`RulePackManager`,
`RulePackSnapshot` with `sourceLabel`/`integrityState`/`payloadJson`/rollback/24h-freshness) already
exists but is never populated. Add:
1. A **GitHub-hosted pack manifest** (JSON: `{ version, categories: [{key, labelEn, labelZh, outcomeDefault, geositeTags[]}] }`).
2. A **fetcher** (new `RulePackFetcher`) that downloads the manifest from GitHub. **Per the user's
   decision (2026-05-31): the update is fetched while the VPN is connected, so it egresses through
   the active node/tunnel — `raw.githubusercontent.com` is reachable that way and GFW poisoning of
   the bare connection is irrelevant.** So the primary path is a plain OkHttp GET that rides the
   tunnel (the updater is only enabled/auto-triggered when connected); DoH (`net/AppDns`) is kept
   only as a best-effort fallback for a disconnected manual check, and a CDN mirror is optional, not
   required. Parse → validate (`integrityState = VERIFIED`) → `RulePackManager.activate()`.
3. **Bind** the active pack's payload into `RoutingCategoryCatalog` (so presets come from the pack,
   not only `default()`); fall back to the **baked-in 16-preset seed** when no pack / fetch fails / offline.
4. **Updater UI** in Settings: an "Update rules" button + last-updated/version + integrity/staleness,
   with an auto-check (on launch and/or periodic `WorkManager`), and **rollback** (the store already
   keeps `previous`).

## 14.4 Simplified-Chinese localization (approved: full + in-app language toggle, default Chinese)

Current state: **no externalization** (~150–200 hardcoded English literals across ~16 UI files +
ViewModels + the VPN notification; only `app_name` in `strings.xml`; no locale resources). Plan:
1. **Externalize** every user-facing literal → `res/values/strings.xml` (base) and replace with
   `stringResource(...)` (Compose), `context.getString(...)` (ViewModels), `getString(...)` (notification).
2. Add **`res/values-zh-rCN/strings.xml`** with Simplified-Chinese translations (incl. the category
   labels from §14.3).
3. **In-app language toggle** in `SettingsScreen` via `AppCompatDelegate.setApplicationLocales` +
   `res/xml/locales_config.xml` (en, zh-CN) + `android:localeConfig` + the `androidx.appcompat`
   dependency. **Default to Simplified Chinese** for new installs (users are zh-CN); English available
   via the toggle. (~3–4 dev-days incl. translation.)

## 14.5 Country-rule ISO-code search/autocomplete (new requirement 2026-05-31)

Today "Add country rule" is a raw **ISO code** text field (`RoutingScreen.kt`) — almost no one knows
every country's 2-letter code. Replace it with a **searchable country picker**: the user types a
country **name** (English or Chinese) and gets autocomplete suggestions resolving to the ISO-3166
alpha-2 code that the engine needs (`geoip-<cc>`).
- Built-in **country table** (`~250` entries: alpha-2 code + English name + Simplified-Chinese name),
  bundled in the app (a small static resource/Kotlin map; not online — it's stable). The zh names
  come from §14.4 localization.
- UI: a typeahead `TextField` + filtered dropdown (match on code, En name, or Zh name,
  case/diacritic-insensitive). Selecting a suggestion sets `matchValue` to the alpha-2 code; the chip
  then shows the friendly name (e.g. "日本 (JP)"). Raw-code entry still accepted for power users.
- No model change — `RoutingRule.matchValue` stays the alpha-2 code; this is purely an input affordance.

## 14.6 GeoIP pack online update, shared with "Bypass China IP/Domain" (new requirement 2026-05-31)

The country traffic selector resolves each rule to a `geoip-<cc>` rule-set
(`RoutingPolicyEngine.geoipTag/geoipUrl` → `SagerNet/sing-geoip@rule-set`). Make the **geoip data
online-updatable** alongside the geosite category packs, and **share the updated `geoip-cn`** with the
existing **Settings → "Bypass China IP/Domain"** toggle so both use one current dataset.

- The rule-pack store already has **two version slots** — `countryPackVersion` (geoip) **and**
  `categoryPackVersion` (geosite) (`RulePackSnapshotEntity`). The §14.3 updater populates **both**:
  the manifest carries a geoip pack section (versioned set of `geoip-<cc>` refs incl. `geoip-cn`) and a
  geosite section.
- **Single source of truth:** the active pack's `geoip-cn` (and `geosite-cn`) refs feed BOTH (a) the
  country routing rules and (b) the `bypassChina` block in `buildRoute`/`buildDns`
  (`SingboxConfigBuilder.kt` ~386-388, 440-443, which today hardcode the SagerNet `.srs` URLs). After
  this, the Bypass-China toggle emits the pack's current `geoip-cn`/`geosite-cn` refs (version-pinned,
  updatable) instead of a hardcoded URL — so "Update rules" refreshes China-bypass accuracy too.
- Fetched over the tunnel like §14.3; baked-in seed (current SagerNet refs) as offline fallback.
- Note: sing-box downloads each referenced `.srs` itself at runtime; "updating the IP lib" here means
  updating the **set/version of geoip refs** the config points at (and letting sing-box re-pull),
  not shipping raw IP databases in the APK.

## 14.7 Phasing

TDD steps for §14.1–14.6 are in the companion plan (`...-plan.md`), appended phases:
**Phase 3-FIX** (B1 distinct bonded egress + B2 leg-failure isolation + port-collision + silent-warning),
**Phase 6** (selector master switch + conflict resolution, §14.2),
**Phase 7** (expanded presets + geosite **and** geoip online updater over the tunnel + Bypass-China
sharing, §14.3/§14.6),
**Phase 8** (ISO-code country search §14.5),
**Phase 9** (Simplified-Chinese localization §14.4).
