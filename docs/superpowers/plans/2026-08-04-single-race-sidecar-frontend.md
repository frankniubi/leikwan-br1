# Single/Race Sidecar Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route gated `current_mode` single/race forwards through the existing shared-WG bond sidecar data plane without changing their user-visible selection semantics.

**Architecture:** The panel marks eligible forwards with `bonding.frontend_mode`, publishes the existing bond/plain-TCP slave runtime, and keeps the sidecar bond service active. Linux, Windows, and Android reuse their bond frontend but lock single/race to `latency_best`; Rust stops treating local endpoint resets as transport-leg failures.

**Tech Stack:** PHP 8 control plane, Python 3 client/slave agents, .NET 8 desktop client, Kotlin Android client, Rust/Tokio bond runtime, WireGuard, systemd, iperf3.

---

### Task 1: Panel Contract And Slave Publish

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Test: `master/tests/provisioning_single_race_sidecar_frontend_regression_test.php`
- Test: `master/tests/sidecar_single_race_bond_runtime_regression_test.php`

- [ ] **Step 1: Write failing panel contract tests**

Add fixtures for an opted-in `current_mode` point and assert that single/race produce
`sidecar_required=true`, `aggregation_enabled=false`, `publish_bond_runtime=true`,
`bonding.frontend_mode`, endpoint ingress tokens, and a static WG relay target ending in the
existing bond port. Assert the same fixture with the point flag absent remains direct.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php master/tests/provisioning_single_race_sidecar_frontend_regression_test.php
php master/tests/sidecar_single_race_bond_runtime_regression_test.php
```

Expected: both fail because `frontend_mode` and gated sidecar inclusion do not exist.

- [ ] **Step 3: Implement the gated contract**

Add one shared predicate in `ProvisioningService`:

```php
public function usesSingleRaceSidecarFrontend(array $forward): bool
{
    $mode = $this->normalizeClientMode((string) ($forward['client_mode'] ?? 'single'));
    $pointConfig = $this->decodeJsonAssoc($forward['bond_sidecar_point_agent_config_json'] ?? null);
    return in_array($mode, ['single', 'race'], true)
        && (string) ($forward['backend_mode'] ?? '') === 'current_mode'
        && (int) ($forward['bond_sidecar_point_id'] ?? 0) > 0
        && $this->truthyConfigValue($pointConfig['single_race_via_sidecar'] ?? false);
}
```

Use it in both client config and slave task payload generation. Treat it as sidecar-required and
bond-runtime-published without changing `aggregation_enabled`. Add `frontend_mode` and
`strategy_locked` to `buildBondClientConfig()`.

In `SidecarAgentController`, include gated single/race forward IDs in
`bond_runtime.forward_ids` by calling the same predicate with the current point config.

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the two commands from Step 2. Expected: `ok` from both.

### Task 2: Linux Client Frontend Runtime

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Test: `client-agent/tests/test_single_race_sidecar_frontend.py`

- [ ] **Step 1: Write failing Linux runtime tests**

Create single and race contracts containing:

```python
"bonding": {
    "frontend_mode": "race",
    "strategy_locked": True,
    "ingress_mode": "plain_tcp_mux",
    "session_token": "tcp-token",
    "udp_session_token": "udp-token",
    "remote_target": "10.240.0.1:18501",
    "route_key": "route-91",
}
```

Assert single emits one ingress helper, race emits all helpers, both emit `leikwan-bond client`,
neither emits `wstunnel`, `leikwan-tcp-race.py`, or `--strategy-file`, and an ordinary ungated
single still uses the legacy runtime.

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_single_race_sidecar_frontend -v
```

Expected: frontend fixtures still render legacy single/race WSS processes.

- [ ] **Step 3: Reuse the bond renderer for frontend modes**

Resolve `bonding.frontend_mode` before the legacy mode branches. For `single`, slice endpoints to
one. For `single|race`, enter the bond branch but omit strategy-file creation and CLI arguments,
leaving `--default-strategy latency_best`. Keep the top-level mode for reporting.

- [ ] **Step 4: Run focused and adjacent tests**

Run:

```bash
python -m unittest client-agent.tests.test_single_race_sidecar_frontend client-agent.tests.test_tcp_race_helper client-agent.tests.test_mptcp_bond_regressions -v
```

Expected: all pass.

### Task 3: Windows Desktop Frontend Runtime

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

- [ ] **Step 1: Write failing desktop tests**

Deserialize `frontend_mode=single|race`, assert `ForwardRuntimeManager` dispatches to the bond
frontend, single retains one endpoint, race retains all endpoints, and generated bond commands do
not include a mutable strategy file.

- [ ] **Step 2: Run the tests and verify RED**

Run:

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~BondRuntimeBuilderTests|FullyQualifiedName~ForwardRuntimeManagerTests"
```

Expected: the desktop still dispatches by top-level single/race mode.

- [ ] **Step 3: Add desktop frontend dispatch**

Add `FrontendMode` and `StrategyLocked` JSON properties. Add `BuildFrontend()` that calls the
existing bond process graph with one endpoint for single or all endpoints for race, omits the
strategy file when locked, and returns the display mode. Dispatch to it before the legacy mode
switch.

- [ ] **Step 4: Re-run focused desktop tests**

Run the command from Step 2. Expected: all selected tests pass.

### Task 4: Android Frontend Runtime

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilder.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/AndroidEffectiveForwardBuilderTest.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`

