# Native Outbound via Ultra Carrier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a plan-gated, invisible-by-default native SOCKS5 outbound backed by an assigned Ultra forward, with full TCP+UDP/TUN and GUI support on Android, Windows, and macOS.

**Architecture:** The Panel remains the authorization boundary. It exposes a non-secret capability in the existing config pull and extends the existing Ultra lease endpoint with `purpose=native_outbound`; the lease reuses Ultra device binding, policy generation, nonce, quota, and TTL but returns a distinct standard-SOCKS5 protocol. Each client adds a managed native mode that creates an ephemeral sing-box outbound and never stores provider credentials. The selected carrier is an ordinary assigned Ultra forward and inherits its `forward_combination_direction`, independent of terminal endpoint availability.

**Tech Stack:** PHP 8+ Panel services/controllers and SQL migrations; C# .NET desktop Core/WPF/Avalonia; Kotlin Android/Retrofit/Jetpack Compose; sing-box JSON runtime; existing PHPUnit-style PHP contract scripts, xUnit, and Gradle tests.

**Spec:** `docs/superpowers/specs/2026-09-01-native-outbound-ultra-carrier-design.md`

## Global Constraints

- `allow_native_outbound` is stored in `service_plans.feature_flags_json`, defaults to `false`, and is plan-only; a user feature flag may never elevate it.
- The client-visible label is `原生出口`; the capability object and mode are omitted when the entitlement is absent.
- The existing `POST /api/client/ultra/lease` remains the only lease endpoint; native items use `purpose=native_outbound` and protocol `leikwan-ultra-native-socks5-v1`.
- Native scope digest is `sha256("native-outbound:v1\n" + forward_id + "\n" + direction + "\n" + transport + "\n" + route_scope)` and replaces the ordinary target digest only for native requests.
- Only `native_socks5_state=verified` candidates may be issued; provider names, source IDs, line IDs, and credentials never enter normal client config, profile stores, logs, or diagnostics.
- Native lease TTL stays within 60-300 seconds, uses a fresh nonce on every issue/renewal, and shares the existing `ultra_client_leases` replay/revocation fence.
- TCP+UDP and TUN must work without an imported user node. Existing China-IP bypass controls remain user-selectable and default off.
- For an international-to-mainland-China carrier, `route_scope` is `cn_only` or `global`, defaults to `global`, and is absent from the GUI for other directions. `cn_only` routes CN destinations native and non-CN destinations direct; `global` routes all native, with bypass-China as an optional CN-direct override.
- Android acceptance requires a QA APK with both `arm64-v8a` and `x86_64` `libbox` and runtime artifacts; a missing ABI is a build failure.
- Android acceptance runs on the temporary local API 34 AVD `LeiKwan_QA_API34` and must produce the redacted evidence listed in `docs/superpowers/runbooks/2026-09-01-native-outbound-acceptance.md`.
- Windows and macOS acceptance uses the already available test machines; no machine provisioning task is added.
- Preserve all unrelated dirty worktree changes. Stage only files belonging to the current task when committing.

---

### Task 0: Establish the implementation baseline before coding

**Files:**
- Create: `docs/handoffs/2026-09-01-native-outbound-baseline-audit.md`
- Read-only inputs: all paths from `git worktree list --porcelain`

**Interfaces:**
- Produces a recorded baseline commit, relevant worktree paths, `ahead`/`behind`
  counts, latest relevant commit timestamps, dirty counts, and the selected
  source path. No source code is changed in this task.

- [ ] **Step 1: Enumerate worktrees and compare both directions**

Run:

```powershell
$root = (Get-Location).Path
$master = (git rev-parse refs/heads/master).Trim()
$worktrees = @(git worktree list --porcelain | Where-Object { $_ -like 'worktree *' } | ForEach-Object { $_.Substring(9) })
foreach ($wt in $worktrees | Where-Object { $_ -match 'leikwan-br1' }) {
    $head = (git -C $wt rev-parse HEAD).Trim()
    $ahead = git -C $root rev-list --count "$master..$head"
    $behind = git -C $root rev-list --count "$head..$master"
    $dirty = @(git -C $wt status --porcelain)
    git -C $wt log -1 --format="%ci|%H|%s"
    "$wt|ahead=$ahead|behind=$behind|dirty=$($dirty.Count)"
}
```

- [ ] **Step 2: Apply the freshness decision rule**

Select `master` unless a worktree's relevant implementation commit is later
than `master` and its scoped diff is reviewed. A branch that is ahead by count
but also behind or timestamp-older is historical divergent WIP. Do not copy
dirty files. Record the decision and the current `master` hash in the handoff.

- [ ] **Step 3: Verify the audit document contains no secrets**

Run `rg -ni "password|token|secret|private_key|credential" docs/handoffs/2026-09-01-native-outbound-baseline-audit.md` and ensure only field names or redaction markers occur.

- [ ] **Step 4: Commit the baseline audit without staging source changes**

