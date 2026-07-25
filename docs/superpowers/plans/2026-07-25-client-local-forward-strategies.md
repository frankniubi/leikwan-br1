# Client-Local Forward Strategies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add client-local `Speed max`, `Latency Best`, and `Random` scheduling for each LeiGod Forward while publishing the same service to every eligible Slave and never synchronizing strategy or client latency to the panel.

**Architecture:** Keep one persistent `leikwan-bond` listener and transport session per Forward. The client writes one local strategy file per Forward; the listener reads it when accepting a new TCP connection or UDP flow and stores the normalized strategy on that stream/flow, so existing connections keep their original behavior without a listener restart. The panel publishes the complete one-to-eight eligible Slave set and Sidecar authentication material but has no strategy field, database state, or strategy telemetry.

**Tech Stack:** PHP 8.2 server-rendered control plane, Rust 2021/Tokio bond runtime, Python 3 Linux client agent, .NET 8/WPF Windows client, Kotlin/Compose Android client, PHPUnit-style PHP scripts, pytest, cargo test, dotnet test, Gradle unit tests.

The invariant topology for every task is `client -> Slave -> Sidecar -> target`; no task may add direct client-to-Sidecar connectivity.

---

## File Map

- `master/src/Support/ProvisioningService.php`: publish/reconcile every eligible distinct LeiGod Slave, capped at eight.
- `master/tests/leigod_client_local_strategy_contract_test.php`: guard complete endpoint publication and absence of panel strategy/latency fields.
- `wstunnel-main/leikwan-bond/src/strategy.rs`: normalize strategy names and load a local strategy file with `Latency Best` fallback.
- `wstunnel-main/leikwan-bond/src/main.rs`: expose `--strategy-file` and `--default-strategy` for client mode.
- `wstunnel-main/leikwan-bond/src/session.rs`: bind one strategy to each accepted TCP stream/UDP flow and schedule legs accordingly.
- `wstunnel-main/leikwan-bond/tests/client_strategy_switch.rs`: prove new-connection-only strategy switching and stream survival.
- `client-agent/forward_strategy.py`: atomic Linux per-Forward strategy store and CLI operations.
- `client-agent/install_forward_runtime.py`: pass the local strategy file to TCP/UDP bond processes.
- `client-agent/client_agent.py`: expose local strategy state in `check` only; never add it to panel requests.
- `client-agent/tests/test_forward_strategy.py`: Linux store, CLI, runtime arguments, and no-sync tests.
- `artifacts/client-agent.py`, `artifacts/install-forward-runtime.py`: published mirrors of the tested Linux sources.
- `artifacts/forward-strategy.py`: published mirror of the Linux strategy helper.
- `master/src/Controllers/ArtifactController.php`, `master/src/Bootstrap.php`: serve the Linux helper from a stable artifact route.
- `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`: include the helper in route/provenance checks.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardStrategy.cs`: canonical Windows enum and wire values.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardStrategyStore.cs`: atomic per-panel/client/Forward persistence.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`: reference the strategy-store path in bond arguments without overwriting a saved choice.
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/ForwardViewModel.cs`: selected strategy and save command.
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`: approved descriptive radio rows.
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`, `zh-CN.xaml`: strategy labels and descriptions.
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardStrategyStoreTests.cs`: persistence isolation and fallback.
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`: generated strategy-file flags.
- `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`: local UI selection without API mutation.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardStrategy.kt`: canonical Android strategy values.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/data/ForwardStrategyStore.kt`: encrypted SharedPreferences persistence.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`: local strategy file and runtime flags.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`: per-Forward local selection.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`: approved descriptive radio rows.
- `leikwan-android/app/src/main/res/values/strings.xml`, `values-zh-rCN/strings.xml`: localized labels.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/data/ForwardStrategyStoreTest.kt`: persistence isolation and fallback.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`: runtime flags.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModelTest.kt`: UI state stays local.
- `artifacts/versions.json`: rebuilt bond artifact hashes and version.

