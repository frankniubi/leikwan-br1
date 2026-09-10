# LeiKwan Android Routing and Workflow Design

## Summary

Update the root `leikwan-android/` app so it remains faithful to the current LeiKwan workflow while adding a safe, user-visible custom routing layer for Android.

The design keeps the existing production topology:

`client -> selected slave(s) -> sidecar -> target`

It does **not** try to port Linux or Windows network plumbing onto Android. Instead, Android stays responsible for:

- panel login, config pull, and heartbeat;
- Android `VpnService` lifecycle;
- local runtime orchestration for the currently supported Android modes;
- compiling safe routing policy into sing-box/libbox;
- protecting control-plane/bootstrap traffic from user rule mistakes.

The selected v1 direction is a **balanced enhancement** model:

- keep the current Android `single` / `bond` baseline;
- keep routing outcomes for user traffic limited to **LeiKwan tunnel** or **direct bypass**;
- support high-value custom routing dimensions such as **country** and **website category** (for example video sites and AI sites);
- keep all other routing behavior inside a conservative safe subset;
- add explainability and remotely refreshable rule packs, but do not introduce arbitrary outbound graphs or NekoBox-scale routing surface.

## Context and baseline

### Authoritative workflow contract

The current workflow document is `docs/current-workflow-client-slave-sidecar-20260529.md`.

The Android design must preserve these facts:

- production topology is `client -> selected slave(s) -> sidecar -> target`;
- slave-preconnect mode must not turn into a direct client-to-sidecar path;
- the panel is the desired-state authority;
- the client is responsible for consuming the published contract, rendering local runtime, and reporting health/usage;
- bond and MPTCP both still conceptually route through slave ingress and the warm slave-to-sidecar chain, even if Android only exposes a subset of those runtime modes.

### Current Android baseline

The root `leikwan-android/` app is the implementation baseline. It already provides:

- Kotlin/Compose UI;
- encrypted preferences and Room-backed local storage;
- panel API access via Retrofit/OkHttp;
- `LeikwanVpnService` based on sing-box `libbox.aar`;
- local runtime orchestration through `TunnelOrchestrator`;
- Android support for `single` and `bond`;
- intentional hiding of unsupported `mptcp` and `race` forwards.

