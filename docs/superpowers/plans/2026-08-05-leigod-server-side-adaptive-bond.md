# LeiGod Server-Side Adaptive Bond Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move fixed-endpoint LeiGod aggregation from the end-user client to the slave/sidecar pair, add direction-aware automatic line admission and latency/bulk scheduling, and validate Reverse single-stream throughput above 200 Mbps or prove the external segment ceiling.

**Architecture:** The client keeps one ordinary authenticated ingress connection to one public slave endpoint. A slave-local `leikwan-bond client` splits the stream into route-tagged legs through the existing loopback LeiGod relay, while the existing sidecar `leikwan-bond server` reassembles and dials the target. Each sending peer measures ACK-confirmed throughput per leg, performs relative admission with quarantine/probes, and schedules bulk chunks proportionally while keeping short flows on a healthy low-RTT leg.

**Tech Stack:** Rust/Tokio `leikwan-bond`, Python slave/client agents, PHP control plane, systemd, static musl release artifacts, PHPUnit-style PHP scripts, Python `unittest`, iperf3 JSON canaries.

---

### Task 1: Time-Based Per-Leg Throughput Samples

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Test: inline Rust tests in `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Write failing tests for qualifying delivery windows**

Add tests using controlled `Instant` values for a wished-for API:

```rust
#[test]
fn bulk_delivery_window_requires_bytes_and_duration() {
    let config = test_config();
    let started = Instant::now();
    let mut window = ThroughputWindow::new(started);
    assert_eq!(window.credit(512 * 1024, started + Duration::from_millis(1200), true, &config), None);
    let sample = window
        .credit(512 * 1024, started + Duration::from_millis(1500), true, &config)
        .expect("one MiB over one second must qualify");
    assert!((sample.mbps - 5.592).abs() < 0.01);
}

#[test]
fn demand_limited_interactive_delivery_does_not_lower_throughput() {
    let config = test_config();
    let started = Instant::now();
    let mut window = ThroughputWindow::new(started);
    assert_eq!(window.credit(2 * 1024 * 1024, started + Duration::from_secs(2), false, &config), None);
    assert_eq!(window.qualifying_samples, 0);
}
```

- [ ] **Step 2: Run the focused Rust tests and verify RED**

Run:

```bash
cd wstunnel-main/leikwan-bond
cargo test delivery -- --nocapture
```

Expected: compile failure because `ThroughputWindow` and the new configuration fields do not exist.

- [ ] **Step 3: Implement the minimal delivery-window model**

Add to `SessionConfig`:

```rust
pub adaptive_line_admission: bool,
pub throughput_min_mbps: f64,
pub throughput_relative_floor: f64,
pub throughput_min_sample_bytes: u64,
pub throughput_min_sample: Duration,
pub throughput_bad_windows: u32,
pub throughput_recovery_windows: u32,
pub throughput_quarantine: Duration,
pub throughput_probe_interval: Duration,
pub throughput_probe_bytes: u64,
```

Add a `ThroughputWindow` that accumulates only warmup/bulk ACK-confirmed first-transmission bytes,
calculates Mbps after both thresholds are met, increments a qualifying-sample counter, and resets
without retaining unbounded history. Add `throughput_mbps_ewma` and sample counters to
`ProtocolLegHealth`.

- [ ] **Step 4: Pass bulk context into ACK accounting**

Change `apply_ack` to receive `qualifies_for_throughput: bool` and an observation time. Credit only
chunks with `transmit_count == 1`; retransmitted bytes continue to update penalties but cannot
inflate delivered throughput.

- [ ] **Step 5: Run focused and full Rust tests**

Run `RUSTFLAGS=-Awarnings cargo test` under WSL. Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): measure time-based leg throughput"
```

### Task 2: Relative Admission, Quarantine, Probes, And Weighted Bulk

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Test: inline Rust tests in `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Write failing admission-state tests**

Cover these exact behaviors:

```rust
#[test]
fn three_slow_windows_quarantine_leg_relative_to_best() {
    let now = Instant::now();
    let mut config = test_config();
    config.adaptive_line_admission = true;
    config.throughput_bad_windows = 3;
    config.throughput_min_mbps = 10.0;
    config.throughput_relative_floor = 0.35;
    let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    apply_tcp_throughput_sample(&mut legs, 0, 200.0, now, &config);
    for offset in 0..3 {
        apply_tcp_throughput_sample(
            &mut legs,
            1,
            40.0,
            now + Duration::from_secs(offset + 1),
            &config,
        );
    }
    assert_eq!(legs[1].tcp_health.bulk_admission, BulkAdmissionState::Quarantined);
    assert_eq!(legs[0].tcp_health.bulk_admission, BulkAdmissionState::Admitted);
}

