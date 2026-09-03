# Windows Native Outbound Port Policy and OpenGFW Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an entitlement-gated, integrated Windows native outbound policy that proxies only ports `1000-65535` by default, supports an explicit unrestricted mode, and filters high-confidence FET/Trojan TCP traffic inside the existing Ultra relay.

**Architecture:** The Panel remains the source of truth for plan entitlement, native-forward direction and lease policy. The Windows app emits one sing-box configuration for HTTP/Process/TUN and points its SOCKS outbound at the existing loopback `ultra-client.exe`; the relay enforces the same port policy and embeds only OpenGFW's platform-neutral analyzers. No WinDivert/WFP service or separate filter process is introduced.

**Tech Stack:** PHP 8 application and migrations, Go 1.22 `tools/ultra-client`, .NET 8 WPF/Avalonia desktop core, sing-box JSON route rules, PHPUnit-style PHP scripts, xUnit .NET tests, Go `testing`.

**Spec:** `docs/superpowers/specs/2026-09-03-windows-native-outbound-port-policy-opengfw-design.md`

## Global Constraints

- `master` is the baseline; do not reset, clean, or overwrite unrelated dirty worktree files.
- Native outbound remains invisible unless the assigned plan has `allow_native_outbound=true`.
- `allow_native_outbound_unrestricted` defaults to `false`, is plan-only, and requires an explicit client opt-in.
- Restricted native mode proxies TCP and UDP destination ports `1000-65535`; destination ports `0-999` are direct.
- Unrestricted mode proxies all ports and disables OpenGFW inspection.
- Restricted mode uses high-confidence OpenGFW FET/Trojan TCP positives only; analyzer errors/inconclusive results fail open; UDP is never analyzer-inspected.
- Native target values are server-generated as `127.0.0.1:<generated-port>` and one active native forward is allowed per canonical direction.
- Provider identity and credentials stay in the one-shot lease/relay memory; they are never published in capability or static client config.
- Keep the existing stripped/garbled `ultra-client.exe` release pipeline; this is obfuscation, not a security boundary.
- Do not deploy or push client updates as part of this implementation unless separately requested.

---

### Task 1: Lock the Panel contract and persistence model

**Files:**
- Create: `master/migrations/20260903_add_native_outbound_policy.sql`
- Modify: `master/src/Support/ProvisioningService.php` (`defaultFeatureFlags`, capability helpers, forward persistence)
- Modify: `master/src/Support/UltraMode.php` (`nativeScopeDigest` and policy constants)
- Test: `master/tests/native_outbound_policy_contract_test.php`

**Interfaces:**
- Consumes: existing `service_plans.feature_flags_json`, `forwards` rows, `UltraMode::nativeScopeDigest()`.
- Produces: `allow_native_outbound_unrestricted`, `native_outbound_enabled`, `native_direction_key`, `route_policy` (`restricted`/`unrestricted`), and a versioned native scope digest accepted by all lease consumers.

- [ ] **Step 1: Write failing contract tests.** Assert that `defaultFeatureFlags()` contains both native flags as `false`; plan flags can enable unrestricted only when native is also enabled; user flags cannot elevate either flag; and a digest changes when `route_policy` changes.

```php
$defaults = $provisioning->defaultFeatureFlags();
same(false, $defaults['allow_native_outbound']);
same(false, $defaults['allow_native_outbound_unrestricted']);
trueValue($provisioning->planAllowsNativeOutbound(['plan_feature_flags_json' => json_encode([
    'allow_native_outbound' => true,
])]));
falseValue($provisioning->planAllowsNativeUnrestrictedOutbound([
    'plan_feature_flags_json' => json_encode(['allow_native_outbound_unrestricted' => true]),
]));
same(false, UltraMode::nativeScopeDigest(1, 'cn_to_intl', 'tcp+udp', 'global', 'restricted') ===
    UltraMode::nativeScopeDigest(1, 'cn_to_intl', 'tcp+udp', 'global', 'unrestricted'));
```

- [ ] **Step 2: Run the focused test and verify it fails.**

Run: `php master/tests/native_outbound_policy_contract_test.php`

Expected: FAIL because the new flag, helper, and digest argument do not yet exist.

- [ ] **Step 3: Add the migration and helpers.** Add nullable `native_outbound_enabled TINYINT(1) NOT NULL DEFAULT 0` and `native_direction_key VARCHAR(64) NULL` to `forwards`, plus an index used by the transaction-level active-direction check. Add `allow_native_outbound_unrestricted => false` to defaults. Implement `planAllowsNativeUnrestrictedOutbound()` so it returns true only when both plan JSON flags are true. Change `UltraMode::nativeScopeDigest()` to canonicalize `native-outbound:v2`, append the normalized policy, and keep a separate v1 verifier that rejects unrestricted claims.