### Task 1: Publish the Complete Eligible Slave Set Without Strategy State

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:6388-6470`
- Create: `master/tests/leigod_client_local_strategy_contract_test.php`

- [ ] **Step 1: Write the failing publication contract test**

Create a focused fake-database fixture with six active distinct `rented_node` candidates on one active `leigod_mode` combination whose old `helper_group_cap` is four. Call endpoint reconciliation and config publication, then assert:

```php
$reconcile = new ReflectionMethod($service, 'reconcileForwardEndpoints');
$reconcile->setAccessible(true);
$reconcile->invoke($service, 77);
$endpointNodeIds = array_map('intval', array_column($db->tables['service_endpoints'], 'node_id'));
sort($endpointNodeIds);
assert_same([11, 12, 13, 14, 15, 16], $endpointNodeIds, 'all eligible Slaves must run the Forward');

$config = $service->buildClientConfig(77);
assert_same(6, count($config['endpoints'] ?? []), 'client must receive every eligible Slave');
foreach (['strategy', 'strategy_mode', 'client_strategy', 'client_latency', 'latency_ms_by_node'] as $forbidden) {
    assert_false(array_key_exists($forbidden, $config), 'panel leaked client-local field ' . $forbidden);
}
```

The fixture must also seed a ninth candidate and assert the published set is capped at eight, and seed a duplicate `node_id` row and assert it is emitted once.

- [ ] **Step 2: Run the test and verify the current four-node cap fails**

Run:

```powershell
php master/tests/leigod_client_local_strategy_contract_test.php
```

Expected: FAIL with `all eligible Slaves must run the Forward` because the current combination helper cap truncates the set.

- [ ] **Step 3: Implement LeiGod-only endpoint expansion**

In `reconcileForwardEndpoints`, keep existing legacy behavior outside the active runtime contract. For an active LeiGod bond Forward, request up to eight distinct eligible candidates and deduplicate by positive `node_id` before creating endpoints:

```php
$selectorCap = max(1, min(8, $backendMode === 'leigod_mode' && $clientMode === 'bond'
    ? 8
    : max(1, (int) ($combination['helper_group_cap'] ?? 0))));
$selectedSlaves = $this->pickFastestSlavesForCombination($combinationId, $selectorCap);
$desiredNodesById = [];
foreach ($selectedSlaves as $candidate) {
    if (($candidate['candidate_source'] ?? '') !== 'rented_node') {
        continue;
    }
    $row = is_array($candidate['row'] ?? null) ? $candidate['row'] : [];
    $nodeId = (int) ($row['id'] ?? 0);
    if ($nodeId > 0 && !isset($desiredNodesById[$nodeId])) {
        $desiredNodesById[$nodeId] = $row;
    }
}
$desiredNodes = array_slice(array_values($desiredNodesById), 0, 8);
```

Do not add a strategy field to Forward rows, combination rows, client config JSON, heartbeat JSON, or migrations.

- [ ] **Step 4: Run focused panel tests**

Run:

```powershell
php master/tests/leigod_client_local_strategy_contract_test.php
php master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php
php master/tests/leigod_only_runtime_contract_policy_test.php
php master/tests/forward_combination_static_sidecar_wg_regression_test.php
```

Expected: four `ok` results.

- [ ] **Step 5: Commit the panel contract**

```powershell
git add master/src/Support/ProvisioningService.php master/tests/leigod_client_local_strategy_contract_test.php
git commit -m "feat: publish all eligible leigod slave legs"
```

### Task 2: Add Canonical Strategy Parsing and Local File Loading to Rust

**Files:**
- Create: `wstunnel-main/leikwan-bond/src/strategy.rs`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs:1-160`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:44-66`
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Modify: `wstunnel-main/leikwan-bond/Cargo.lock`

- [ ] **Step 1: Write failing parser and CLI tests**

Add module tests that require the exact wire values and fallback:

```rust
#[test]
fn strategy_parser_accepts_only_canonical_values() {
    assert_eq!(ClientStrategy::parse("speed_max").unwrap(), ClientStrategy::SpeedMax);
    assert_eq!(ClientStrategy::parse("latency_best").unwrap(), ClientStrategy::LatencyBest);
    assert_eq!(ClientStrategy::parse("random").unwrap(), ClientStrategy::Random);
    assert!(ClientStrategy::parse("race").is_err());
}