#[test]
fn upload_and_reverse_observers_make_independent_decisions() {
    let now = Instant::now();
    let config = adaptive_test_config();
    let mut upload = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    let mut reverse = upload.clone();
    apply_tcp_throughput_sample(&mut upload, 0, 160.0, now, &config);
    apply_tcp_throughput_sample(&mut upload, 1, 150.0, now, &config);
    apply_tcp_throughput_sample(&mut reverse, 0, 200.0, now, &config);
    for offset in 0..config.throughput_bad_windows {
        apply_tcp_throughput_sample(
            &mut reverse,
            1,
            5.0,
            now + Duration::from_secs(offset as u64 + 1),
            &config,
        );
    }
    assert_ne!(upload[1].tcp_health.bulk_admission, BulkAdmissionState::Quarantined);
    assert_eq!(reverse[1].tcp_health.bulk_admission, BulkAdmissionState::Quarantined);
}

#[test]
fn quarantined_leg_gets_only_bounded_due_probe_budget() {
    let now = Instant::now();
    let mut config = adaptive_test_config();
    config.throughput_probe_bytes = 1024 * 1024;
    let mut leg = fake_leg(0, 10, 0, 20);
    leg.tcp_health.bulk_admission = BulkAdmissionState::Quarantined;
    leg.tcp_health.quarantine_until = Some(now - Duration::from_secs(1));
    leg.tcp_health.next_probe_at = Some(now - Duration::from_secs(1));
    begin_tcp_probe_if_due(&mut leg, now, &config);
    assert!(tcp_leg_bulk_eligible(&leg, now, &config));
    consume_tcp_probe_budget(&mut leg, 1024 * 1024, now, &config);
    assert!(!tcp_leg_bulk_eligible(&leg, now, &config));
}

#[test]
fn two_good_probe_windows_restore_leg() {
    let now = Instant::now();
    let mut config = adaptive_test_config();
    config.throughput_recovery_windows = 2;
    let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    legs[1].tcp_health.bulk_admission = BulkAdmissionState::Probation;
    apply_tcp_throughput_sample(&mut legs, 0, 200.0, now, &config);
    apply_tcp_throughput_sample(&mut legs, 1, 170.0, now, &config);
    assert_eq!(legs[1].tcp_health.bulk_admission, BulkAdmissionState::Probation);
    apply_tcp_throughput_sample(&mut legs, 1, 180.0, now + Duration::from_secs(1), &config);
    assert_eq!(legs[1].tcp_health.bulk_admission, BulkAdmissionState::Admitted);
}

#[test]
fn all_quarantined_falls_back_to_best_writable_leg() {
    let now = Instant::now();
    let config = adaptive_test_config();
    let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
    for leg in &mut legs {
        leg.tcp_health.bulk_admission = BulkAdmissionState::Quarantined;
        leg.tcp_health.quarantine_until = Some(now + Duration::from_secs(60));
    }
    legs[0].tcp_health.throughput_mbps_ewma = 40.0;
    legs[1].tcp_health.throughput_mbps_ewma = 20.0;
    assert_eq!(select_weighted_bulk_leg(&legs, 0, now, &config), Some(0));
}

#[test]
fn weighted_bulk_prefers_normalized_inflight_load() {
    let now = Instant::now();
    let config = adaptive_test_config();
    let mut fast = fake_leg(0, 10, 400_000, 20);
    let mut slow = fake_leg(1, 10, 150_000, 25);
    fast.tcp_health.throughput_mbps_ewma = 200.0;
    slow.tcp_health.throughput_mbps_ewma = 50.0;
    assert_eq!(select_weighted_bulk_leg(&[fast, slow], 0, now, &config), Some(0));
}