Key existing files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`

### Relevant prior art

Useful local prior art exists but is not the new source of truth:

- `.worktrees/android-stable-device-identity` contains Android-side device identity header work that should be folded into root Android.
- `.worktrees/leikwan-android-next-inline` contains reference experiments, including NekoBox-inspired runtime ideas, but is not the baseline.
- `docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md` is relevant only at the contract/orchestration layer: effective config building, health gating, signature/no-op apply, and force rebuild semantics.
- `NekoBoxForAndroid-main/` is a reference for Android VPN/routing patterns, not a codebase to copy. Its GPL-3.0 license and much larger protocol/routing surface make direct reuse inappropriate.

## User decisions captured during brainstorming

The approved direction for this spec is:

1. Android v1 should include custom routing rather than deferring it.
2. The high-value routing scope is curated dimensions such as:
   - by country;
   - by website category, for example video sites and AI sites.
3. Everything outside that high-value surface stays inside a conservative safe subset.
4. When a user rule matches in v1, the only allowed outcomes for user traffic are:
   - **LeiKwan tunnel**
   - **direct bypass**
5. The overall architecture direction is the middle path: **balanced enhancement**, not bare-minimum routing and not a NekoBox-style power-user maximalist graph.

## Goals

1. Keep the Android client aligned with the current LeiKwan workflow and topology.
2. Preserve the existing Android `single` / `bond` runtime baseline instead of inventing a parallel transport model.
3. Add safe, user-visible custom routing for curated country and website-category rules.
4. Protect panel, bootstrap, runtime, and control-plane traffic from user rule mistakes.
5. Make routing decisions explainable enough for support and debugging.
6. Allow online refresh of country/category intelligence without turning routing data into a single point of failure.
7. Keep the v1 outcome model simple enough to test and reason about on real Android devices.

## Non-goals

This design intentionally does **not** include:

- Android support for `mptcp` or `race` in v1;
- arbitrary outbound graphs or “send matched traffic to any imported proxy node” behavior;
- a NekoBox-class full routing DSL in v1;
- copying NekoBox code or architecture wholesale;
- Windows/Linux network plumbing parity such as client-managed WireGuard interface orchestration;
- silent fallback into hidden proxy graphs;
- partial or best-effort rule-pack updates that mutate the live routing state unpredictably.

## Proposed design

### 1. Product contract

#### 1.1 System traffic vs user traffic

The design separates traffic into two classes:

##### A. System-protected traffic

This includes traffic the app must preserve to remain alive and manageable, such as:

- panel login, config pull, heartbeat, and related API access;
- runtime bootstrap dependencies;
- rule-pack update traffic;
- protected DNS/bootstrap lookups;
- local app-owned control paths;
- slave/relay/sidecar reachability dependencies that must not be redirected by user policy.

This traffic is **outside the user routing model**. User rules cannot override it. Enforcement must happen in both Android VPN handling and sing-box/libbox route generation.

##### B. User traffic

This is the normal tunneled application traffic that the Android VPN service handles.

For v1, user traffic can resolve to only two outcomes:

- **TUNNEL**: send through the selected LeiKwan forward and preserve the canonical `client -> slave -> sidecar -> target` structure.
- **DIRECT**: bypass the LeiKwan tunnel.

No other runtime outcome is allowed in v1.

##### C. Formal routing state model

To prevent accidental expansion into a third runtime path, the design distinguishes three concepts:

- `UserTrafficOutcome`: `TUNNEL` or `DIRECT`. These are the only two outcomes that may be compiled for ordinary user traffic.
- `ProtectedPathHandling`: system-protected control traffic is not a user-traffic outcome. It is enforced as non-overridable protected direct/bypass behavior in both Android VPN handling and sing-box/libbox route generation.
- `RoutingDecisionReason`: diagnostic state only, for example `SYSTEM_PROTECTED`, `USER_RULE`, `DEFAULT_TUNNEL`, `FALLBACK_TUNNEL`, or `UNSUPPORTED_RULE_REJECTED`.

Only `UserTrafficOutcome` reaches the user-traffic route compiler. Protected-path handling and decision reasons remain policy/diagnostic metadata and must not become separate outbound graphs or third runtime outcomes.

#### 1.2 Balanced enhancement policy

Compared with a minimal routing design, the selected design adds:

- curated high-value rule dimensions;
- remotely refreshable rule packs;
- clear rule precedence;
- visible explainability for why traffic went tunnel, direct, or system-protected.

Compared with a power-user-heavy design, it explicitly avoids:

- arbitrary routing graphs;
- per-rule outbound selection among imported proxy nodes;
- large protocol/plugin expansion;
- best-effort behavior that is hard to reason about on Android.

### 2. Scope of supported routing in v1

The v1 routing surface is intentionally narrow and curated.

#### 2.1 Supported high-value rule types

The primary supported rule types are:

- **country-based routing**;
- **website-category routing**, beginning with high-value categories such as:
  - video sites
  - AI sites

App-level split tunneling is explicitly deferred to a future spec. This v1 design is limited to curated country/category routing layered on top of the existing Android `single` / `bond` workflow baseline.

#### 2.2 Unsupported or deferred rule types

The following are out of scope for v1 and must be rejected, ignored, or downgraded according to the explicit safe-subset matrix below rather than approximated as new routing shapes:

- arbitrary per-destination scripting;
- multi-stage routing chains;
- user-defined outbound graphs;
- “send this category to imported proxy X, that category to imported proxy Y”;
- desktop-style broad routing DSL parity.

In particular, curated country/category rules in this spec may only resolve user traffic to `TUNNEL` or `DIRECT`. They must never select imported proxy nodes, arbitrary outbound graphs, or per-destination proxy targets.

### 3. Architecture overview

The Android app is divided into six responsibility layers.

#### 3.1 Panel contract layer

Responsibilities:

- login;
- config pull;
- heartbeat;
- client identity headers;
- raw panel payload parsing.

Primary existing file:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`