#[test]
fn missing_or_invalid_strategy_file_uses_latency_best() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("forward-77.strategy");
    assert_eq!(load_strategy(&path, ClientStrategy::LatencyBest), ClientStrategy::LatencyBest);
    std::fs::write(&path, "invalid\n").unwrap();
    assert_eq!(load_strategy(&path, ClientStrategy::LatencyBest), ClientStrategy::LatencyBest);
}
```

Extend the existing `main.rs` CLI test to parse:

```rust
"--strategy-file", "/var/lib/leikwan-client-agent/forward-strategies/77.strategy",
"--default-strategy", "latency_best",
```

- [ ] **Step 2: Run the tests and verify RED**

```powershell
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml strategy_parser -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml client_cli_accepts_strategy_file -- --nocapture
```

Expected: compile failure because `strategy` and the CLI flags do not exist.

- [ ] **Step 3: Implement the strategy module and CLI flags**

Create the canonical type:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ClientStrategy { SpeedMax, LatencyBest, Random }

impl ClientStrategy {
    pub fn parse(value: &str) -> anyhow::Result<Self> {
        match value.trim().to_ascii_lowercase().as_str() {
            "speed_max" => Ok(Self::SpeedMax),
            "latency_best" => Ok(Self::LatencyBest),
            "random" => Ok(Self::Random),
            other => anyhow::bail!("unsupported client strategy {other}"),
        }
    }
}

pub fn load_strategy(path: &std::path::Path, fallback: ClientStrategy) -> ClientStrategy {
    std::fs::read_to_string(path)
        .ok()
        .and_then(|value| ClientStrategy::parse(&value).ok())
        .unwrap_or(fallback)
}
```

Add `strategy_file: Option<PathBuf>` and `default_strategy: ClientStrategy` to `SessionConfig`, set both client CLI fields, and use `None` plus `LatencyBest` for server mode.

Add the test-only temporary-directory dependency and commit its lockfile change in this task:

```toml
[dev-dependencies]
tempfile = "3"
```

- [ ] **Step 4: Run parser and CLI tests**

```powershell
cargo fmt --manifest-path wstunnel-main/leikwan-bond/Cargo.toml
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml strategy_parser -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml client_cli_accepts_strategy_file -- --nocapture
```

Expected: PASS.

- [ ] **Step 5: Commit canonical strategy support**

```powershell
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock wstunnel-main/leikwan-bond/src/strategy.rs wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat: load client-local bond strategies"
```

### Task 3: Bind a Strategy to Every New TCP Stream

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:530-611,946-1010,1595-2055,2580-2805,4200-4400`
- Create: `wstunnel-main/leikwan-bond/tests/client_strategy_switch.rs`

- [ ] **Step 1: Write failing scheduler and switch tests**

Add unit tests with deterministic fake legs:

```rust
#[test]
fn latency_best_never_promotes_a_stream_to_bulk_striping() {
    let mut state = TcpStreamState::new_for_test(ClientStrategy::LatencyBest, Some(0));
    let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    let selected = select_stream_leg_for_strategy(&legs, &mut state, 512 * 1024, 4 * 1024 * 1024, &test_config());
    assert_eq!(selected, Some(0));
}

#[test]
fn random_is_pinned_per_stream_until_its_leg_fails() {
    let mut state = TcpStreamState::new_for_test(ClientStrategy::Random, Some(1));
    let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    assert_eq!(select_stream_leg_for_strategy(&legs, &mut state, 4096, 0, &test_config()), Some(1));
    legs[1].active = false;
    assert_eq!(select_stream_leg_for_strategy(&legs, &mut state, 4096, 0, &test_config()), Some(0));
}
```

The integration test starts a listener with a temporary strategy file, opens connection A under `latency_best`, atomically replaces the file with `random`, opens connection B, and asserts A retains `LatencyBest` while B records `Random` in the local lifecycle snapshot.

- [ ] **Step 2: Run the tests and verify RED**

```powershell
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml latency_best_never_promotes -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml --test client_strategy_switch -- --nocapture
```

Expected: compile failure because stream state has no strategy.

- [ ] **Step 3: Implement per-stream strategy attachment and scheduling**

Change the command and stream state shape:

```rust
enum ClientTransportCommand {
    AttachLocalStream { stream: TcpStream, strategy: ClientStrategy },
}