- [ ] **Step 4: Run the focused test and verify it passes.**

Run: `php master/tests/native_outbound_policy_contract_test.php`

Expected: PASS with assertions for defaults, non-elevation, and digest separation.

- [ ] **Step 5: Commit the contract slice.**

```bash
git add master/migrations/20260903_add_native_outbound_policy.sql master/src/Support/ProvisioningService.php master/src/Support/UltraMode.php master/tests/native_outbound_policy_contract_test.php
git commit -m "feat(panel): add native outbound route policy entitlement"
```

### Task 2: Enforce native forward creation and publish capability/lease policy

**Files:**
- Modify: `master/src/Support/ForwardLineApiContract.php` (`native_outbound` normalization/presentation)
- Modify: `master/src/Support/ProvisioningService.php` (`saveForward`, capability carrier construction, active-direction guard)
- Modify: `master/src/Controllers/TenantController.php` (add/edit forward form)
- Modify: `master/src/Controllers/ServiceController.php` (feature and mutation responses)
- Modify: `master/src/Controllers/ClientAgentController.php` (lease request validation and response)
- Modify: `master/src/Support/UltraLeaseService.php` (authoritative policy verification)
- Test: `master/tests/native_outbound_forward_mutation_test.php`
- Test: `master/tests/native_outbound_pull_visibility_test.php`

**Interfaces:**
- Consumes: Task 1 fields/helpers and existing Ultra combination direction.
- Produces: `native_outbound` capability with per-carrier `unrestricted_allowed`, `route_policy` in native lease requests/responses, and redacted forward presentation.

- [ ] **Step 1: Write failing tests for normalization, authorization and uniqueness.** Cover: unknown native fields rejected; native create requires an Ultra `tcp+udp` combination and `allow_native_outbound`; caller target host/port are ignored/rejected; generated host is `127.0.0.1`; a second active native row for the same direction returns `native_direction_conflict`; disabled/deleted rows release the key; capability is absent for non-entitled plans; and lease requests with `route_policy=unrestricted` are rejected without both plan flags.

```php
$native = ForwardLineApiContract::normalizeLegacyCreate([
    'forward_route_id' => 42,
    'native_outbound' => true,
    'target_host' => 'attacker.example',
    'target_port' => 443,
    'transport_mode' => 'tcp+udp',
]);
same(true, $native['native_outbound']);
throwsApi(fn() => ForwardLineApiContract::normalizeLegacyCreate(['native_outbound' => 'yes']), 'validation_failed');
```

- [ ] **Step 2: Run the focused tests and verify they fail.**

Run: `php master/tests/native_outbound_forward_mutation_test.php && php master/tests/native_outbound_pull_visibility_test.php`

Expected: FAIL on missing field normalization, generated target and capability policy assertions.

- [ ] **Step 3: Implement the API and form contract.** Accept a boolean `native_outbound` in legacy and structured create/update payloads; preserve it in `presentForward()`. Add the checkbox to the tenant add/edit form, disable target host/port controls when selected, and render the server-generated loopback target. The server remains authoritative even if a disabled HTML control is forged.

- [ ] **Step 4: Implement transactional persistence.** In `saveForward()`, lock the service row, resolve the selected combination, derive its canonical direction, verify plan native entitlement and `tcp+udp`, generate a collision-free target port in `20000-60000`, set host `127.0.0.1`, set `native_outbound_enabled=1` and the direction key, and clear those fields when a native forward is disabled/deleted. Query active rows under the same lock before insert/update and translate the unique/index race into `native_direction_conflict`.

- [ ] **Step 5: Publish per-carrier unrestricted entitlement.** Extend `nativeOutboundCapabilityForAssignedForwards()` with `unrestricted_allowed` per carrier and top-level only for the selected carrier; keep capability `null` when no native carrier is eligible. Include native markers in forward revision calculation without including provider endpoints.

- [ ] **Step 6: Validate and issue the lease policy.** `ClientAgentController::resolveUltraLease()` accepts only `restricted` by default or explicit `unrestricted` when the carrier's plan allows it. Include the policy in the v2 digest and the response. `UltraLeaseService` rechecks direction, policy and digest, rejects old v1 unrestricted claims, and keeps provider selection server-side.