Expected enhancement:

- fold stable device identity/header behavior from `.worktrees/android-stable-device-identity` into the root Android client.

#### 3.2 Forward/runtime layer

Responsibilities:

- normalize raw panel forwards into Android-supported runtime intent;
- filter unsupported modes;
- build the final `single` or `bond` runtime plan;
- keep Android bound to the supported runtime subset.

Primary existing files:

- `.../runtime/ForwardContract.kt`
- `.../runtime/ForwardFilter.kt`
- `.../service/TunnelOrchestrator.kt`
- `.../runtime/SingleForwardRunner.kt`
- `.../runtime/BondForwardRunner.kt`

#### 3.3 Routing policy layer

This is the main new design layer.

Responsibilities:

- define hard-safe protected traffic;
- evaluate user routing rules;
- decide **TUNNEL** vs **DIRECT** for user traffic;
- enforce precedence between protected paths and user policy;
- generate explainable routing outcomes.

#### 3.4 Rule-pack / classification layer

Responsibilities:

- download and cache country/category rule packs;
- validate version and integrity;
- keep the last known-good pack available offline;
- degrade conservatively when rule data is stale, missing, or uncertain.

#### 3.5 Config compiler layer

Responsibilities:

- compile runtime intent + routing policy into sing-box/libbox configuration;
- inject system-protected rules before user rules;
- keep output deterministic enough to debug and test.

Primary existing file to evolve:

- `.../singbox/SingboxConfigBuilder.kt`

#### 3.6 VPN/runtime orchestration layer

Responsibilities:

- start/stop local runtime processes;
- manage startup order;
- ensure local ready checks pass before the VPN advertises success;
- coordinate retries and clean shutdown;
- avoid half-open states.

Primary existing files:

- `.../service/TunnelOrchestrator.kt`
- `.../service/LeikwanVpnService.kt`

### 4. Proposed component boundaries

#### 4.1 `AndroidEffectiveForwardBuilder`

New helper.

Responsibilities:

- take raw panel forward data;
- normalize only the Android-relevant fields;
- reject unsupported runtime modes early;
- merge Android-local constraints and panel health/state into a stable effective forward;
- produce the final runtime-oriented view that downstream components consume.

This plays the Android equivalent of a workflow-parity effective-config layer without importing Windows/Linux network responsibilities.

#### 4.2 `RoutingPolicyEngine`

New core routing component.

Responsibilities:

- own hard-safe precedence rules;
- evaluate curated country/category rules;
- decide **TUNNEL** or **DIRECT** for user traffic;
- reject or downgrade unsupported rule types into the safe subset;
- return structured explainability alongside the decision model.

It must not directly:

- start runtime processes;
- own VPN lifecycle;
- invent new topologies.

#### 4.3 `RulePackManager`

New component.

Responsibilities:

- fetch country/category rule packs from protected update paths;
- validate version/integrity before activation;
- store last-known-good rule packs locally;
- expose current freshness and validation status;
- support atomic swap and rollback on failure.

The design assumes country/category intelligence may be sourced online, but the client must treat it as advisory data rather than a hard dependency for the tunnel to function.

#### 4.4 `SafePathRegistry`

New component.

Responsibilities:

- define the set of destinations and flows that user policy cannot override;
- provide a shared source of truth for the routing engine, config compiler, and UI;
- make “system protected” behavior explicit instead of scattered.

This registry is mandatory for v1 and must include:

- panel/mirror endpoints;
- login/pull/heartbeat endpoints;
- rule-pack source endpoints;
- bootstrap DNS/control paths;
- local loopback/app-owned runtime control paths;
- protected relay/bootstrap dependencies required to keep the selected forward operable;
- the concrete baseline-safe Android workflow paths implied by `docs/current-workflow-client-slave-sidecar-20260529.md`, including panel reachability, bootstrap reachability, and slave/sidecar reachability that must never be captured by user rules.

The registry entries must be enforced in both places:

- Android VPN handling (`VpnService.Builder`-level protection / bypass semantics where applicable);
- sing-box/libbox route generation.

#### 4.5 `RoutingExplainabilityStore`

New component.

Responsibilities:

- persist the last compiled routing summary;
- expose protected routes, active rule packs, and active user rules to the UI;
- record warnings such as:
  - rule ignored;
  - stale rule pack;
  - system-protected override;
  - conservative fallback.

#### 4.6 `CompiledConfigResult`

New structured result from config compilation.

Derived fields:

- final sing-box/libbox JSON;
- active routing summary;
- warnings;
- applied rule-pack version(s);
- protected override summary;
- signature inputs used for the current compiled state.

`CompiledConfigResult` is derived from the authoritative `RoutingPolicySnapshot` plus `RuntimeIntent`. It must not become a second source of truth for routing decisions.

### 5. Proposed data model

#### 5.1 `RuntimeIntent`

This should become the main orchestration input.

Recommended contents:

- selected forward id;
- normalized mode (`single` or `bond`);
- local listen port(s);
- runtime process spec;
- slave/tunnel metadata needed by the runtime layer;
- protected-path metadata;
- routing-policy reference;
- config-signature inputs.

`TunnelOrchestrator` should consume this object rather than rebuilding context from many loosely related pieces.

#### 5.2 `RoutingPolicySnapshot`

Represents the exact routing model compiled for the current session.

Canonical contents:

- hard-safe protected entries;
- user country rules;
- user category rules;
- default user-traffic action;
- conservative fallback action;
- active rule-pack version(s);
- compile timestamp;
- warnings/degradations;
- rule evaluation precedence version.

`RoutingPolicySnapshot` is the single authoritative routing contract for a compile/rebuild cycle. Other routing-related structures are derived from it.

#### 5.3 `RulePackSnapshot`

Represents local rule-pack state.

A `RulePackSnapshot` is pinned into the `RoutingPolicySnapshot` used for a given compile/rebuild cycle. A refreshed pack does not affect a running or already-compiled session until the next explicit compile point.

Recommended contents:

- country pack version;
- website-category pack version;
- source metadata;
- last refresh time;
- integrity/verification state;
- stale flag;
- rollback availability.

### 6. Data flow

#### 6.1 Normal startup flow

1. `PanelRepository` performs login/config pull as needed.
2. `AndroidEffectiveForwardBuilder` converts the raw contract into an Android-effective forward.
3. `RulePackManager` returns the current validated rule-pack snapshot.
4. `RoutingPolicyEngine` combines:
   - hard-safe registry
   - user routing preferences
   - rule-pack snapshot
   and produces `RoutingPolicySnapshot`.
5. `SingboxConfigBuilder` consumes:
   - `RuntimeIntent`
   - `RoutingPolicySnapshot`
   - user DNS/bypass settings
   and returns `CompiledConfigResult`.
6. `TunnelOrchestrator` starts the selected runtime (`single` or `bond`) and verifies local readiness.
7. After runtime readiness is confirmed, `LeikwanVpnService` starts with the compiled config.
8. The active routing summary is saved for explainability and diagnostics.

#### 6.2 Rule-pack refresh flow

Rule-pack refresh must not be allowed to destabilize a healthy live tunnel.

Required behavior:

1. fetch new pack in the background;
2. validate integrity and schema;
3. stage it atomically;
4. keep using the old pack if validation fails;
5. pin the currently active `RulePackSnapshot` into the active `RoutingPolicySnapshot` for the current session;
6. apply the new pack only on the next safe config rebuild or user-initiated refresh.

Hot in-place reconfiguration is not required for the first implementation slice.

#### 6.3 Failure or ambiguity flow

If routing compilation discovers:

- missing rule data;
- invalid rule entries;
- circular route intent;
- unsupported user configuration;
- ambiguous classification;

then the system must:

- keep the hard-safe path intact;
- reduce behavior to the safe subset;
- avoid taking the main tunnel path down unless no safe tunnel can be proven;
- surface the degradation to the user and diagnostics.

### 7. Routing evaluation model

#### 7.1 Precedence order

The precedence model must be explicit and deterministic.

Required order:

1. **System-protected traffic**
2. **Validated user custom rules**
3. **Conservative fallback behavior** when classification, rule expression, or rule-pack state is unsafe or uncertain
4. **Default user traffic action** only when classification is known-safe and no validated user rule matches

This order ensures that user policy cannot override the flows that keep the app alive, and that uncertain inputs cannot accidentally take a stronger default path.

#### 7.2 Outcome restrictions

For user traffic, v1 supports exactly two outcomes:

- `TUNNEL`
- `DIRECT`

If the user requests behavior outside that model, Android must follow a deterministic safe-subset downgrade matrix:

| Input condition | Owner | Result |
|---|---|---|
| Imported proxy node / arbitrary outbound target requested by a routing rule | `RoutingPolicyEngine` | Reject rule and surface warning |
| Unsupported routing dimension or DSL construct | `RoutingPolicyEngine` | Ignore unsupported clause and keep evaluating remaining supported clauses |
| Supported rule with unsafe or uncertain classification result | `RoutingPolicyEngine` | Apply conservative fallback action |
| Ambiguous or circular compiled route graph | `SingboxConfigBuilder` | Collapse to safe compile result and warn |
| Invalid rule-pack entry | `RulePackManager` | Reject entry or pack during validation; keep last known-good pack |
| Missing rule-pack data for a category/country lookup | `RoutingPolicyEngine` | Apply conservative fallback action |
| Unsupported Android runtime mode implied by a rule or config | `AndroidEffectiveForwardBuilder` | Reject unsupported behavior before compile |

The only permitted runtime results after downgrade remain `TUNNEL` or `DIRECT`, and Android must not silently create a new outbound graph.

#### 7.3 Category and country classification

Country/category routing is useful but imperfect.

Therefore the client must assume:

- classification may be stale;
- some domains or services may be misclassified or uncategorized;
- metadata sources may fail or update asynchronously.

If classification is uncertain, the design defaults to conservative behavior rather than aggressive best-effort routing.

For v1, the default user-traffic action must be defined in one place only by the implementation plan and then carried into `RoutingPolicySnapshot`; other components may consume it but must not redefine it locally.

### 8. Rule-pack and classification policy

#### 8.1 Role of rule packs

Rule packs are a maintained knowledge source for:

- country targeting;
- website-category targeting.

They exist so the client does not need to hardcode a brittle, unmaintainable routing taxonomy in app code.

For v1, the rule-pack contract must be explicit:

- source authority: LeiKwan-controlled update endpoints only;
- format: versioned, schema-validated data with separate country/category pack identifiers;
- integrity: each pack must carry a signed manifest or equivalent verifiable integrity metadata accepted only from LeiKwan-controlled sources;
- freshness: the client records fetched-at time and marks a pack stale after a configured freshness window defined by the implementation plan;
- activation: only validated packs may become active;
- rollback: the previous validated pack remains available until a replacement is fully validated and staged.

#### 8.2 Activation rules

A rule pack becomes active only when:

- it passes schema/integrity validation;
- it can be staged atomically;
- it does not invalidate the current safe routing model.