```text
git add docs/handoffs/2026-09-01-native-outbound-baseline-audit.md
git commit -m "docs: record native outbound implementation baseline"
```

### Task 1: Add the plan entitlement and inventory schema

**Files:**
- Create: `master/migrations/20260901_add_native_socks5_state_and_plan_flag.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/ProvisioningService.php:defaultFeatureFlags`, `effectiveFeatureFlagsForService`, and service/forward projection helpers
- Modify: `master/src/Controllers/AdminController.php:renderFeatureCheckboxes`
- Modify: `master/src/Support/UltraInventoryService.php` (enum constants only)
- Test: `master/tests/native_outbound_plan_entitlement_test.php`

**Interfaces:**
- Produces `ProvisioningService::planAllowsNativeOutbound(array $service): bool`, which reads only `plan_feature_flags_json` and returns false for malformed or absent JSON.
- Produces `UltraInventoryService`-compatible `native_socks5_state` values: `unknown`, `verified`, `failed`.

- [ ] **Step 1: Write the failing entitlement and migration assertions**

```php
<?php
require __DIR__ . '/../src/Support/ProvisioningService.php';

$defaults = (new ReflectionClass(ProvisioningService::class))
    ->getMethod('defaultFeatureFlags');
$defaults->setAccessible(true);
$flags = $defaults->invoke(new ProvisioningService($db, $config));
if (($flags['allow_native_outbound'] ?? null) !== false) {
    throw new RuntimeException('native outbound must default off');
}
$service = [
    'plan_feature_flags_json' => json_encode(['allow_native_outbound' => false]),
    'user_feature_flags_json' => json_encode(['allow_native_outbound' => true]),
];
if ((new ProvisioningService($db, $config))->planAllowsNativeOutbound($service)) {
    throw new RuntimeException('user flags must not elevate native outbound');
}
```

Also assert the migration adds `native_socks5_state` with default `unknown` and
does not alter the existing `native_udp_state` default.

- [ ] **Step 2: Run the focused test and observe the missing flag/method**

Run: `php master/tests/native_outbound_plan_entitlement_test.php`

Expected: FAIL because `allow_native_outbound` and `planAllowsNativeOutbound`
are not defined.

- [ ] **Step 3: Implement the flag, plan-only helper, and schema migration**

Add this entry to `defaultFeatureFlags()`:

```php
'allow_native_outbound' => false,
```

Implement the helper so user JSON is intentionally ignored:

```php
public function planAllowsNativeOutbound(array $service): bool
{
    $raw = json_decode((string) ($service['plan_feature_flags_json'] ?? ''), true);
    return is_array($raw) && !empty($raw['allow_native_outbound']);
}
```

Add the migration:

```sql
ALTER TABLE ultra_lines
  ADD COLUMN native_socks5_state VARCHAR(16) NOT NULL DEFAULT 'unknown'
  AFTER native_udp_state;
```

Mirror the column in `schema.sql`; make the migration idempotent using the
repository's existing information-schema guard pattern.

- [ ] **Step 4: Add the admin checkbox and run the test**

Add `allow_native_outbound => '原生出口'` to the feature label map. The generic
hidden `0` input and checked `1` input must be emitted by the existing renderer,
so unchecked plans persist false. Run:

```text
php master/tests/native_outbound_plan_entitlement_test.php
php master/tests/feature_flag_layering_regression_test.php
```

Expected: PASS for both, with no changes to existing flag behavior.

- [ ] **Step 5: Commit the isolated panel schema/entitlement change**

```text
git add master/migrations/20260901_add_native_socks5_state_and_plan_flag.sql master/migrations/schema.sql master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/tests/native_outbound_plan_entitlement_test.php
git commit -m "feat(panel): gate native outbound by service plan"
```

### Task 2: Publish the capability and issue native leases