- [ ] **Step 7: Run the focused tests and verify they pass.**

Run: `php master/tests/native_outbound_forward_mutation_test.php && php master/tests/native_outbound_pull_visibility_test.php && php master/tests/native_outbound_plan_entitlement_test.php`

Expected: PASS, including absence of capability for a default plan and no provider identity in JSON.

- [ ] **Step 8: Commit the Panel slice.**

```bash
git add master/src/Support/ForwardLineApiContract.php master/src/Support/ProvisioningService.php master/src/Controllers/TenantController.php master/src/Controllers/ServiceController.php master/src/Controllers/ClientAgentController.php master/src/Support/UltraLeaseService.php master/tests/native_outbound_forward_mutation_test.php master/tests/native_outbound_pull_visibility_test.php
git commit -m "feat(panel): authorize native forwards and route policies"
```

### Task 3: Add the integrated Go relay policy and OpenGFW inspection

**Files:**
- Modify: `tools/ultra-client/go.mod` (local OpenGFW module replacement and analyzer dependency)
- Create: `tools/ultra-client/native_policy.go`
- Create: `tools/ultra-client/native_inspector.go`
- Modify: `tools/ultra-client/main.go` (`Config` policy fields and shared port gate)
- Modify: `tools/ultra-client/native_socks5.go` (TCP inspector and UDP port gate)
- Modify: `tools/ultra-client/lease.go` and `tools/ultra-client/policy.go` (wire validation)
- Test: `tools/ultra-client/native_policy_test.go`
- Test: `tools/ultra-client/native_inspector_test.go`

**Interfaces:**
- Consumes: Task 2 lease fields `route_policy`, `native-outbound:v2` digest, and existing `PolicyStore`/SOCKS runtime.
- Produces: `NativeRoutePolicy` with `Restricted`/`Unrestricted`, `AllowsPort(port)`, `OpenGFWInspector.Inspect(direction, bytes)`, and deterministic relay rejection errors.

- [ ] **Step 1: Write failing Go tests for policy and analyzer behavior.** Assert restricted allows ports 1000 and 65535, rejects 0 and 999, unrestricted allows every valid port, invalid policy values fail closed, FET/Trojan positive fixtures reject, incomplete/ordinary TLS fixtures pass, analyzer errors pass through, and UDP requests never invoke the inspector.

```go
func TestRestrictedNativePortPolicy(t *testing.T) {
    policy := ParseNativeRoutePolicy("restricted")
    if !policy.AllowsPort(999) || policy.AllowsPort(1000) == false || policy.AllowsPort(65535) == false {
        t.Fatal("unexpected restricted port policy")
    }
}
```

- [ ] **Step 2: Run the focused tests and verify they fail.**

Run: `go test ./... -run 'Native(Policy|Inspector)'`

Expected: FAIL because the policy and inspector types are absent.

- [ ] **Step 3: Add the OpenGFW module wiring.** Add a local `replace github.com/apernet/OpenGFW => ../../opengfw` and import only `github.com/apernet/OpenGFW/analyzer` plus `.../analyzer/tcp`; do not import `io/nfqueue` or iptables packages. Keep the Windows build cgo-free.

- [ ] **Step 4: Implement `NativeRoutePolicy`.** Normalize empty policy to restricted, expose `AllowsPort`, and serialize only the minimal policy fields. `restricted` is the only default. `unrestricted` disables both the port gate and inspector only after lease validation has succeeded.

- [ ] **Step 5: Implement the bounded inspector.** Create one analyzer instance per TCP connection, feed client-to-server and server-to-client chunks incrementally, cap buffered bytes at the analyzers' `Limit()`, reject only when FET or Trojan reports `yes`, and return pass-through for `maybe`, exhausted analyzers, short streams, or analyzer errors. Never inspect UDP payloads.

- [ ] **Step 6: Enforce policy in the relay.** Parse `route_policy` from `Config` and lease frames. In `handleTCPConnect`, reject ports below 1000 before dialing in restricted mode, run the inspector before forwarding each bounded prefix, and report `native_policy_port_rejected`/`native_opengfw_blocked` through the existing status writer. In `serveUDP`, apply the same port gate before quota/session setup and leave payloads untouched.

- [ ] **Step 7: Run focused and full Go tests.**

Run: `go test ./... -run 'Native(Policy|Inspector|Socks5)'` then `go test ./...` and `go vet ./...`

Expected: PASS with no Linux NFQUEUE dependency compiled into the Windows target.

- [ ] **Step 8: Commit the relay slice.**