#### 8.3 Failure policy

If the new pack is invalid, stale, or unavailable:

- keep the last known-good pack when possible;
- otherwise drop back to a reduced safe subset;
- never let the absence of category/country data break the ability to log in, pull config, or start the default tunnel path.

### 9. Error handling and degradation

Routing is an enhancement feature, not a fatal dependency. Failures in routing precision must not automatically become failures in app liveliness.

#### 9.1 Critical failure handling

##### `libbox` / sing-box init fails or compiled config is invalid

- abort tunnel startup;
- clean up partial VPN/runtime state;
- preserve saved state and last-known-good data;
- surface a recoverable error.

##### `TunnelOrchestrator` cannot reconcile selected forward/runtime state

- refuse to launch the selected path;
- restore or request a known-good selection;
- re-pull authoritative config before further tunnel action.

##### `LeikwanVpnService` loses permission or foreground-service continuity

- stop routing cleanly;
- preserve app state;
- show a persistent recovery action;
- do not loop forever attempting hidden restarts.

##### bond/slave/endpoint degradation

- quarantine only the failing leg/path where safe;
- preserve the remaining working subset;
- rebuild the full tunnel only when the primary safe path can no longer stay alive.

##### panel contract vs live runtime mismatch

- treat the mismatch as real;
- stop speculative local rebuilds;
- re-pull authoritative config.

##### unsupported routing or imported features

- strip or ignore unsupported behavior;
- reduce to the safe subset;
- preserve the two-outcome contract.

##### circular route graph or recursive VPN behavior

- force protected control-plane bypass;
- collapse to the smallest safe route set;
- preserve panel/bootstrap reachability first.

##### repeated crash/restart loops

- exponential backoff;
- retry cap;
- user-visible fault after cap;
- no infinite flapping.

#### 9.2 Degradation rules

1. If rule-pack data fails, keep the last known-good pack or fall back to the reduced safe subset.
2. If classification is uncertain, use conservative behavior instead of speculative routing.
3. If a rule cannot be safely expressed on Android, turn it into an explicit safe-subset behavior or no-op with warning.
4. If route compilation becomes ambiguous or circular, collapse back to the conservative model instead of expanding into a more complex graph.
5. If runtime startup fails, do not silently switch to an unrelated hidden proxy model.
6. If diagnostics cannot prove a route is safe, choose the safer visible outcome rather than a hidden clever one.

### 10. Testing and release validation

#### 10.1 Unit tests

Must verify:

- config parsing ignores unknown fields safely;
- mode selection remains `single`/`bond` only;
- route precedence is deterministic;
- unsupported rules stay inside the safe subset;
- degradation decisions are conservative for malformed or incomplete inputs.

Representative examples:

- unknown forward field does not alter the selected topology;
- ambiguous route graph resolves to failure or conservative behavior, not synthetic tunnel invention;
- invalid country/category rule entry does not crash compile;
- duplicate start requests and stop-during-backoff have deterministic results.

#### 10.2 Service orchestration tests

Must verify:

- `TunnelOrchestrator` and `LeikwanVpnService` start/stop cleanly;
- libbox start failure cannot leave a half-open VPN;
- foreground-service interruption yields recoverable shutdown;
- process death or permission revocation does not corrupt persisted state.

#### 10.3 Integration tests with mocked endpoints

Must verify:

- `single` and `bond` survive targeted endpoint/leg failures when a safe subset remains;
- contract mismatch forces re-pull before launch;
- startup failure does not invent alternate graphs;
- sidecar/slave startup reachability failures trigger the right bounded degradation behavior.

#### 10.4 Regression tests

Must verify:

- `single` still works without bond assumptions;
- `bond` still uses the supported Android baseline path;
- hidden `mptcp`/`race` behavior does not leak back into UI or runtime;
- unsupported routing options do not change tunnel startup.