**Files:**
- Modify: `master/src/Support/UltraMode.php`
- Modify: `master/src/Support/UltraInventoryService.php`
- Modify: `master/src/Support/LeigodTokenBroker.php`
- Modify: `master/src/Support/UltraMaterialService.php`
- Modify: `master/src/Support/UltraLeaseService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Test: `master/tests/native_outbound_lease_contract_test.php`
- Test: `master/tests/native_outbound_pull_visibility_test.php`

**Interfaces:**
- `UltraMode::NATIVE_OUTBOUND_PURPOSE = 'native_outbound'`.
- `UltraMode::NATIVE_SOCKS5_PROTOCOL = 'leikwan-ultra-native-socks5-v1'`.
- `UltraMode::nativeScopeDigest(int $forwardId, string $direction, string $transport, string $routeScope): string`.
- `UltraMode::nativeRouteScopesForDirection(string $direction): array` returns `['cn_only', 'global']` for international-to-mainland-China and `['global']` otherwise.
- `UltraLeaseService::issueLease()` accepts `purpose`; omitted purpose preserves the current Ultra protocol.
- `ClientAgentController` returns `native_outbound` only for entitled assigned active Ultra carriers.

- [ ] **Step 1: Add contract tests before implementation**

The tests must send a batch containing both purposes and assert that the native
response has `purpose`, the new protocol, `direction`, and `scope_digest`, while
the existing item still has `leikwan-ultra-policy-v2`. Add negative assertions
for a plan with `allow_native_outbound=false`, a non-Ultra forward, a mismatched
direction digest, a replayed nonce, and a candidate whose
`native_socks5_state` is `unknown`.

- [ ] **Step 2: Run the contract tests to establish failures**

Run: `php master/tests/native_outbound_lease_contract_test.php; php master/tests/native_outbound_pull_visibility_test.php`

Expected: FAIL because the protocol constant, purpose branch, and pull field do
not exist.

- [ ] **Step 3: Add protocol and scope helpers**

Implement in `UltraMode.php`:

```php
public const NATIVE_OUTBOUND_PURPOSE = 'native_outbound';
public const NATIVE_SOCKS5_PROTOCOL = 'leikwan-ultra-native-socks5-v1';

public static function nativeScopeDigest(int $forwardId, string $direction, string $transport, string $routeScope): string
{
    return hash('sha256', implode("\n", [
        'native-outbound:v1', $forwardId,
        strtolower(trim($direction)), strtolower(trim($transport)), strtolower(trim($routeScope)),
    ]));
}
```

Extend inventory normalization to retain only the three allowed SOCKS5 states.

- [ ] **Step 4: Gate and filter candidate resolution**

Thread `purpose` through `UltraLeaseService`'s normalized request and
`UltraMaterialService::serverCandidateForClientToken`. For native purpose,
candidate resolution must reject a line unless its normalized
`native_socks5_state` is `verified`; the existing Ultra path continues to use
`native_udp_state` unchanged. Add `native_socks5_state` to the line metadata
projection used by `LeigodTokenBroker` without exposing it to clients.

In `ClientAgentController::resolveUltraLease`, normalize and validate
`route_scope` against `UltraMode::nativeRouteScopesForDirection`, build the
native policy target as the canonical string
`native-outbound:v1\n<forward_id>\n<direction>\n<transport>\n<route_scope>`, and
compare the request digest to `UltraMode::nativeScopeDigest`. Before calling
`issueLease`, call `planAllowsNativeOutbound($forward)` and return
`native_outbound_not_allowed` on false.

- [ ] **Step 5: Preserve shared replay/expiry behavior and scrub the response**

Add a purpose branch to `UltraLeaseService::issueLease`:

```php
$isNative = ($normalized['purpose'] ?? '') === UltraMode::NATIVE_OUTBOUND_PURPOSE;
$expectedProtocol = $isNative ? UltraMode::NATIVE_SOCKS5_PROTOCOL : UltraMode::POLICY_PROTOCOL;
```

Use the native scope target only for digest validation, retain the same
`consumeNonce`, `markConsumed`, quota, revocation, and 60-300 second TTL path,
and add only these scrubbed fields to a native lease:
`purpose`, `direction`, `scope_digest`, `endpoint`, and `credential`. Never copy
`source_id`, `line_id`, inventory labels, or upstream names.

Add `native_outbound` construction to the pull response. Each carrier entry
must contain only the approved carrier ID, inherited direction, transport,
allowed `route_scopes`, and default scope (`global`); omit the whole object when
the plan gate or active Ultra carrier check fails. Include its stable projection
in `forwardRevision` so a plan toggle or direction change wakes long-poll
clients.

- [ ] **Step 6: Run panel regression tests and commit**

```text
php master/tests/native_outbound_lease_contract_test.php
php master/tests/native_outbound_pull_visibility_test.php
php master/tests/ultra_policy_lease_contract_test.php
php master/tests/ultra_inventory_contract_test.php
git add master/src/Support/UltraMode.php master/src/Support/UltraInventoryService.php master/src/Support/LeigodTokenBroker.php master/src/Support/UltraMaterialService.php master/src/Support/UltraLeaseService.php master/src/Controllers/ClientAgentController.php master/tests/native_outbound_lease_contract_test.php master/tests/native_outbound_pull_visibility_test.php
git commit -m "feat(panel): issue redacted native socks5 leases"
```

### Task 3: Add the verified SOCKS5 inventory update path

**Files:**
- Modify: `master/src/Support/UltraInventoryService.php`
- Modify: `master/src/Controllers/AdminController.php` (inventory display/filter)
- Create: `master/src/Support/NativeSocks5Probe.php`
- Create: `master/cli/verify-native-socks5.php`
- Test: `master/tests/native_socks5_inventory_state_test.php`
- Test: `master/tests/native_socks5_probe_test.php`

**Interfaces:**
- Inventory payload key: `native_socks5_state`.
- State transition helper: `UltraInventoryService::normalizeNativeSocks5State(mixed $value): string`.
- `NativeSocks5Probe::verify(string $host, int $port, string $user, string $password, string $targetHost, int $targetPort): NativeSocks5ProbeResult` performs the two non-destructive probes and is called by `master/cli/verify-native-socks5.php`.
- `NativeSocks5ProbeResult` has `succeeded`, `tcpSucceeded`, `udpSucceeded`, and bounded `errorCode` fields.

- [ ] **Step 1: Test normalization and fail-closed candidate eligibility**

Assert `verified` survives normalization, arbitrary values become `unknown`,
and `failed`/`unknown` never pass the native candidate predicate.

- [ ] **Step 2: Implement normalization, persistence, and admin visibility**

Use the same bounded enum pattern as `native_udp_state`; add the column to
insert/update projections and show the state in the existing Ultra inventory
table/filter. Keep source credentials server-side.

- [ ] **Step 3: Wire the non-destructive verification result**

Implement `NativeSocks5Probe` with the repository's existing SOCKS5 framing
rules: negotiate username/password authentication, issue a TCP `CONNECT` to a
fixed operator-supplied probe host, then issue UDP `ASSOCIATE` and send one DNS
query through the returned UDP relay. The command must write `verified` only
after both probes and write `failed` with a bounded error state otherwise. It
must not print credentials or provider labels.

- [ ] **Step 4: Run and commit**

```text
php master/tests/native_socks5_inventory_state_test.php
php master/tests/ultra_inventory_contract_test.php
php master/tests/native_socks5_probe_test.php
git add master/src/Support/UltraInventoryService.php master/src/Controllers/AdminController.php master/src/Support/NativeSocks5Probe.php master/cli/verify-native-socks5.php master/tests/native_socks5_inventory_state_test.php master/tests/native_socks5_probe_test.php
git commit -m "feat(panel): track verified native socks5 candidates"
```

### Task 4: Add the shared desktop native lease and runtime contract

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundCapability.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/NativeOutboundLease.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/NativeOutboundConfigBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/PullResponse.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConfigBuilder.cs` (delegate native fragments)
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/NativeOutboundContractTests.cs`

**Interfaces:**

```csharp
public sealed class NativeOutboundCapability
{
    [JsonPropertyName("enabled")] public bool Enabled { get; init; }
    [JsonPropertyName("protocol")] public string Protocol { get; init; } = "";
    [JsonPropertyName("lease_protocol")] public string LeaseProtocol { get; init; } = "";
    [JsonPropertyName("transport")] public string Transport { get; init; } = "tcp+udp";
    [JsonPropertyName("forward_ids")] public List<int> ForwardIds { get; init; } = [];
    [JsonPropertyName("default_forward_id")] public int? DefaultForwardId { get; init; }
    [JsonPropertyName("route_scopes")] public List<string> RouteScopes { get; init; } = [];
    [JsonPropertyName("default_route_scope")] public string DefaultRouteScope { get; init; } = "global";
    [JsonPropertyName("forwards")] public List<NativeOutboundForward> Forwards { get; init; } = [];
}