struct TcpStreamState {
    strategy: ClientStrategy,
    preferred_leg_id: Option<usize>,
    // retain the existing offsets, pending chunks, writer, and lifecycle fields
}
```

At every `listener.accept()`, call `load_strategy` once and send it with the stream. Initialize affinity as follows:

- `SpeedMax`: use the existing primary/interactive affinity and existing bulk scheduler.
- `LatencyBest`: select the best healthy primary and keep all data on it until it becomes ineligible.
- `Random`: choose uniformly from healthy eligible legs once, store that leg, and choose again only after it becomes ineligible.

Use `rand::rngs::StdRng` owned by the transport session and `SliceRandom::choose` over a stable list of eligible leg IDs. Add `rand = "0.8"` to `Cargo.toml` and commit the resulting `Cargo.lock` update in this task.

- [ ] **Step 4: Run focused and existing TCP tests**

```powershell
cargo fmt --manifest-path wstunnel-main/leikwan-bond/Cargo.toml
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml latency_best_never_promotes -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml random_is_pinned -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml --test client_strategy_switch -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml retransmit -- --nocapture
```

Expected: PASS; existing retransmission behavior remains green.

- [ ] **Step 5: Commit per-stream TCP scheduling**

```powershell
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock wstunnel-main/leikwan-bond/src/session.rs wstunnel-main/leikwan-bond/tests/client_strategy_switch.rs
git commit -m "feat: select bond strategy per tcp connection"
```

### Task 4: Apply Strategy Semantics to UDP Flows

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:614-640,3300-3700,4370-4485`
- Modify: `wstunnel-main/leikwan-bond/tests/client_strategy_switch.rs`

- [ ] **Step 1: Write failing UDP flow tests**

Add tests proving `SpeedMax` and `LatencyBest` use the best healthy leg while `Random` pins each source flow independently:

```rust
#[test]
fn udp_random_keeps_one_leg_per_flow() {
    let mut flows = UdpFlowStrategies::default();
    let peer_a: SocketAddr = "127.0.0.1:31001".parse().unwrap();
    let first = flows.leg_for(peer_a, ClientStrategy::Random, &[0, 1], &mut seeded_rng());
    let second = flows.leg_for(peer_a, ClientStrategy::Random, &[0, 1], &mut seeded_rng());
    assert_eq!(first, second);
}

#[test]
fn udp_speed_max_is_primary_only() {
    assert_eq!(select_udp_leg_for_strategy(ClientStrategy::SpeedMax, &[healthy_primary(0), healthy_helper(1)]), Some(0));
}
```

- [ ] **Step 2: Run and verify RED**

```powershell
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml udp_random_keeps_one_leg_per_flow -- --nocapture
```

Expected: compile failure because UDP has no flow strategy table.

- [ ] **Step 3: Implement bounded UDP flow pinning**

Add a map keyed by local peer address with `{strategy, leg_id, last_seen}`. Read the strategy file only when inserting a new flow. Expire entries after the existing UDP idle timeout. For `SpeedMax` and `LatencyBest`, choose the best healthy primary; for `Random`, choose uniformly from healthy eligible legs. If a pinned leg is no longer eligible, replace it from the remaining set.

- [ ] **Step 4: Run UDP and full Rust tests**

```powershell
cargo fmt --manifest-path wstunnel-main/leikwan-bond/Cargo.toml
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml udp_random -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml udp_primary -- --nocapture
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml --all-targets -- --nocapture
```

Expected: PASS.

- [ ] **Step 5: Commit UDP strategy behavior**

```powershell
git add wstunnel-main/leikwan-bond/src/session.rs wstunnel-main/leikwan-bond/tests/client_strategy_switch.rs
git commit -m "feat: pin udp flows to client-selected strategies"
```

### Task 5: Add Linux Local Persistence and CLI

**Files:**
- Create: `client-agent/forward_strategy.py`
- Create: `client-agent/tests/test_forward_strategy.py`
- Modify: `client-agent/install_forward_runtime.py:724-1007`
- Modify: `client-agent/client_agent.py:1-60,429-450,4600-end`
- Modify: `install/install-client-agent.sh`
- Modify: `master/src/Controllers/ArtifactController.php:150-180`
- Modify: `master/src/Bootstrap.php:270-310`
- Modify: `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php:26-32`
- Create: `artifacts/forward-strategy.py`