- [ ] **Step 1: Write failing Android contract/runtime tests**

Assert an opted-in sidecar single/race contract resolves to the bond runner even when
`aggregation_enabled=false`; single uses one endpoint, race uses all, and both omit
`--strategy-file` while retaining `--default-strategy latency_best`.

- [ ] **Step 2: Run serial Android tests and verify RED**

Run only one Gradle process:

```powershell
leikwan-android\gradlew.bat -p leikwan-android testDebugUnitTest --tests "com.leikwan.bridge.runtime.AndroidEffectiveForwardBuilderTest" --tests "com.leikwan.bridge.runtime.BondForwardRunnerTest"
```

Expected: contracts are rejected or use the legacy single path.

- [ ] **Step 3: Implement Android frontend recognition**

Parse `frontend_mode`/`strategy_locked`, classify these contracts as a usable bond frontend without
requiring `aggregation_enabled=true`, select endpoints by frontend mode, and pass no strategy file
when locked.

- [ ] **Step 4: Re-run serial Android tests**

Run the command from Step 2. Expected: all selected tests pass.

### Task 5: Bond Stream Failure Classification

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`

- [ ] **Step 1: Write a failing Rust regression test**

Add a test that creates a preferred leg and a local stream reset, runs the local-failure handler,
and asserts the leg has no cooldown and no transport-health penalty. Keep existing tests proving
that an actual leg/control failure still changes leg health.

- [ ] **Step 2: Run the Rust test and verify RED**

Run:

```bash
cargo test -p leikwan-bond local_endpoint_reset_does_not_cool_transport_leg --manifest-path wstunnel-main/Cargo.toml
```

Expected: the leg currently enters cooldown.

- [ ] **Step 3: Separate endpoint failures from leg failures**

Refactor the `LocalReadFailed` and `LocalWriteFailed` event branches so they reset/close only the
application stream. Remove calls that suppress/cool the preferred transport leg. Preserve cooldown
for stream-open timeout, retransmit, and actual control-leg closure paths.

Bump the bond runtime version and its CLI version assertion.

- [ ] **Step 4: Run the bond test suite**

Run:

```bash
cargo test -p leikwan-bond --manifest-path wstunnel-main/Cargo.toml
```

Expected: all tests pass.

### Task 6: Artifact And Manifest Refresh

**Files:**
- Modify: `artifacts/wstunnel/leikwan-bond_linux_amd64`
- Modify: `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
- Modify: `artifacts/versions.json`
- Modify: installer/artifact checks only if their current hashes are pinned elsewhere

- [ ] **Step 1: Build a static Linux artifact**

Build under WSL with the musl target:

```bash
cargo build --release --target x86_64-unknown-linux-musl -p leikwan-bond --manifest-path wstunnel-main/Cargo.toml
```

Copy the result to `artifacts/wstunnel/leikwan-bond_linux_amd64`.

- [ ] **Step 2: Verify Debian compatibility**

Run `file`, `ldd`, and the repository's artifact guard. Expected: static executable, `ldd` reports
not dynamically linked, and no `GLIBC_` dependency exists.

- [ ] **Step 3: Build the matching Windows artifact**

Run the repository's existing Windows cargo build and copy the executable to the artifact path.

- [ ] **Step 4: Refresh manifest hashes and focused artifact tests**

Update version, SHA-256, and size in `artifacts/versions.json`, then run the bond/ingress artifact
regression tests.

### Task 7: Full Verification And Gated Live Rollout

**Files:**
- Modify live point configuration only after backups and preflight
- Create a timestamped benchmark result directory on the test client

- [ ] **Step 1: Run all focused suites serially**

Run PHP, Python, Rust, .NET, and Android focused suites from Tasks 1-5. Stop on the first failure.

- [ ] **Step 2: Back up and deploy committed artifacts**

Back up panel files/database and current agent/runtime artifacts. Deploy the panel, slave/client
agents, and bond binaries through the existing artifact/update channels. Do not touch port `443`.

- [ ] **Step 3: Enable only `yt-hk-a`**

Set `single_race_via_sidecar=true` while preserving all existing point config, then confirm the
sidecar still listens only on its production low ports and `lwsp10` still has all peers on one WG
listener.

- [ ] **Step 4: Publish and inspect temporary forwards**

Create TCP single/race/bond forwards to the test endpoint. Confirm single/race slave metadata and
processes target the sidecar bond overlay port, never the final endpoint.

- [ ] **Step 5: Benchmark cold and immediate repeat**

Run the same reverse iperf3 profile for all modes, record JSON, hashes, retransmits, and sidecar WG
per-peer deltas. Repeat each mode immediately to prove no false 1,800-second cooldown.

- [ ] **Step 6: Clean up and verify final state**

Disable/delete temporary forwards and confirm no temporary client/slave listeners remain. Keep
production `18501/18502` services active and verify panel health.