#[test]
fn sub_ten_mbps_low_rtt_leg_is_interactive_only() {
    let now = Instant::now();
    let config = adaptive_test_config();
    let mut low_latency = fake_leg(0, 10, 0, 10);
    let mut bulk = fake_leg(1, 10, 0, 35);
    low_latency.tcp_health.throughput_mbps_ewma = 8.0;
    low_latency.tcp_health.bulk_admission = BulkAdmissionState::Degraded;
    bulk.tcp_health.throughput_mbps_ewma = 180.0;
    assert_eq!(select_latency_best_leg(&[low_latency.clone(), bulk.clone()]), Some(0));
    assert_eq!(select_weighted_bulk_leg(&[low_latency, bulk], 0, now, &config), Some(1));
}
```

- [ ] **Step 2: Run each new test and verify the expected RED failure**

Run the test name individually with `cargo test <name> -- --exact` until every test fails because
the corresponding admission behavior is absent, not because of fixture errors.

- [ ] **Step 3: Implement bounded admission state**

Add a `BulkAdmissionState` enum (`Admitted`, `Degraded`, `Probation`, `Quarantined`) and bounded
counters/timestamps to `ProtocolLegHealth`. Implement:

```rust
fn recompute_tcp_bulk_admission(legs: &mut [LegState], now: Instant, config: &SessionConfig);
fn tcp_leg_bulk_eligible(leg: &LegState, now: Instant, config: &SessionConfig) -> bool;
fn consume_tcp_probe_budget(leg: &mut LegState, bytes: usize, now: Instant, config: &SessionConfig);
fn normalized_bulk_load(leg: &LegState) -> f64;
```

The floor is `max(absolute_min, best_fresh * relative_floor)`. Require configured consecutive bad
windows before quarantine and configured good probe windows before restoration. Keep the best
writable fallback when no admitted line remains.

- [ ] **Step 4: Integrate weighted selection without changing control-frame routing**

For `TcpStripingStage::Bulk`, filter by bulk admission and sort by normalized inflight load divided
by throughput weight. Sticky/control selection continues using health and RTT. Probe traffic is
bounded and never steals control frames.

- [ ] **Step 5: Extend snapshot JSON**

Expose throughput Mbps EWMA, qualifying samples, admission state, bad/good windows, quarantine
deadline, probe deadline, and remaining probe bytes. Do not expose session tokens.

- [ ] **Step 6: Run full Rust tests and commit**

```bash
RUSTFLAGS=-Awarnings cargo test
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): adapt bulk legs by measured throughput"
```

### Task 3: CLI Configuration And Release Version

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Modify: `wstunnel-main/leikwan-bond/Cargo.lock`
- Test: inline parser tests in `wstunnel-main/leikwan-bond/src/main.rs`

- [ ] **Step 1: Write failing CLI default/bounds tests**

Tests must parse all new flags, confirm conservative defaults, and reject zero/NaN/out-of-range
values before starting a listener.

- [ ] **Step 2: Verify RED with focused parser tests**

Expected: Clap reports unknown arguments or missing fields.

- [ ] **Step 3: Add CLI flags and map them into `SessionConfig`**

Use the names from the design contract, with adaptive admission disabled unless explicitly enabled.
The server-side placement will enable it; legacy bonds retain existing behavior.

- [ ] **Step 4: Bump `leikwan-bond` to `0.1.32` and run full Rust tests**

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-bond
git commit -m "release: prepare adaptive bond 0.1.32"
```

### Task 4: Slave Server-Side Bond Lifecycle

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/slave-agent.py`
- Test: `slave-agent/tests/test_leigod_server_side_bond_runtime.py`
- Test: `slave-agent/tests/test_slave_artifact_consistency.py`

- [ ] **Step 1: Write failing payload-validation tests**

Create a valid `server_side_bond` payload and reject: missing bond relay, one leg, duplicate/zero
route IDs, mismatched relay target, missing token, public/local port collision, invalid thresholds,
and placement combined with non-TCP carrier.

- [ ] **Step 2: Verify RED**

Run:

```bash
python -m unittest slave-agent.tests.test_leigod_server_side_bond_runtime -v
```

Expected: validation/runtime methods are missing.

- [ ] **Step 3: Add runtime contract and deterministic service names**

Implement `validate_server_side_bond_payload`, deterministic unit/config paths, and a unit command:

```text
/usr/local/bin/leikwan-bond client --protocol tcp
  --listen 127.0.0.1:<frontend-port>
  --legs tcp://127.0.0.1:<relay-port>?leg_route_id=<id>,...
  --session-token <token>
  --default-strategy speed_max
  --adaptive-line-admission <threshold flags>