```bash
git add tools/ultra-client/go.mod tools/ultra-client/native_policy.go tools/ultra-client/native_inspector.go tools/ultra-client/main.go tools/ultra-client/native_socks5.go tools/ultra-client/lease.go tools/ultra-client/policy.go tools/ultra-client/native_policy_test.go tools/ultra-client/native_inspector_test.go
git commit -m "feat(ultra): enforce native port policy and opengfw inspection"
```

### Task 4: Propagate policy through Windows core and generated sing-box routes

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundCapability.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundLease.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/NativeOutboundConfigBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConfigBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConnectionService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/NativeOutboundRelayHost.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsProxyConfigBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/NativeOutboundContractTests.cs`

**Interfaces:**
- Consumes: Task 2 capability/lease JSON and Task 3 `route_policy` names.
- Produces: `NativeOutboundLease.RoutePolicy`, `NativeOutboundCapability.UnrestrictedAllowed`, relay config `native_route_policy`, and valid sing-box JSON for HTTP/Process/TUN.

- [ ] **Step 1: Write failing .NET tests.** Assert JSON for restricted mode contains an early route rule with `network=["tcp","udp"]` and `port_range="1:999"` to direct, scoped rules cover `1000:65535`, no outbound has a `network="tcp,udp"` scalar, and unrestricted mode omits the low-port rule and marks relay policy unrestricted.

- [ ] **Step 2: Run focused .NET tests and verify they fail.**

Run: `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~WindowsProxyConfigBuilder|FullyQualifiedName~NativeOutboundContract"`

Expected: FAIL because policy fields and route rules are missing.

- [ ] **Step 3: Extend models and lease digest validation.** Add optional `unrestricted_allowed` to capability/carriers and `RoutePolicy` to lease. Default deserialized policy to restricted. Include policy in `ComputeScopeDigest` using the v2 canonical string and reject unrestricted when the capability does not permit it.

- [ ] **Step 4: Add route policy to builders.** Add a `NativeRoutePolicy` argument to `BuildNative`, `NativeOutboundConfigBuilder.Build`, and `WindowsProxyConnectionService.StartNativeAsync`. Emit the low-port direct rule before `cn_only`/`intl_only`/global rules, limit native route rules to `1000:65535` TCP+UDP, preserve DNS hijack ordering, and omit the gate for unrestricted mode.

- [ ] **Step 5: Pass policy to the relay host.** Extend `INativeOutboundRelayHost.StartAsync` and `NativeOutboundRelayHost` runtime JSON with `native_route_policy`, `native_port_min=1000`, and `native_filter_profile` (`opengfw_tcp` or `off`). Preserve lease refresh and process cleanup behavior.

- [ ] **Step 6: Run focused and full .NET tests.**

Run: `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~WindowsProxyConfigBuilder|FullyQualifiedName~NativeOutboundContract|FullyQualifiedName~WindowsProxyConnectionService"` then `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj`

Expected: PASS and generated configs contain no invalid outbound network selector.

- [ ] **Step 7: Commit the Windows core slice.**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundCapability.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundLease.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/NativeOutboundConfigBuilder.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConfigBuilder.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConnectionService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/NativeOutboundRelayHost.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsProxyConfigBuilderTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/NativeOutboundContractTests.cs
git commit -m "feat(windows): apply native outbound port policy"
```

### Task 5: Add the entitlement-gated Windows GUI state

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelNativeOutboundTests.cs`

**Interfaces:**
- Consumes: Task 4 `NativeOutboundCapability.UnrestrictedAllowed` and `RoutePolicy`.
- Produces: hidden-by-default native card, entitlement-gated unrestricted checkbox, and explicit restricted/unrestricted lease request.

- [ ] **Step 1: Write failing ViewModel tests.** Cover capability absent => native card hidden; capability present but unrestricted false => checkbox hidden/disabled; unrestricted true => checkbox visible but false by default; enabling it changes the lease request to unrestricted; a capability refresh that removes entitlement stops the active session.

- [ ] **Step 2: Run focused tests and verify they fail.**

Run: `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter "FullyQualifiedName~MainViewModelNativeOutbound"`

Expected: FAIL because unrestricted state and bindings are absent.

- [ ] **Step 3: Implement ViewModel state.** Add `_nativeUnrestrictedRoute` (default false), computed visibility/enabled properties based on the selected carrier, reset it on capability/forward changes, and pass the policy to `ResolveNativeOutboundLeaseAsync` and `StartNativeAsync`. Reject local opt-in when the capability no longer allows it.