public sealed record NativeOutboundForward(
    [property: JsonPropertyName("forward_id")] int ForwardId,
    [property: JsonPropertyName("direction")] string Direction,
    [property: JsonPropertyName("scope_digest")] string ScopeDigest,
    [property: JsonPropertyName("transport")] string Transport,
    [property: JsonPropertyName("route_scopes")] IReadOnlyList<string> RouteScopes,
    [property: JsonPropertyName("default_route_scope")] string DefaultRouteScope);

public sealed record NativeOutboundConfigFragment(string Json, int LocalPort);

public sealed class NativeOutboundLease : IDisposable
{
    public NativeOutboundLease(
        int forwardId, string leaseId, string policyGeneration,
        DateTimeOffset expiresAt, string transport, string direction,
        string routeScope, string scopeDigest, string endpointHost, int endpointPort,
        byte[] user, byte[] password)
    {
        ForwardId = forwardId; LeaseId = leaseId; PolicyGeneration = policyGeneration;
        ExpiresAt = expiresAt; Transport = transport; Direction = direction;
        RouteScope = routeScope; ScopeDigest = scopeDigest;
        EndpointHost = endpointHost; EndpointPort = endpointPort;
        User = user; Password = password;
    }

    public int ForwardId { get; }
    public string LeaseId { get; }
    public string PolicyGeneration { get; }
    public DateTimeOffset ExpiresAt { get; }
    public string Transport { get; }
    public string Direction { get; }
    public string RouteScope { get; }
    public string ScopeDigest { get; }
    public string EndpointHost { get; }
    public int EndpointPort { get; }
    public byte[] User { get; private set; }
    public byte[] Password { get; private set; }