```

Write token-bearing config/start files mode `0600`; logs and metadata contain only token-presence
and hashes.

- [ ] **Step 4: Reorder publish lifecycle safely**

Ensure the LeiGod relay is active first, then the slave bond unit, then the ordinary backend and
public mux. If any stage fails, leave the public route inactive. Persist server-side bond metadata
for idempotent re-publish.

- [ ] **Step 5: Add destroy/mode-change cleanup tests and implementation**

Stop/disable/remove the bond unit, start/config files, listener, and metadata without touching
shared LeiGod line services or unrelated forwards.

- [ ] **Step 6: Keep source/artifact copies byte-identical, run focused suites, and commit**

```bash
python -m unittest slave-agent.tests.test_leigod_server_side_bond_runtime slave-agent.tests.test_slave_artifact_consistency -v
git add slave-agent artifacts/slave-agent.py
git commit -m "feat(slave): host fixed-endpoint leigod aggregation"
```

### Task 5: Slave Installer And Manifest-Pinned Bond Binary

**Files:**
- Modify: `install/install-slave.sh`
- Modify: `artifacts/versions.json`
- Modify: `master/tests/slave_agent_update_artifact_regression_test.php`
- Modify: `slave-agent/tests/test_slave_installer_helper.py`

- [ ] **Step 1: Write failing installer and manifest tests**

Require `install-slave.sh` to read `leikwan_bond_linux_amd64` SHA/size from the versions manifest,
download atomically to `/usr/local/bin/leikwan-bond`, verify the hash, and skip only when the exact
artifact is installed.

- [ ] **Step 2: Verify RED, implement download, and re-run tests**

Do not activate `server_side_bond` when the installed binary is absent or does not match the
manifest pin.

- [ ] **Step 3: Commit**

```bash
git add install/install-slave.sh artifacts/versions.json master/tests slave-agent/tests
git commit -m "feat(slave): install adaptive bond artifact"
```

### Task 6: Panel Placement And Slave Publish Contract

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/tests/leigod_fixed_endpoint_bond_contract_test.php`

- [ ] **Step 1: Write failing panel contract tests**

Set sidecar point config `server_side_leigod_bond=true` and assert:

```php
assertSame('slave_sidecar', $config['bonding']['aggregation_placement']);
assertSame([], $config['bonding']['fixed_endpoint_legs'] ?? []);
assertSame(1, count($config['endpoints']));
assertSame('127.0.0.1:58316', $payload['target']);
assertSame('127.0.0.1:48316', $payload['server_side_bond']['leg_relay_target']);
assertSame([447,451,453,455,456], array_column($payload['server_side_bond']['legs'], 'leg_route_id'));
```

Also prove the existing `fixed_endpoint_leigod_bond=true` without the new gate retains client-side
0.1.31 behavior.

- [ ] **Step 2: Verify RED with the focused PHP script**

- [ ] **Step 3: Implement placement gate and stable frontend port**

Add `stableLeigodServerBondPort(endpointId) = 58000 + endpointId % 7000`, collision-safe validation,
the complete `server_side_bond` payload, and client contract fields. Keep the sidecar required and
the public endpoint row unchanged.

- [ ] **Step 4: Run focused and adjacent PHP suites and commit**

```bash
php master/tests/leigod_fixed_endpoint_bond_contract_test.php
git add master/src/Support/ProvisioningService.php master/tests/leigod_fixed_endpoint_bond_contract_test.php
git commit -m "feat(panel): place leigod bond on slave and sidecar"
```

### Task 7: Transparent Client Runtime

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `artifacts/install-forward-runtime.py`
- Modify: `client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py`

- [ ] **Step 1: Write failing transparent-runtime test**

For `aggregation_placement=slave_sidecar`, require exactly one `leikwan-ingress client`, zero
`leikwan-bond client` commands, no `leg_route_id`, unchanged remote public endpoint, and the normal
local application listener. Retain the existing legacy fixed-bond test under no placement marker.

- [ ] **Step 2: Verify RED**

Run the focused unittest and confirm the current renderer starts two bond processes.

- [ ] **Step 3: Implement the special transparent branch**

Validate exactly one physical plain-TCP endpoint and render the existing ingress helper directly at
the requested client listen port. Do not expose the sidecar target or line list in the script.