- [ ] **Step 1: Write failing Linux store, CLI, and render tests**

Test the canonical values, atomic file content, fallback, and generated runtime command:

```python
def test_store_defaults_to_latency_best_and_persists_canonical_value(tmp_path):
    store = ForwardStrategyStore(tmp_path, panel_id="panel-a", client_id=9)
    assert store.get(77) == "latency_best"
    store.set(77, "random")
    assert store.get(77) == "random"
    assert store.runtime_path(77).read_text(encoding="utf-8") == "random\n"

def test_bond_runtime_receives_local_strategy_file(sample_forward, sample_agent_config):
    unit = render_forward(sample_forward, sample_agent_config)
    assert "--strategy-file /var/lib/leikwan-client-agent/forward-strategies/77.strategy" in unit
    assert "--default-strategy latency_best" in unit
```

Add a request-spy test around the agent HTTP helpers and assert no POST/PATCH payload contains `strategy`, `client_strategy`, or per-node latency values.

- [ ] **Step 2: Run and verify RED**

```powershell
python -m pytest client-agent/tests/test_forward_strategy.py -q
```

Expected: import failure for `forward_strategy`.

- [ ] **Step 3: Implement the atomic store, CLI, and runtime flags**

Create `ForwardStrategyStore` with `get`, `set`, and `runtime_path`. Normalize only `speed_max`, `latency_best`, and `random`; invalid or missing values return `latency_best`. Write to a sibling temporary file, flush and `os.fsync`, then `os.replace`.

Expose commands:

```text
leikwan-client-strategy get --forward-id 77
leikwan-client-strategy set --forward-id 77 --strategy random
leikwan-client-strategy list
```

The installer must download `/artifacts/forward-strategy.py`, place the module and wrapper beside the client agent, and verify the downloaded file is non-empty before replacing an existing copy. Add `ArtifactController::forwardStrategyHelper`, route it from `Bootstrap`, and register it as a route-only helper in `LeigodOnlyArtifactSourceBuildMap`. `install_forward_runtime.py` must create a missing runtime file as `latency_best\n` and add both Rust CLI flags to TCP and UDP commands.

- [ ] **Step 4: Run Linux tests**

```powershell
python -m pytest client-agent/tests/test_forward_strategy.py -q
python -m pytest client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_client_agent_bond_endpoint_health.py -q
```

Expected: PASS.

- [ ] **Step 5: Commit Linux client strategy support**

```powershell
git add client-agent/forward_strategy.py client-agent/tests/test_forward_strategy.py client-agent/install_forward_runtime.py client-agent/client_agent.py install/install-client-agent.sh artifacts/forward-strategy.py master/src/Controllers/ArtifactController.php master/src/Bootstrap.php master/src/Support/LeigodOnlyArtifactSourceBuildMap.php
git commit -m "feat: persist forward strategies on linux clients"
```

### Task 6: Add Windows Persistence, Runtime Flags, and Descriptive Radio Rows

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardStrategy.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardStrategyStore.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/ForwardViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/zh-CN.xaml`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardStrategyStoreTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`

- [ ] **Step 1: Write failing Windows tests**

Require tuple isolation and local-only UI mutation:

```csharp
[Fact]
public void MissingChoiceDefaultsToLatencyBest()
{
    using var temp = new TempStateDirectory();
    var store = new ForwardStrategyStore(temp.Path, "panel-a", 9);
    Assert.Equal(ForwardStrategy.LatencyBest, store.Get(77));
}

[Fact]
public void SaveIsIsolatedByPanelClientAndForward()
{
    using var temp = new TempStateDirectory();
    new ForwardStrategyStore(temp.Path, "panel-a", 9).Set(77, ForwardStrategy.Random);
    Assert.Equal(ForwardStrategy.Random, new ForwardStrategyStore(temp.Path, "panel-a", 9).Get(77));
    Assert.Equal(ForwardStrategy.LatencyBest, new ForwardStrategyStore(temp.Path, "panel-b", 9).Get(77));
}
```