    public void Dispose()
    {
        CryptographicOperations.ZeroMemory(User);
        CryptographicOperations.ZeroMemory(Password);
        User = []; Password = [];
    }
}
```

- [ ] **Step 1: Add parser tests for hidden/visible capability and scrubbed lease**

Deserialize a pull response without `native_outbound` and assert `Capability`
is null. Deserialize the approved fixture and assert only endpoint/credential
fields are retained in the lease object; assert serialized logs/models do not
contain `leigod`, `source_id`, or `line_id`.

- [ ] **Step 2: Extend `PullResponse` and `MasterApiClient`**

Add nullable `NativeOutbound` to `PullResponse`. Add
`ResolveNativeOutboundLeaseAsync(ForwardConfig forward, string routeScope, CancellationToken ct)`
that computes the capability scope digest, sends one batch item with
`purpose=native_outbound`, and validates protocol, forward ID, generation,
ticket, digest, direction, route scope, endpoint bounds, credential bounds, and
expiry before returning a disposable lease. Zero request/response byte arrays in
`finally`.

- [ ] **Step 3: Add an ephemeral SOCKS5 outbound builder**

Implement `NativeOutboundConfigBuilder.Build(NativeOutboundLease lease, bool
bypassChina, int localPort): NativeOutboundConfigFragment` as the single shared
config-fragment builder. It reads `lease.RouteScope` and
emits a sing-box `socks` outbound with `version: 5`, username/password, and UDP
enabled plus the direct China rules when requested. Windows and macOS consume
this fragment; it must not call either profile store.

- [ ] **Step 4: Add lease lifecycle hooks to polling/runtime state**

Keep the capability in the in-memory controller snapshot. Refresh a native
lease before `ExpiresAt - 30s`, stop the runtime on an unrecoverable lease error,
and dispose/zero credentials on stop. Do not treat native mode as an Ultra
custom `RuntimeLease` or start `ultra-client` for it.

- [ ] **Step 5: Run Core tests and commit**

```text
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter NativeOutbound
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "Ultra|MasterApiClient"
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/NativeOutboundContractTests.cs
git commit -m "feat(desktop): add ephemeral native outbound lease"
```

### Task 5: Adapt the Windows GUI and managed connection path

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/zh-CN.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConnectionService.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/NativeOutboundViewModelTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsNativeOutboundTests.cs`

**Interfaces:**
- Add an in-memory `WindowsNativeOutboundSession` owned by `MainViewModel`,
  including the selected `NativeRouteScope`.
- Add a nullable `NativeOutboundCapability` property and a `NativeCarrierForwardId` property.
- Add a `NativeRouteScope` property and a `StartNativeProxyAsync(WindowsProxyMode mode, int forwardId, string routeScope, ...)` path
  that calls the Core lease method and never requires `ProxyProfiles`.

- [ ] **Step 1: Test GUI visibility and no-node start behavior**

With `NativeOutboundCapability=null`, assert the mode selector and carrier
selector are collapsed and the existing profile UI remains visible. With an
enabled capability, assert the label is `原生出口`, the default carrier is
selected, and `StartNativeProxyAsync` succeeds with an empty profile list.

- [ ] **Step 2: Add view-model state and commands**

Bind a managed mode enum (`ImportedProfile`, `NativeOutbound`) to the existing
connection page. On pull update, clear `NativeCarrierForwardId` and switch back
to `ImportedProfile` when capability disappears. Normalize the selected scope
against the carrier's `RouteScopes`, default to `DefaultRouteScope`, and map
lease failures to the existing generic connection error text.

- [ ] **Step 3: Add GUI controls and localized resources**

Add a compact mode `ComboBox`/segmented control, a carrier-forward selector,
an international-to-mainland-China-only route-scope selector, and a native
status line in the connection panel. Hide the scope selector for carriers whose
`RouteScopes` contains only `global`. Put the existing bypass-China checkbox
next to the mode controls; disable it in `cn_only` and keep it enabled in
`global`. Do not show endpoint, username, password, source, or provider text.

- [ ] **Step 4: Connect Windows HTTP/TUN/process modes to the ephemeral builder**

Pass the native lease into `WindowsProxyConnectionService` and use the same
process host, TUN stack, local port, DNS, and `ProxyBypassChinaMainland` route
construction as imported profiles. Ensure `StopAsync`, startup failure, and
lease expiry delete the temporary config and zero the lease.

- [ ] **Step 5: Run Windows tests and commit**

```text
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter WindowsNativeOutbound
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter NativeOutbound
git add LeikwanBridge.Desktop/src/LeikwanBridge.App LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsProxyConnectionService.cs LeikwanBridge.Desktop/tests
git commit -m "feat(windows): expose managed native outbound mode"
```