- [ ] **Step 4: Run focused, artifact consistency, and broader client suites**

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py
git commit -m "feat(client): use transparent ingress for server-side bond"
```

### Task 8: Real Process Integration

**Files:**
- Create: `slave-agent/tests/test_leigod_server_side_bond_integration.py`

- [ ] **Step 1: Write the integration test before runtime glue is considered complete**

Start a real bond server, real bond client on the slave side, real bond-mode relay with two fake
SOCKS candidates, and one ordinary TCP application socket. Assert route identity, normal echo,
Reverse bulk delivery, one client application connection, quarantine of a throttled line, and no
transport rebuild while the healthy line continues.

- [ ] **Step 2: Verify the test fails against incomplete glue, fix only production causes, and rerun**

- [ ] **Step 3: Run all release-focused Python/PHP/Rust suites and commit**

```bash
git add slave-agent/tests/test_leigod_server_side_bond_integration.py
git commit -m "test: cover server-side adaptive leigod bond"
```

### Task 9: Static Release Artifact

**Files:**
- Modify: `artifacts/wstunnel/leikwan-bond_linux_amd64`
- Modify: binary mirrors required by existing consistency tests
- Modify: `artifacts/versions.json`
- Modify: release version tests

- [ ] **Step 1: Run release tests against the old artifact and verify RED for version 0.1.32**

- [ ] **Step 2: Build static musl artifact under WSL**

```bash
cd wstunnel-main/leikwan-bond
cargo build --release --target x86_64-unknown-linux-musl
```

Require static x86-64 PIE, executable mode, version `0.1.32`, exact mirrors, and updated SHA/size.

- [ ] **Step 3: Run complete local gates**

Run serially: full Rust tests, release Python suite, broader client/slave suites, focused PHP tests,
process integration, artifact consistency, and `git diff --check`.

- [ ] **Step 4: Commit the release**

```bash
git add artifacts wstunnel-main client-agent slave-agent master/tests
git commit -m "release: publish server-side adaptive bond 0.1.32"
```

### Task 10: Isolated Live Capacity And Adaptive Canary

**Files:**
- Update after results: `docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`

- [ ] **Step 1: Inventory and back up canary hosts**

Use WSL SSH, sidecar only through an authorized slave hop. Record exact binary hashes, service
states, restarts, listeners, and existing LeiGod line services. Do not create panel records yet.

- [ ] **Step 2: Measure raw fixed-slave Reverse single-stream capacity**

Use an isolated ingress port within `7000-9000`, five `iperf3 -R -b 400M -t 20 --json` runs, and
archive every JSON. If median is at most `210 Mbps`, record the external ceiling before judging bond.

- [ ] **Step 3: Deploy isolated 0.1.32 slave/sidecar canary units**

Use committed artifacts, loopback relay, deterministic route IDs, and no production forward/task
mutation. Verify the application client has one ordinary connection and no client bond process.

- [ ] **Step 4: Run per-line controls and adaptive Reverse single-stream tests**

Run five controls per line, warm/probe the adaptive pool, then five cold and five warm server-side
bond Reverse runs. Capture bond snapshots, relay state, restart counters, sockets, and zero-delivery
intervals beside each JSON.

- [ ] **Step 5: Evaluate gates and tune only evidence-backed thresholds**

Primary: median >200 Mbps, zero stalls, five normal runs. Stretch: median >=220 Mbps and every run
>200 Mbps. Any threshold change requires a local regression test and a new committed artifact before
retesting.

- [ ] **Step 6: Clean every canary resource and prove final state**

Zero transient units, benchmark listeners, client sockets, target iperf services, and upload files;
original agents and LeiGod line services remain active.

### Task 11: Production Synchronization And Handoff

**Files:**
- Modify: `docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`

- [ ] **Step 1: Update the handoff from immutable result JSON**

Record topology, exact commands, all runs, thresholds, automatic exclusions/recoveries, capacity
controls, cleanup, artifacts, commits, and any unmet gate without overstating results.

- [ ] **Step 2: Commit the handoff and build a committed release archive**

- [ ] **Step 3: Back up panel files/database and each changed runtime host**

Validate archive/database backups, preserve `.env`, and record rollback paths and hashes.

- [ ] **Step 4: Synchronize committed panel/artifacts and runtime binaries**

Run migration status before/after, exact tracked-file comparison, origin/public HTTP checks, and
runtime hash/version checks. Feature gates remain off except for an explicitly selected canary.

- [ ] **Step 5: Final verification**

Fresh-run all required local gates, panel `71 applied / 0 pending`, public HTTP, slave/client/sidecar
services, endpoint identity, no transient resources, and a clean Git worktree.