Assert the bond process arguments include the two strategy flags, a saved `Random` choice is not replaced during runtime reconciliation, and changing `ForwardViewModel.SelectedStrategy` writes locally without invoking `MasterApiClient`.

- [ ] **Step 2: Run and verify RED**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter ForwardStrategyStoreTests
```

Expected: compile failure because the strategy types do not exist.

- [ ] **Step 3: Implement Windows local strategy support**

Use this canonical enum:

```csharp
public enum ForwardStrategy { SpeedMax, LatencyBest, Random }
```

Persist snake-case strings through temp-file replacement. `ForwardStrategyStore` owns one runtime file per Forward and creates `latency_best\n` only when that file is absent. Extend the bond runtime spec to reference, but never regenerate or overwrite, that owned file and add:

```text
--strategy-file "<state-dir>\forward-strategies\<forward-id>.strategy" --default-strategy latency_best
```

In the Forward details template, add the approved three vertical radio rows with label plus description. Bind them to `SelectedStrategy`; selection writes the local file immediately and does not restart the process.

- [ ] **Step 4: Run Windows tests**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj
```

Expected: PASS.

- [ ] **Step 5: Commit Windows support**

```powershell
git add LeikwanBridge.Desktop/src LeikwanBridge.Desktop/tests
git commit -m "feat: choose forward strategies in windows client"
```

### Task 7: Add Android Persistence, Runtime Flags, and Descriptive Radio Rows

**Files:**
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardStrategy.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/data/ForwardStrategyStore.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- Modify: `leikwan-android/app/src/main/res/values/strings.xml`
- Modify: `leikwan-android/app/src/main/res/values-zh-rCN/strings.xml`
- Create: `leikwan-android/app/src/test/java/com/leikwan/bridge/data/ForwardStrategyStoreTest.kt`
- Modify: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`
- Create: `leikwan-android/app/src/test/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModelTest.kt`

- [ ] **Step 1: Write failing Android tests**

```kotlin
@Test
fun `missing choice defaults to latency best and choices stay per forward`() {
    val store = ForwardStrategyStore(inMemoryPreferences(), "panel-a", 9)
    assertEquals(ForwardStrategy.LATENCY_BEST, store.get(77))
    store.set(77, ForwardStrategy.RANDOM)
    assertEquals(ForwardStrategy.RANDOM, store.get(77))
    assertEquals(ForwardStrategy.LATENCY_BEST, store.get(78))
}

@Test
fun `bond runner passes strategy file without panel mutation`() {
    val spec = runner.buildProcessSpec(sampleForward, ForwardStrategy.RANDOM)
    assertTrue(spec.arguments.contains("--strategy-file"))
    assertTrue(spec.arguments.contains("--default-strategy latency_best"))
}
```

The ViewModel test changes the selected strategy and asserts the fake repository receives no API call.

- [ ] **Step 2: Run and verify RED**

```powershell
Set-Location leikwan-android
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.data.ForwardStrategyStoreTest"
Set-Location ..
```

Expected: Kotlin compile failure because `ForwardStrategyStore` is missing.

- [ ] **Step 3: Implement Android local strategy support**

Define canonical enum wire values, persist under `forward_strategy:<panel-hash>:<client-id>:<forward-id>`, and write a one-line runtime file under app-private storage before starting bond. Add the approved descriptive `RadioButton` rows to the selected Forward section in `TunnelsScreen`. `TunnelsViewModel.selectStrategy` updates local state and store only.

- [ ] **Step 4: Run Android tests**

```powershell
Set-Location leikwan-android
.\gradlew.bat :app:testDebugUnitTest
Set-Location ..
```

Expected: PASS.

- [ ] **Step 5: Commit Android support**

```powershell
git add leikwan-android/app/src/main leikwan-android/app/src/test
git commit -m "feat: choose forward strategies in android client"
```

### Task 8: Publish Rebuilt Runtime and Source Mirrors

**Files:**
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Modify: `artifacts/wstunnel/leikwan-bond_linux_amd64`
- Modify: `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
- Modify: `artifacts/client-agent.py`
- Modify: `artifacts/install-forward-runtime.py`
- Modify: `artifacts/forward-strategy.py`
- Modify: `artifacts/versions.json`
- Test: `master/tests/bond_versions_manifest_regression_test.php`
- Test: `master/tests/artifact_versions_manifest_consistency_test.php`
- Test: `client-agent/tests/test_artifact_versions_consistency.py`