#### 10.5 Manual/real-device QA

This is mandatory before claiming the feature is ready.

Minimum coverage:

- login and config pull;
- VPN permission grant/deny/retry;
- start/stop `single`;
- start/stop `bond`;
- app background/foreground transitions;
- process recreation;
- default tunneled behavior;
- at least one country-based rule;
- at least one website-category rule;
- verification that system-protected traffic cannot be overridden by user rules;
- rule-pack update success and failure behavior;
- repeated crash/restart cap behavior.

### 11. Implementation phasing

To keep the work aligned with the approved balanced-enhancement direction and avoid an unbounded implementation slice, execution should be phased as follows:

1. **Phase A: contract and Android baseline cleanup**
   - fold device identity header parity into root Android;
   - introduce `AndroidEffectiveForwardBuilder` and `RuntimeIntent`;
   - keep `single` / `bond` orchestration stable with no new routing semantics.
2. **Phase B: safe routing contract**
   - add `SafePathRegistry`;
   - add `RoutingPolicyEngine` with the two-outcome model and the explicit downgrade matrix;
   - add `RoutingPolicySnapshot` as the single routing source of truth.
3. **Phase C: rule-pack ingestion and explainability**
   - add `RulePackManager` and `RulePackSnapshot`;
   - add `RoutingExplainabilityStore`;
   - keep activation pinned to explicit compile points.
4. **Phase D: config compiler and orchestration integration**
   - evolve `SingboxConfigBuilder` to consume `RuntimeIntent` + `RoutingPolicySnapshot`;
   - return `CompiledConfigResult` as a derived artifact;
   - wire bounded degradation behavior into `TunnelOrchestrator` and `LeikwanVpnService`.

The implementation plan should preserve these phases or an equivalent decomposition.

### 12. Release gates

The design is not implementation-complete unless all of the following are verified:

1. `single` and `bond` startup/stop succeed on representative Android devices.
2. invalid config, missing VPN permission, and service death all end in a clean stopped state with preserved user data.
3. bond-leg failure degrades only the affected path when a safe remaining path still exists.
4. unsupported routing inputs are reduced to the safe subset or explicit bypass behavior, not expanded into arbitrary graphs.
5. the app can recover from a failed tunnel attempt without reinstall or data wipe.
6. diagnostics expose the failure class, chosen degradation action, and final state.
7. VPN bootstrapping is deterministic across cold start, warm start, and process recreation.
8. config and rule-pack refreshes are atomic, validated, and revert cleanly on failure.

## Risks and cautions

1. Country/category routing is only as good as the quality and freshness of rule-pack data.
2. Android lifecycle failures are product-critical; a mostly-working VPN state is worse than a clearly failed one.
3. Over-expanding v1 routing expressiveness would quickly erase the reliability gains of the two-outcome contract.
4. Hard-safe precedence must be absolute. If user rules can reach protected system traffic, the design loses its main safety guarantee.
5. NekoBox is a useful pattern reference, but its architecture and license footprint are too large to treat as a drop-in model.

## Explicit warnings

- Do not silently convert ambiguous paths into clever fallback graphs.
- Do not let bond or sidecar degradation rewrite the topology.
- Do not auto-retry forever after startup failure.
- Do not silently fall back to hidden proxy graphs.
- Do not expose `mptcp` or `race` as Android v1 user-tunable features.
- Do not merge partially validated rule-pack updates into the live routing state.

## Approval state

This spec reflects the approved design direction from brainstorming:

- root `leikwan-android/` remains the implementation baseline;
- the topology remains `client -> selected slave(s) -> sidecar -> target`;
- Android v1 custom routing is included;
- the custom routing surface is curated, not arbitrary;
- user-traffic outcomes remain limited to LeiKwan tunnel vs direct bypass;
- the chosen direction is the balanced-enhancement path, not a minimal-only design and not a NekoBox-style maximalist design.