### Task 6: Adapt the macOS runtime and GUI

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacConnectionModels.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacConnectionService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacConnectionConfigBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/ViewModels/MainWindowViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Views/MainWindow.axaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Resources/*`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/Runtime/NativeOutboundTests.cs`

**Interfaces:**
- Extend `MacConnectionMode` with `NativeOutbound` while retaining `Proxy` and `Vpn` behavior.
- Add `NativeRouteScope` to the in-memory session and
  `StartNativeAsync(int forwardId, NativeOutboundLease lease, string routeScope,
  bool bypassChina, ...)` to `IMacConnectionService`.
- `MacConnectionProfileStore` remains unchanged.

- [ ] **Step 1: Test SOCKS5 config and mode visibility**

Assert native config emits a `socks` v5 outbound with UDP, uses the lease
credentials only in the returned JSON, and that native mode has no profile ID
requirement. Assert mode is hidden when capability is null.

- [ ] **Step 2: Implement macOS ephemeral config and lifecycle**

Add the native outbound branch to `MacConnectionConfigBuilder`, call the
existing proxy/VPN process host, and remove the temporary config in every stop,
failure, and expiry path. Preserve the current VPN permission error mapping.

- [ ] **Step 3: Add Avalonia controls and localized labels**

Add the same capability-gated mode selector, carrier selector, route-scope
selector (only for `cn_only`/`global` carriers), status/error line, and
bypass-China toggle used by the Windows experience. Keep imported profile
editing available and leave its persistence untouched; disable bypass-China in
`cn_only`.

- [ ] **Step 4: Run and commit**

```text
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter NativeOutbound
git add LeikwanBridge.Desktop/src/LeikwanBridge.Mac LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests
git commit -m "feat(macos): expose managed native outbound mode"
```

### Task 7: Add Android native mode and lease-backed runtime

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/NativeOutboundModels.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxyConnectionMode.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxySelectionPolicy.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/api/NativeOutboundContractTest.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/NativeOutboundConfigTest.kt`

**Interfaces:**

```kotlin
enum class ProxyConnectionMode(val storedValue: String, val label: String) {
    SERVICES("services", "Services"),
    NATIVE("native", "原生出口"),
    DIRECT("direct", "Direct");
}

data class NativeOutboundCapability(
    val enabled: Boolean,
    val leaseProtocol: String,
    val transport: String,
    val forwardIds: List<Long>,
    val defaultForwardId: Long?,
    val routeScopes: List<String>,
    val defaultRouteScope: String,
    val forwards: List<NativeOutboundForward>,
)

data class NativeOutboundForward(
    val forwardId: Long,
    val direction: String,
    val scopeDigest: String,
    val transport: String,
    val routeScopes: List<String>,
    val defaultRouteScope: String,
)

data class NativeOutboundLease(
    val forwardId: Long,
    val leaseId: String,
    val policyGeneration: String,
    val expiresAt: Instant,
    val transport: String,
    val direction: String,
    val routeScope: String,
    val scopeDigest: String,
    val endpointHost: String,
    val endpointPort: Int,
    val user: ByteArray,
    val password: ByteArray,
) : AutoCloseable
```

- [ ] **Step 1: Add parser and digest tests**

Parse the optional pull object, compute the exact native scope digest, reject a
wrong protocol/digest/forward/route scope/expiry, and assert a missing object
leaves the stored mode at `SERVICES`.

- [ ] **Step 2: Add capability and lease parsing to `PanelRepository`**

Expose `nativeOutboundCapability` from the pull catalog. Add
`resolveNativeOutboundLease(forwardId, routeScope)` that sends the existing batch
request with `purpose` and the selected `route_scope`, validates the new
protocol, direction, and scope, copies credentials to mutable byte arrays, and
wipes them on parse failure or caller cleanup.

- [ ] **Step 3: Add native mode to selection and orchestrator**

For `NATIVE`, require a capability-selected carrier forward but do not inspect
`proxyNodes` or require `selectedProxyId`. Resolve the lease, create a temporary
SOCKS5 `ProxyNode`/outbound descriptor, and pass it to `SingboxConfigBuilder`.
Do not execute `UltraForwardRunner` or start `ultra-client` in this branch.
Refresh the lease before expiry and stop/clear mode on terminal errors. Validate
the selected scope against the carrier, defaulting to `global`.

- [ ] **Step 4: Reuse TUN and bypass-China route construction**

Make the native outbound the `route.final` proxy tag for native mode. For
`cn_only`, add native rules only for `geoip-cn`/`geosite-cn` and direct fallback
for all other destinations; ignore/disable `Prefs.bypassChina`. For `global`,
keep the existing direct China rules conditional on `Prefs.bypassChina`; do not
change its default. Ensure full-device TUN and protected-connectivity exceptions
still include the lease endpoint.

- [ ] **Step 5: Run Android unit tests and commit**

```text
cd leikwan-android
./gradlew testDebugUnitTest --tests '*NativeOutbound*' --tests '*SingboxConfigBuilder*'
cd ..
git add leikwan-android/app/src/main/java leikwan-android/app/src/test/java
git commit -m "feat(android): add lease-backed native outbound mode"
```

### Task 8: Adapt the Android GUI

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/connections/ConnectionsViewModel.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/connections/ConnectionsScreen.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesViewModel.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt` only if layout placement is needed
- Modify: `leikwan-android/app/src/main/res/values/strings.xml`
- Modify: `leikwan-android/app/src/main/res/values-zh-rCN/strings.xml`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/NativeOutboundVisibilityTest.kt`

**Interfaces:**
- `ConnectionsUiState` gains nullable `nativeCapability`, `nativeCarrierForwardId`, and `nativeError`.
- `ConnectionsViewModel` exposes `selectNativeCarrier(Long)` and `setConnectionMode(ProxyConnectionMode)`.

- [ ] **Step 1: Test hidden-by-default and no-node states**

Render the connection state with no capability and assert no `原生出口` option
exists. Render an enabled capability with zero nodes and assert the option,
carrier selector, start action, and generic error state are present.

- [ ] **Step 2: Add capability-gated mode controls**

Add a segmented mode control or compact menu to the connections surface. Show
`原生出口` only when `nativeCapability?.enabled == true`; default the carrier
to `defaultForwardId`, allow changing among `forwards`, and reset to `SERVICES`
when the capability disappears. For an `intl_to_cn` carrier show
`仅中国大陆`/`全局` and default to `global`; omit the selector for other
directions. Keep node import/pairing cards visible for the other modes and avoid
showing a fake empty-node warning in native mode.

- [ ] **Step 3: Add status, error, and bypass affordances**

Show the selected carrier direction as non-secret status text, show a generic
unavailable message on lease failure, and keep the existing `绕过中国` switch
available only in `global`; disable or hide it in `cn_only`. Do not add endpoint
or credential text to any composable.

- [ ] **Step 4: Run GUI/unit tests and commit**

```text
cd leikwan-android
./gradlew testDebugUnitTest --tests '*NativeOutboundVisibility*'
cd ..
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui leikwan-android/app/src/main/res leikwan-android/app/src/test/java
git commit -m "feat(android): show entitlement-gated native outbound UI"
```

### Task 9: Produce Android dual-ABI artifacts and run the temporary VM gate

**Files:**
- Modify: `scripts/build-ultra-android.ps1`
- Modify: `scripts/build-nekobox-libcore.ps1`
- Modify: `leikwan-android/app/build.gradle.kts`
- Create: `scripts/verify-android-native-artifacts.ps1`
- Create: `scripts/qa-android-native-outbound.ps1`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RuntimeBinaryInstallerTest.kt`

**Interfaces:**
- `build-ultra-android.ps1 -Architectures arm64,amd64` writes both executable
  assets and both `libultra_client.so` ABI copies, and validates ELF machines
  183 (`arm64`) and 62 (`x86_64`).
- `build-nekobox-libcore.ps1 -Targets 'android/arm64,android/amd64'` writes an
  AAR containing `jni/arm64-v8a/libbox.so` and `jni/x86_64/libbox.so`.
- `verify-android-native-artifacts.ps1 -Apk $apk` exits non-zero if any
  required ABI or runtime asset is missing or has the wrong ELF machine.
- `qa-android-native-outbound.ps1 -Apk $apk -AvdName LeiKwan_QA_API34`
  starts a non-persistent emulator, runs connected tests, installs the APK,
  waits for boot, and always stops the emulator.

- [ ] **Step 1: Write artifact and VM harness tests first**

Assert the artifact verifier accepts a fixture containing both ABI directories,
rejects an arm64-only AAR/APK, and maps ELF `e_machine=62` to `x86_64`. Assert
the VM harness targets only `emulator-5556`, waits for `sys.boot_completed=1`,
and executes cleanup when installation or tests fail.

- [ ] **Step 2: Run the focused tests before implementation**

```text
cd leikwan-android
./gradlew testDebugUnitTest --tests '*RuntimeBinaryInstallerTest*'
cd ..
pwsh -File scripts/verify-android-native-artifacts.ps1 -Apk artifacts/android/missing.apk
```

Expected: the Kotlin test or verifier fails because x86_64 artifacts and the
verification script do not yet exist.

- [ ] **Step 3: Make the Android build emit both ABIs**

Update `build-ultra-android.ps1` to iterate `arm64`/`amd64`, set
`GOARCH` accordingly, copy to `app/src/main/assets/runtime/arm64-v8a` and
`runtime/x86_64`, and copy matching `.so` files to `app/src/main/jniLibs`.
Keep the reproducibility check per architecture and reject a wrong ELF header.

Update `build-nekobox-libcore.ps1` to use `%LOCALAPPDATA%\\Android\\Sdk` (or an
explicit `ANDROID_HOME`) and invoke the checked-in gomobile command with:

```text
gomobile-matsuri bind -v -androidapi 21 -target=android/arm64,android/amd64 -trimpath -ldflags="-s -w" -tags="with_conntrack,with_gvisor,with_quic,with_wireguard,with_utls,with_clash_api" .
```

Fail if `libbox.aar` does not contain both `jni/` slices. In
`build.gradle.kts`, enable `x86_64` when `qatest=true` or
`androidX86=true`; keep the existing arm64 production default unless the
release build explicitly opts into the second ABI.

- [ ] **Step 4: Add artifact verification and the non-persistent VM harness**

`verify-android-native-artifacts.ps1` must inspect the APK/AAR zip entries,
validate both ELF machines, print only paths/hashes, and return exit code 1 on
missing or mismatched artifacts. `qa-android-native-outbound.ps1` must launch:

```text
emulator.exe -avd LeiKwan_QA_API34 -port 5556 -read-only -no-snapshot-load -no-snapshot-save -no-boot-anim -no-window
```

It waits for `emulator-5556`, checks `ro.product.cpu.abilist` includes
`x86_64`, installs the QA APK, runs `:app:connectedDebugAndroidTest`, writes
redacted UI/log/VPN evidence, and kills the instance in `finally`. No existing
AVD data is wiped or modified.

- [ ] **Step 5: Run the complete Android VM acceptance**

```powershell
.\\scripts\\build-ultra-android.ps1 -Version 2026.09.01.1 -Architectures arm64,amd64
.\\scripts\\build-nekobox-libcore.ps1 -Targets 'android/arm64,android/amd64'
cd leikwan-android
.\\gradlew.bat :app:assembleDebug -Pqatest=true -PandroidX86=true
cd ..
.\\scripts\\verify-android-native-artifacts.ps1 -Apk leikwan-android/app/build/outputs/apk/debug/app-debug.apk
.\\scripts\\qa-android-native-outbound.ps1 -Apk leikwan-android/app/build/outputs/apk/debug/app-debug.apk -AvdName LeiKwan_QA_API34
```

The harness is not a substitute for the functional native-mode checks: use the
runbook's entitled test client to verify no-node startup, TUN, TCP, UDP,
bypass-China, renewal, revocation, and credential cleanup on the same running
VM. Record every pass/fail row and evidence filename.

- [ ] **Step 6: Commit only the build/harness files and tests**

```text
git add scripts/build-ultra-android.ps1 scripts/build-nekobox-libcore.ps1 scripts/verify-android-native-artifacts.ps1 scripts/qa-android-native-outbound.ps1 leikwan-android/app/build.gradle.kts leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/RuntimeBinaryInstallerTest.kt
git commit -m "test(android): add dual-ABI VM acceptance gate"
```

### Task 10: Cross-platform regression, documentation, and acceptance evidence

**Files:**
- Modify: `docs/windows-proxy.md` (managed native mode section)
- Create: `docs/handoffs/2026-09-01-native-outbound-implementation-handoff.md`
- Test: existing panel, desktop, and Android suites listed below

- [ ] **Step 1: Run all focused automated suites and the acceptance harness**

```text
php master/tests/native_outbound_plan_entitlement_test.php
php master/tests/native_outbound_lease_contract_test.php
php master/tests/native_outbound_pull_visibility_test.php
php master/tests/native_socks5_inventory_state_test.php
php master/tests/ultra_policy_lease_contract_test.php
php master/tests/native_socks5_probe_test.php
dotnet test LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln --no-restore
cd leikwan-android; ./gradlew testDebugUnitTest; cd ..
pwsh -File scripts/qa-android-native-outbound.ps1 -Apk leikwan-android/app/build/outputs/apk/debug/app-debug.apk -AvdName LeiKwan_QA_API34
```

- [ ] **Step 2: Perform the three-platform manual acceptance**

Use one active `intl_to_cn` Ultra carrier and one plan with the flag enabled;
follow every row in `docs/superpowers/runbooks/2026-09-01-native-outbound-acceptance.md`:

1. Entitled pull contains `native_outbound`; a disallowed pull omits it.
2. Windows and macOS start native mode with zero imported nodes on their
   existing test machines; Android performs the same check on the temporary
   x86_64 VM.
3. TCP and UDP pass through the standard SOCKS5 lease in TUN mode.
4. Bypass-China off tunnels all destinations; on sends CN ranges direct.
5. Lease renewal succeeds before expiry; revocation stops new leases.
6. Search pull JSON, logs, profile stores, and diagnostics for provider/source
   identifiers and confirm none are present.

- [ ] **Step 3: Record known platform limits and Linux follow-up**

Document Windows administrator/TUN permission requirements, macOS VPN consent,
Android `VpnService` behavior, temporary VM limitations, and the Linux follow-up
as contract reuse only.
Do not claim Linux client delivery in this release.

- [ ] **Step 4: Commit documentation and final verification**

```text
git add docs/windows-proxy.md docs/handoffs/2026-09-01-native-outbound-implementation-handoff.md docs/superpowers/runbooks/2026-09-01-native-outbound-acceptance.md
git commit -m "docs: record native outbound rollout and acceptance"
git diff --check master...HEAD
git status --short
```