- [ ] **Step 1: Bump the bond patch version and run manifest tests to verify RED**

Change `Cargo.toml` from `0.1.15` to `0.1.16`, then run:

```powershell
php master/tests/bond_versions_manifest_regression_test.php
python -m pytest client-agent/tests/test_artifact_versions_consistency.py -q
```

Expected: FAIL because binaries and `versions.json` still describe `0.1.15`.

Update the existing `cli_reports_refreshed_version` Rust assertion to `0.1.16` in the same RED/GREEN cycle.

- [ ] **Step 2: Sync tested Python sources into artifact mirrors**

Use byte-preserving copies after the source tests pass:

```powershell
Copy-Item client-agent\client_agent.py artifacts\client-agent.py -Force
Copy-Item client-agent\install_forward_runtime.py artifacts\install-forward-runtime.py -Force
Copy-Item client-agent\forward_strategy.py artifacts\forward-strategy.py -Force
```

Run `git diff --no-index` between each source/mirror pair and require no output.

- [ ] **Step 3: Build Linux and Windows bond artifacts**

Build Linux through WSL and Windows natively:

```powershell
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/wstunnel-main/leikwan-bond && cargo build --release'
cargo build --manifest-path wstunnel-main/leikwan-bond/Cargo.toml --release --target x86_64-pc-windows-msvc
```

Copy the resulting executables to the two tracked artifact paths, then update `artifacts/versions.json` with version, SHA-256, and exact byte size from `Get-FileHash` and `Get-Item`.

- [ ] **Step 4: Run artifact consistency tests**

```powershell
php master/tests/bond_versions_manifest_regression_test.php
php master/tests/artifact_versions_manifest_consistency_test.php
python -m pytest client-agent/tests/test_artifact_versions_consistency.py -q
```

Expected: PASS.

- [ ] **Step 5: Commit artifacts and provenance**

```powershell
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock wstunnel-main/leikwan-bond/src/main.rs artifacts/wstunnel artifacts/client-agent.py artifacts/install-forward-runtime.py artifacts/forward-strategy.py artifacts/versions.json
git commit -m "build: publish client strategy runtime artifacts"
```

### Task 9: Cross-Platform Privacy and Regression Verification

**Files:**
- Modify only if a failing verification exposes a defect in files already owned by Tasks 1-8.

- [ ] **Step 1: Run focused privacy searches**

```powershell
rg -n 'client_strategy|strategy_mode|latency_ms_by_node' master/src master/migrations
```

Expected: no new panel persistence or API serialization. Product-local identifiers may exist only in client source trees.

- [ ] **Step 2: Run the strategy suites**

```powershell
php master/tests/leigod_client_local_strategy_contract_test.php
cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml --all-targets -- --nocapture
python -m pytest client-agent/tests -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj
Set-Location leikwan-android
.\gradlew.bat :app:testDebugUnitTest
Set-Location ..
```

Expected: all pass. Record any existing unrelated environmental failure separately; do not waive a new strategy failure.

- [ ] **Step 3: Run the broad panel regression sweep**

```powershell
$results = foreach ($test in Get-ChildItem master\tests\*.php) {
    php $test.FullName *> $null
    [pscustomobject]@{ Test=$test.Name; ExitCode=$LASTEXITCODE }
}
$results | Where-Object ExitCode -ne 0 | Format-Table -AutoSize
```

Expected: no new failures relative to the recorded master baseline. Compare names, not only the count, because the current environment has known unrelated failures.

- [ ] **Step 4: Inspect the complete diff and working tree**

```powershell
git diff --check
git status --short --branch
git log --oneline --decorate -12
```

Expected: no whitespace errors and no uncommitted implementation files.

- [ ] **Step 5: Create the subsystem checkpoint tag/commit only if required by the execution workflow**

If verification required no correction, do not create an empty commit. Record the verified commit IDs in the execution handoff and proceed to the independent Precision Grid plan.