- [ ] **Step 4: Implement GUI bindings and copy.** Add the checkbox beside the native scope controls, show the existing “Native outbound” card only for an eligible capability, and add localized strings explaining that unrestricted mode proxies all ports without OpenGFW. Keep target fields and unrelated node controls unchanged.

- [ ] **Step 5: Run focused and full app tests.**

Run: `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter "FullyQualifiedName~MainViewModelNativeOutbound"` then `dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj`

Expected: PASS with default-off behavior and no entitlement bypass.

- [ ] **Step 6: Commit the GUI slice.**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelNativeOutboundTests.cs
git commit -m "feat(windows-ui): gate unrestricted native outbound"
```

### Task 6: Cross-layer acceptance, build and artifact checks

**Files:**
- Create: `docs/superpowers/runbooks/2026-09-03-windows-native-outbound-port-policy-acceptance.md`
- Modify: existing build metadata only if a version bump is required by the current release manifest.
- Test: generated sing-box configs and the relevant PHP/Go/.NET suites.

**Interfaces:**
- Consumes: all previous task outputs.
- Produces: reproducible acceptance evidence and a Windows x86_64 test artifact, without upload or update push.

- [ ] **Step 1: Run the full focused matrix.** Execute the PHP tests from Tasks 1-2, `go test ./...` plus `go vet ./...`, and both .NET projects. Record exact commands and exit codes in the runbook.

- [ ] **Step 2: Validate generated sing-box configs.** Generate restricted and unrestricted HTTP/Process/TUN JSON, run the bundled sing-box `check` command, and assert with a JSON script that DNS hijack precedes the port rule, `1:999` is direct only in restricted mode, and all native rules use `1000:65535` plus `network=["tcp","udp"]`.

- [ ] **Step 3: Build the Windows x86_64 test binary.** Build `ultra-client.exe` with `GOOS=windows GOARCH=amd64 CGO_ENABLED=0`, strip symbols using the existing script, and write SHA-256 plus build inputs to an artifact manifest. Do not replace the public installer or push an update.

- [ ] **Step 4: Perform local smoke checks.** Start the relay with a synthetic lease, verify a port-999 SOCKS/TUN request is direct/rejected by the expected layer, verify port-1000 TCP and UDP reach the relay, verify ordinary TLS/HTTP passes the inspector, and verify FET/Trojan fixtures are blocked only in restricted mode.

- [ ] **Step 5: Commit acceptance evidence.**

```bash
git add docs/superpowers/runbooks/2026-09-03-windows-native-outbound-port-policy-acceptance.md
git commit -m "docs: record native outbound port policy acceptance"
```

### Task 7: Produce the Android/MacOS integration handoff

**Files:**
- Create: `docs/handoffs/2026-09-03-native-outbound-port-policy-cross-platform-handoff.md`

**Interfaces:**
- Consumes: final Panel JSON contract, digest examples, route policy semantics, acceptance evidence and artifact hash.
- Produces: a credential-free handoff that Android and MacOS can implement without reverse-engineering the Windows code.

- [ ] **Step 1: Document the wire contract.** Include JSON examples for capability, restricted lease request/response and unrestricted request/response; list required defaults, digest canonicalization, error codes, and provider-identity redaction rules.

- [ ] **Step 2: Document platform behavior.** State that every client must enforce `0-999` direct unless unrestricted is explicitly entitled and selected, use `1000-65535` TCP+UDP for restricted native, skip OpenGFW on UDP, and surface the entitlement only when present. Explain how TUN implementations should place DNS handling before the port gate.

- [ ] **Step 3: Document compatibility and tests.** List old-client behavior, lease refresh/revocation expectations, required fixture tests, sing-box schema checks, Windows artifact hash and the fact that no server upload/update push occurred.

- [ ] **Step 4: Run a credential scan and commit the handoff.**

Run: `rg -n -i "password|secret|token|private_key|authorization" docs/handoffs/2026-09-03-native-outbound-port-policy-cross-platform-handoff.md`

Expected: only field names and redaction instructions, no actual credentials.

```bash
git add docs/handoffs/2026-09-03-native-outbound-port-policy-cross-platform-handoff.md
git commit -m "docs: hand off native outbound policy to mobile clients"
```

## Verification Summary

Before claiming completion, run `git status --short --branch`, confirm only intentional files from this plan are staged/committed, compare `HEAD` with `master`'s upstream, record the exact Windows x86_64 artifact path/hash, and explicitly state that deployment and client update push were not performed.
