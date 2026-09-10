# UoT Feedback-Driven Fair Scheduler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an opt-in, feedback-driven UoT scheduler that sustains high concurrency with bounded packet loss and stable latency while preserving the existing legacy path and mixed-version compatibility.

**Architecture:** Start from `master@11550550b3c6c36d0878443c79dd5814aa260a48`, import only the pre-FEC unordered/bounded-queue runtime snapshot through `e44c987`, then add a single-owner scheduler actor. The actor provides bounded admission, DRR fairness, aggregate pacing, feedback-based contiguous-chunk placement, explicit reservation accounting, and per-leg health state; client and sidecar runtimes remain responsible for sockets and task lifecycle.

**Tech Stack:** Rust 2021, Tokio, Serde/Serde JSON, Python `unittest`, PHP CLI tests, .NET/C# xUnit, Linux network namespaces, `tc netem`, `iperf3`, PowerShell/WSL, static musl builds.

**Worktree:** `C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\uot-feedback-scheduler-20260808`

**Commit policy:** Do not commit or push. Do not stage files. Every task ends with test, `git diff --check`, and `git status --short` checkpoints. Leave the finished change as an uncommitted worktree.

**Deployment policy:** Do not change production. Isolated stage validation must preserve `client -> slave -> sidecar`, use slave ingress ports in `7000-9000`, and prove port `20013` remains available. Production requires a new explicit instruction.

---

## File Map

### Rust runtime

- Modify `wstunnel-main/leikwan-uot/src/config.rs`: scheduler mode, defaults, hard-bound validation.
- Modify `wstunnel-main/leikwan-uot/src/frame.rs`: reserve frame type 5 and add types 6/7.
- Create `wstunnel-main/leikwan-uot/src/progress.rs`: capability negotiation, fixed-width progress codec, progress validation, RTT checkpoints.
- Create `wstunnel-main/leikwan-uot/src/scheduler.rs`: bounded admission, DRR, pacer, chunks, windows, leg state, reservation ledger, actor commands.
- Create `wstunnel-main/leikwan-uot/src/telemetry.rs`: counters, bounded histograms, Jain fairness, bounded per-session reporting.
- Modify `wstunnel-main/leikwan-uot/src/client_runtime.rs`: scheduler actor wiring, extended Auth, AuthAck/progress handling, checked UDP writes.
- Modify `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`: compatible Auth parsing, conditional AuthAck, progress generation, reverse scheduler, checked UDP writes.
- Modify `wstunnel-main/leikwan-uot/src/lib.rs`: export focused modules.
- Create `wstunnel-main/leikwan-uot/tests/feedback_runtime.rs`: mixed-version and both-direction runtime integration tests.
- Create `wstunnel-main/leikwan-uot/tests/scheduler_load.rs`: deterministic 64-session and single-session/multi-leg load tests.

### Configuration propagation

- Modify `client-agent/install_forward_runtime.py`: copy validated scheduler fields into Rust runtime JSON and reject feedback mode with Python fallback.
- Modify `sidecar-agent/sidecar_agent.py`: copy the same fields into sidecar runtime JSON.
- Modify `client-agent/client_agent.py`: include sidepath/native-binary capability in the forward contract signature and fail closed before a feedback restart.
- Modify `master/src/Support/ProvisioningService.php`: publish one authoritative scheduler contract.
- Modify `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UdpUotSidepathConfig.cs`: deserialize the scheduler contract.
- Modify `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`: emit the scheduler contract unchanged.
- Modify `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs`: include UoT sidepath settings and native capability identity.
- Modify the existing Python, PHP, and C# regression tests named in the propagation tasks.

### Native artifacts and installers

- Modify `client-agent/leikwan_uot_bond.py` and `sidecar-agent/leikwan_udp_uot_merge.py`: reject non-legacy scheduler configs.
- Modify `artifacts/client-agent.py`, `artifacts/sidecar-agent.py`, and `artifacts/install-forward-runtime.py`: preserve established byte-identical source mirrors.
- Modify `install/install-client-agent.sh`, `install/install-client.sh`, and `install/install-bond-sidecar.sh`: download and verify native UoT binaries.
- Modify `scripts/build-windows-client-setup.ps1` and `scripts/tests/test_windows_client_setup_installer.py`: require the manifest-selected native Windows UoT artifact and its hash.
- Modify `artifacts/versions.json` and artifact consistency tests.
- Create `artifacts/wstunnel/leikwan-uot-bond_linux_amd64`, `artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64`, and `artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe` as locally built, hash-pinned artifacts.
- Create `wstunnel-main/leikwan-uot/src/bin/leikwan-uot-qa.rs`: high-rate local QA generator/receiver.

### QA and evidence

- Create `tools/uot-feedback/acceptance.py`: run manifest, metric gates, paired legacy comparison, evidence hashes.
- Create `tools/uot-feedback/test_acceptance.py`: deterministic parser/gate tests.
- Create `tools/uot-feedback/netns.sh`: isolated namespace topology and `tc netem` impairment runner.
- Create `docs/superpowers/runbooks/uot-feedback-stage.md`: stage-only commands, listener guard, backup, rollback.
- Rewrite the two damaged handoffs only after measured evidence exists.

---

### Task 1: Import The Approved Non-FEC Runtime Baseline

**Files:**
- Modify: `wstunnel-main/leikwan-uot/Cargo.toml`
- Modify: `wstunnel-main/leikwan-uot/src/client_runtime.rs`
- Modify: `wstunnel-main/leikwan-uot/src/config.rs`
- Create: `wstunnel-main/leikwan-uot/src/delivery.rs`
- Modify: `wstunnel-main/leikwan-uot/src/frame.rs`
- Modify: `wstunnel-main/leikwan-uot/src/lib.rs`
- Modify: `wstunnel-main/leikwan-uot/src/merge.rs`
- Create: `wstunnel-main/leikwan-uot/src/queue.rs`
- Modify: `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`
- Create: `wstunnel-main/leikwan-uot/src/udp_socket.rs`
- Create: `wstunnel-main/leikwan-uot/src/wireguard.rs`

- [ ] **Step 1: Confirm the worktree/base and record the untouched baseline**

Run:

```powershell
git status --short --branch
git rev-parse HEAD
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
```

Expected: branch is `codex/uot-feedback-scheduler-20260808`, HEAD is `11550550b3c6c36d0878443c79dd5814aa260a48`, and the current 11 tests pass. Only the approved spec and this plan are untracked.

- [ ] **Step 2: Generate and inspect the exact pre-FEC patch**

Run:

```powershell
$patch = Join-Path $env:TEMP 'leikwan-uot-e44c987.patch'
git diff --binary --output=$patch master e44c987 -- wstunnel-main/leikwan-uot
git apply --check $patch
git diff --stat master e44c987 -- wstunnel-main/leikwan-uot
git log --oneline --reverse master..e44c987 -- wstunnel-main/leikwan-uot
```

Expected: `git apply --check` exits 0; the patch changes only `wstunnel-main/leikwan-uot`; the listed path history ends at `e44c987`; no FEC implementation from `fe0a7df` or newer appears.

- [ ] **Step 3: Apply the snapshot without creating a commit**

Run:

```powershell
git apply $patch
git diff --name-status -- wstunnel-main/leikwan-uot
rg -n "FecParity|fec_group|fec_shard" wstunnel-main/leikwan-uot/src
```

Expected: the 11 baseline files are modified/created; the FEC search returns no implementation matches; nothing is staged.

- [ ] **Step 4: Verify the imported behavior**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all -- --check
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
git diff --check
git status --short
```

Expected: formatting and all imported non-FEC UoT tests pass, `git diff --check` emits nothing, and the changes remain unstaged.

---

### Task 2: Add The Backward-Compatible Scheduler Configuration Contract

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/config.rs`

- [ ] **Step 1: Add failing legacy-default and validation tests**

Add these cases to `config.rs`'s test module:

```rust
#[test]
fn legacy_json_defaults_to_legacy_scheduler() {
    let parsed: ClientConfig = serde_json::from_str(LEGACY_CLIENT_JSON).unwrap();
    assert_eq!(parsed.scheduler.scheduler_mode, SchedulerMode::Legacy);
    assert_eq!(parsed.scheduler.aggregate_rate_bps, 300_000_000);
    assert_eq!(parsed.scheduler.queue_target_ms, 5);
    assert_eq!(parsed.scheduler.queue_deadline_ms, 10);
    parsed.validate().unwrap();
}

#[test]
fn feedback_defaults_match_the_approved_contract() {
    let cfg = SchedulerConfig {
        scheduler_mode: SchedulerMode::FeedbackDrr,
        ..SchedulerConfig::default()
    };
    assert_eq!(cfg.global_queue_bytes, 1_048_576);
    assert_eq!(cfg.global_queue_packets, 8_192);
    assert_eq!(cfg.per_session_queue_bytes, 262_144);
    assert_eq!(cfg.per_session_queue_packets, 512);
    assert_eq!(cfg.drr_quantum_bytes, 16_384);
    assert_eq!((cfg.chunk_min_bytes, cfg.chunk_target_bytes, cfg.chunk_max_bytes), (16_384, 32_768, 65_536));
    assert_eq!((cfg.min_leg_window_bytes, cfg.max_leg_window_bytes), (262_144, 4_194_304));
    assert_eq!(cfg.max_total_outstanding_bytes, 8_388_608);
    assert_eq!((cfg.progress_interval_bytes, cfg.progress_interval_frames, cfg.progress_interval_ms), (65_536, 32, 5));
    assert_eq!(cfg.finish_skew_budget_ms, 2);
    assert_eq!(cfg.probe_interval_ms, 1_000);
    assert_eq!(cfg.max_sessions, 4_096);
    assert_eq!(cfg.control_queue_packets, 256);
}

#[test]
fn feedback_validation_rejects_an_impossible_contract() {
    let mut cfg = SchedulerConfig {
        scheduler_mode: SchedulerMode::FeedbackDrr,
        ..SchedulerConfig::default()
    };
    cfg.queue_target_ms = 11;
    assert_eq!(cfg.validate(65_535).unwrap_err().to_string(), "queue_target_ms must not exceed queue_deadline_ms");
    cfg.queue_target_ms = 5;
    cfg.chunk_max_bytes = 65_534;
    assert_eq!(cfg.validate(65_535).unwrap_err().to_string(), "chunk_max_bytes must fit max_datagram_bytes");
}
```

Define `LEGACY_CLIENT_JSON` once inside the test module using the existing legacy JSON shape.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml config::tests::legacy_json_defaults_to_legacy_scheduler
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml config::tests::feedback_validation_rejects_an_impossible_contract
```

Expected: compilation fails because `SchedulerMode`, `SchedulerConfig`, and `scheduler` do not exist.

- [ ] **Step 3: Add the exact config types and defaults**

Add `SchedulerMode` and a flattened `SchedulerConfig` shared by `ClientConfig` and `SidecarConfig`:

```rust
#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum SchedulerMode {
    Legacy,
    FeedbackDrr,
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct SchedulerConfig {
    #[serde(default)] pub scheduler_mode: SchedulerMode,
    #[serde(default = "default_aggregate_rate_bps")] pub aggregate_rate_bps: u64,
    #[serde(default = "default_queue_target_ms")] pub queue_target_ms: u64,
    #[serde(default = "default_queue_deadline_ms")] pub queue_deadline_ms: u64,
    #[serde(default = "default_global_queue_bytes")] pub global_queue_bytes: usize,
    #[serde(default = "default_global_queue_packets")] pub global_queue_packets: usize,
    #[serde(default = "default_per_session_queue_bytes")] pub per_session_queue_bytes: usize,
    #[serde(default = "default_per_session_queue_packets")] pub per_session_queue_packets: usize,
    #[serde(default = "default_drr_quantum_bytes")] pub drr_quantum_bytes: usize,
    #[serde(default = "default_chunk_min_bytes")] pub chunk_min_bytes: usize,
    #[serde(default = "default_chunk_target_bytes")] pub chunk_target_bytes: usize,
    #[serde(default = "default_chunk_max_bytes")] pub chunk_max_bytes: usize,
    #[serde(default = "default_min_leg_window_bytes")] pub min_leg_window_bytes: usize,
    #[serde(default = "default_max_leg_window_bytes")] pub max_leg_window_bytes: usize,
    #[serde(default = "default_max_total_outstanding_bytes")] pub max_total_outstanding_bytes: usize,
    #[serde(default = "default_progress_interval_bytes")] pub progress_interval_bytes: u64,
    #[serde(default = "default_progress_interval_frames")] pub progress_interval_frames: u64,
    #[serde(default = "default_progress_interval_ms")] pub progress_interval_ms: u64,
    #[serde(default = "default_finish_skew_budget_ms")] pub finish_skew_budget_ms: u64,
    #[serde(default = "default_probe_interval_ms")] pub probe_interval_ms: u64,
    #[serde(default = "default_max_sessions")] pub max_sessions: usize,
    #[serde(default = "default_control_queue_packets")] pub control_queue_packets: usize,
}
```

Implement `Default` with the approved values. Add `#[serde(flatten)] pub scheduler: SchedulerConfig` to both runtime configs. `SchedulerMode::default()` must return `Legacy`.

- [ ] **Step 4: Implement fail-closed hard-bound validation**

Use these process bounds and invariants:

```rust
const MAX_PROCESS_QUEUE_BYTES: usize = 64 * 1024 * 1024;
const MAX_PROCESS_QUEUE_PACKETS: usize = 262_144;
const MAX_PROCESS_OUTSTANDING_BYTES: usize = 64 * 1024 * 1024;
const MAX_PROCESS_SESSIONS: usize = 65_536;

impl SchedulerConfig {
    pub fn burst_bytes(&self, max_wire_frame_bytes: usize) -> usize {
        let two_ms = ((self.aggregate_rate_bps as u128 * 2) / 8_000) as usize;
        max_wire_frame_bytes.max(two_ms)
    }

    pub fn validate(&self, max_datagram_bytes: usize) -> Result<()> {
        if self.queue_target_ms > self.queue_deadline_ms {
            bail!("queue_target_ms must not exceed queue_deadline_ms");
        }
        if self.chunk_min_bytes == 0
            || self.chunk_min_bytes > self.chunk_target_bytes
            || self.chunk_target_bytes > self.chunk_max_bytes
        {
            bail!("chunk sizes must satisfy 0 < min <= target <= max");
        }
        if self.chunk_max_bytes < max_datagram_bytes {
            bail!("chunk_max_bytes must fit max_datagram_bytes");
        }
        if self.min_leg_window_bytes == 0
            || self.min_leg_window_bytes > self.max_leg_window_bytes
            || self.max_leg_window_bytes > self.max_total_outstanding_bytes
        {
            bail!("leg windows must satisfy 0 < min <= max <= total");
        }
        validate_nonzero_and_process_bounds(self)?;
        Ok(())
    }
}
```

`validate_nonzero_and_process_bounds` must check every packet/byte/rate/interval/session capacity, the four constants above, and `burst_bytes(FRAME_HEADER_BYTES + 4 + max_datagram_bytes) >= FRAME_HEADER_BYTES + 4 + max_datagram_bytes`. Call this from both `ClientConfig::validate()` and `SidecarConfig::validate()`.

- [ ] **Step 5: Run the config and full UoT tests**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml config::tests
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
git diff --check
git status --short
```

Expected: all config and baseline tests pass; legacy JSON remains valid; no file is staged.

---

### Task 3: Define Capability Negotiation And LegProgress Wire Semantics

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/frame.rs`
- Create: `wstunnel-main/leikwan-uot/src/progress.rs`
- Modify: `wstunnel-main/leikwan-uot/src/lib.rs`

- [ ] **Step 1: Add failing frame-number and codec tests**

Create tests that pin the wire contract:

```rust
#[test]
fn frame_numbers_preserve_the_experimental_fec_reservation() {
    assert_eq!(MsgType::FecParity as u8, 5);
    assert_eq!(MsgType::AuthAck as u8, 6);
    assert_eq!(MsgType::LegProgress as u8, 7);
}

#[test]
fn leg_progress_is_exactly_44_network_order_bytes() {
    let progress = LegProgress {
        progress_version: 1,
        direction: Direction::ClientToSidecar,
        connection_epoch: 7,
        decoded_frames: 11,
        decoded_wire_bytes: 13,
        udp_accepted_frames: 17,
        udp_accepted_wire_bytes: 19,
    };
    let encoded = progress.encode();
    assert_eq!(encoded.len(), 44);
    assert_eq!(LegProgress::decode(&encoded).unwrap(), progress);
}

#[test]
fn progress_validation_does_not_mutate_on_invalid_input() {
    let mut validator = ProgressValidator::new(Direction::ClientToSidecar, 9);
    validator.set_locally_sent_total(200);
    validator.accept(&LegProgress::accepted(Direction::ClientToSidecar, 9, 1, 100, 1, 100)).unwrap();
    let before = validator.snapshot();
    let bad = LegProgress::accepted(Direction::ClientToSidecar, 8, 2, 150, 2, 150);
    assert_eq!(validator.accept(&bad).unwrap_err(), ProgressError::EpochMismatch);
    assert_eq!(validator.snapshot(), before);
}
```

Add separate cases for reserved bits, direction mismatch, cumulative regression, and counters beyond locally sent wire bytes.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml frame::tests::frame_numbers_preserve_the_experimental_fec_reservation
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml progress::tests
```

Expected: compilation fails because the new frame variants and `progress` module do not exist.

- [ ] **Step 3: Add wire-compatible frame variants**

Change the enum and decoder exactly as follows:

```rust
#[repr(u8)]
pub enum MsgType {
    Auth = 0,
    Open = 1,
    Data = 2,
    Close = 3,
    Keepalive = 4,
    FecParity = 5,
    AuthAck = 6,
    LegProgress = 7,
}
```

Map all values `0..=7` in `TryFrom<u8>`. Retain envelope version 1 and the existing unknown-type error for values above 7.
Runtime matches must treat type 5 as reserved-but-unsupported in this phase:
count it as an invalid/unsupported peer frame and ignore its payload without FEC
decode, replay, or scheduler credit. Add a test proving type 5 cannot be mistaken
for AuthAck or LegProgress and does not terminate an otherwise valid negotiated
connection.

- [ ] **Step 4: Implement negotiation and fixed-width progress types**

Create these public interfaces in `progress.rs`:

```rust
pub const PROTOCOL_VERSION: u8 = 2;
pub const LEG_PROGRESS_VERSION: u8 = 1;
pub const LEG_PROGRESS_BYTES: usize = 44;
pub const LEG_PROGRESS_CAPABILITY: &str = "leg-progress-v1";

#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq)]
#[repr(u8)]
pub enum Direction { ClientToSidecar = 0, SidecarToClient = 1 }

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct AuthCapabilities {
    pub protocol_version: u8,
    pub capabilities: Vec<String>,
    pub connection_epoch: u64,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct AuthAck {
    pub protocol_version: u8,
    pub capabilities: Vec<String>,
    pub connection_epoch: u64,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct LegProgress {
    pub progress_version: u8,
    pub direction: Direction,
    pub connection_epoch: u64,
    pub decoded_frames: u64,
    pub decoded_wire_bytes: u64,
    pub udp_accepted_frames: u64,
    pub udp_accepted_wire_bytes: u64,
}
```

`LegProgress::encode` and `decode` must use explicit big-endian conversions, require exactly 44 bytes, require reserved bytes `[2..4] == [0, 0]`, and reject invalid versions/directions.

- [ ] **Step 5: Implement monotonic validation and local RTT checkpoints**

Expose:

```rust
pub struct ProgressValidator {
    expected_direction: Direction,
    connection_epoch: u64,
    locally_sent_wire_bytes: u64,
    accepted: Option<LegProgress>,
}

pub struct RttCheckpoints {
    entries: VecDeque<(u64, Instant)>,
    capacity: usize,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ProgressError {
    InvalidVersion,
    ReservedBits,
    DirectionMismatch,
    EpochMismatch,
    Regression,
    AboveLocalSentTotal,
    AcceptedAboveDecoded,
}

impl LegProgress {
    pub fn accepted(direction: Direction, epoch: u64, decoded_frames: u64,
        decoded_wire_bytes: u64, udp_accepted_frames: u64,
        udp_accepted_wire_bytes: u64) -> Self;
    pub fn encode(self) -> [u8; LEG_PROGRESS_BYTES];
    pub fn decode(bytes: &[u8]) -> Result<Self, ProgressError>;
}

impl ProgressValidator {
    pub fn new(expected_direction: Direction, connection_epoch: u64) -> Self;
    pub fn set_locally_sent_total(&mut self, cumulative_wire_bytes: u64);
    pub fn accept(&mut self, progress: &LegProgress) -> Result<(), ProgressError>;
    pub fn snapshot(&self) -> Option<LegProgress>;
}

impl RttCheckpoints {
    pub fn record(&mut self, cumulative_wire_bytes: u64, now: Instant);
    pub fn acknowledge(&mut self, cumulative_wire_bytes: u64, now: Instant) -> Option<Duration>;
}
```

Validation order is version/reserved/direction, epoch, monotonicity,
`udp_accepted <= decoded` for both frames and wire bytes, then local-sent ceiling.
`accept` replaces stored state only after every check succeeds. `RttCheckpoints`
is fixed-capacity, drops oldest checkpoints on overflow, and returns the elapsed
local monotonic duration for the newest crossed checkpoint.

- [ ] **Step 6: Export and verify the protocol module**

Add `pub mod progress;` to `lib.rs`, then run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml progress::tests
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml frame::tests
git diff --check
git status --short
```

Expected: protocol tests pass, including all rejection cases, with no staged changes.

---

### Task 4: Add Bounded Telemetry And Unambiguous Lifecycle Counters

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/telemetry.rs`
- Modify: `wstunnel-main/leikwan-uot/src/lib.rs`

- [ ] **Step 1: Write failing histogram, fairness, and counter tests**

Create tests around these exact outcomes:

```rust
#[test]
fn bounded_histogram_reports_requested_percentiles() {
    let mut h = DelayHistogram::milliseconds(&[1, 2, 5, 10, 20, 50]);
    for value in [1, 2, 3, 4, 9, 11] { h.observe(value); }
    assert_eq!(h.percentile(0.50), 5);
    assert_eq!(h.percentile(0.95), 20);
    assert_eq!(h.percentile(0.99), 20);
    assert_eq!(h.count(), 6);
}

#[test]
fn jain_fairness_excludes_idle_sessions() {
    let active = [("a", 100_u64, true), ("b", 100, true), ("idle", 0, false)];
    assert!((jain_fairness(active) - 1.0).abs() < 0.000_001);
}

#[test]
fn top_sessions_remains_fixed_capacity() {
    let mut top = TopSessions::new(2);
    top.observe("a", 10);
    top.observe("b", 30);
    top.observe("c", 20);
    assert_eq!(top.snapshot().iter().map(|x| x.key.as_str()).collect::<Vec<_>>(), vec!["b", "c"]);
}
```

Also test every fixed `DropReason` and `UdpErrorKind` string so stats keys cannot drift.

- [ ] **Step 2: Run the telemetry tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml telemetry::tests
```

Expected: compilation fails because `telemetry.rs` is not exported.

- [ ] **Step 3: Implement fixed enums and packet/payload/wire counters**

Use these names exactly:

```rust
pub enum LifecycleStage {
    Offered, Admitted, Scheduled, QueuedToLeg, Writing,
    LocalSocketAccepted, RemoteDecoded, RemoteUdpAccepted,
}

pub enum DropReason {
    QueueDeadline, RootQueueFull, SessionQueueFull, NoHealthyLeg,
    LegWindowFull, WriterClosed, UdpEgressError, InvalidProgress,
}

pub enum UdpErrorKind { WouldBlock, Enobufs, Unreachable, Closed, Other }

#[derive(Default, Clone, Copy, Serialize)]
pub struct TrafficCounter { pub packets: u64, pub payload_bytes: u64, pub wire_bytes: u64 }

#[derive(Clone, Serialize)]
pub struct DelayPercentiles { pub p50_ms: u64, pub p95_ms: u64, pub p99_ms: u64 }

#[derive(Clone, Serialize)]
pub struct LegStatsSnapshot {
    pub leg_id: u64,
    pub state: String,
    pub outstanding_wire_bytes: u64,
    pub progress_age_ms: u64,
    pub progress_srtt_ms: u64,
    pub delivery_rate_ewma_bps: u64,
    pub write_stalls: u64,
    pub transitions: u64,
}

#[derive(Clone, Serialize)]
pub struct StatsSnapshot {
    pub lifecycle: BTreeMap<String, TrafficCounter>,
    pub drops: BTreeMap<String, TrafficCounter>,
    pub udp_errors: BTreeMap<String, u64>,
    pub scheduler_drop_ratio: f64,
    pub admission_to_schedule: DelayPercentiles,
    pub writer_wait: DelayPercentiles,
    pub scheduler_queue: DelayPercentiles,
    pub active_delivery_rate_bps: u64,
    pub jain_fairness: f64,
    pub feedback_capable_active_legs: usize,
    pub legacy_fallback_active_legs: usize,
    pub queue_bytes: usize,
    pub queue_bytes_high_water: usize,
    pub outstanding_bytes: usize,
    pub outstanding_bytes_high_water: usize,
    pub legs: Vec<LegStatsSnapshot>,
}
```

Implement complete global and per-leg counters, bounded delay histograms for admission-to-schedule, writer wait, and admission-to-local-write, plus fixed-capacity top-N session reporting. Keep legacy `datagrams_out` in the JSON snapshot and derive it only from successful local UDP accepts.

- [ ] **Step 4: Implement Jain fairness and stable JSON output**

Use the standard formula over sessions with offered demand in the current measurement window:

```rust
pub fn jain_fairness<I, K>(rates: I) -> f64
where I: IntoIterator<Item = (K, u64, bool)> {
    let values: Vec<f64> = rates.into_iter()
        .filter_map(|(_, rate, active)| active.then_some(rate as f64))
        .collect();
    if values.is_empty() { return 1.0; }
    let sum: f64 = values.iter().sum();
    let sum_sq: f64 = values.iter().map(|v| v * v).sum();
    if sum_sq == 0.0 { 1.0 } else { (sum * sum) / (values.len() as f64 * sum_sq) }
}
```

Serialize enum keys with the exact snake-case names in the design. Do not allocate a per-session map larger than `max_sessions`; the reporting top-N capacity defaults to 128.

Compute `scheduler_drop_ratio` only from data `offered` and the seven data-drop
reasons. `InvalidProgress` has its own control-frame denominator and must not
inflate the scheduler data-drop ratio.

- [ ] **Step 5: Export and verify telemetry**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml telemetry::tests
git diff --check
git status --short
```

Expected: tests pass and the serialized names match the metrics contract.

---

### Task 5: Implement Bounded Admission, DRR, And Aggregate Pacing

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/scheduler.rs`
- Modify: `wstunnel-main/leikwan-uot/src/lib.rs`

- [ ] **Step 1: Add deterministic admission and deadline tests**

Use integer virtual time in microseconds for scheduler core tests:

```rust
#[test]
fn expired_data_is_removed_before_capacity_rejection() {
    let mut s = fixture_scheduler();
    assert!(s.admit(frame("a", 100, 0)).is_admitted());
    s.set_now_us(11_000);
    assert!(s.admit(frame("b", 100, 11_000)).is_admitted());
    assert_eq!(s.metrics().drops(DropReason::QueueDeadline), 1);
}

#[test]
fn root_and_session_limits_never_overcommit() {
    let mut s = fixture_scheduler_with_limits(200, 2, 120, 1);
    assert!(s.admit(frame("a", 100, 0)).is_admitted());
    assert_eq!(s.admit(frame("a", 1, 0)), Admission::Dropped(DropReason::SessionQueueFull));
    assert!(s.admit(frame("b", 100, 0)).is_admitted());
    assert_eq!(s.admit(frame("c", 1, 0)), Admission::Dropped(DropReason::RootQueueFull));
    assert_eq!(s.queue_bytes(), 200);
    assert_eq!(s.queue_packets(), 2);
}
```

Add cases for `max_sessions`, control/WireGuard-handshake reserved quota, and full wire-byte charging (`4-byte stream prefix + FRAME_HEADER_BYTES + payload`).

- [ ] **Step 2: Add deterministic DRR and pacer tests**

```rust
#[test]
fn sixty_four_equal_sessions_are_fair() {
    let result = run_backlogged_sessions(64, 10_000, 300_000_000);
    assert!(result.jain_fairness >= 0.90);
    assert!(result.sessions.iter().all(|s| s.scheduled_packets > 0));
}

#[test]
fn one_busy_session_uses_idle_capacity() {
    let result = run_weighted_sessions(&[("busy", 1, true), ("idle", 1, false)], 1_000);
    assert!(result.rate("busy") > 0);
    assert_eq!(result.rate("idle"), 0);
}

#[test]
fn token_bucket_never_exceeds_rate_plus_burst() {
    let result = run_pacer(300_000_000, 1_000_000);
    assert!(result.wire_bytes <= 37_500_000 + result.burst_bytes as u64);
    assert_eq!(result.busy_wait_iterations, 0);
}
```

- [ ] **Step 3: Run the scheduler tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests::expired_data_is_removed_before_capacity_rejection
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests::sixty_four_equal_sessions_are_fair
```

Expected: compilation fails because the scheduler module and fixtures do not exist.

- [ ] **Step 4: Implement scheduler identities, admission, and DRR**

Use these shared identities and commands:

```rust
#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub enum SchedulerScopeKey {
    Client { forward_id: u64 },
    Sidecar { forward_id: u64, target_host: String, target_port: u16, token_fingerprint: String },
}

#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub struct SchedulerSessionKey {
    pub scope_key: SchedulerScopeKey,
    pub session_id: u64,
    pub direction: Direction,
    pub process_epoch: u64,
}

pub enum TrafficClass { WireGuardHandshake, Regular }

pub struct AdmittedDatagram {
    pub reservation_id: u64,
    pub session: SchedulerSessionKey,
    pub payload: Bytes,
    pub payload_bytes: usize,
    pub wire_bytes: usize,
    pub enqueue_us: u64,
    pub deadline_us: u64,
    pub class: TrafficClass,
}
```

`process_epoch` is generated once per runtime process and is local-only; it is
not added to the wire Auth JSON. `connection_epoch` is generated for each TCP
connection, is sent in Auth/AuthAck/progress, and changes on every reconnect.

The scheduler exclusively owns `HashMap<SchedulerSessionKey, SessionQueue>`, an active-session `VecDeque`, global counts, and deficit. Charge full wire bytes, cap deficit at four weighted quanta, delete empty/idle session state, and never exceed any configured packet/byte/session bound.

Reserve handshake admission inside, not above, the global hard bounds. Compute
`reserved_handshake_packets = min(control_queue_packets,
global_queue_packets / 8)` and `reserved_handshake_bytes =
min(per_session_queue_bytes, global_queue_bytes / 8)`. Regular data may consume
only the nonreserved portion; recognized WireGuard types 1/2/3 may consume the
full global bound. Protocol control frames use separate bounded per-leg control
queues and never consume scheduler data quota.

When demand is above capacity, apply the exact drop order: purge expired frames
from the affected session; purge other globally expired frames; reject newly
offered regular data with `drop_session_queue_full` or `drop_root_queue_full`;
preserve the reserved handshake/control quota. Never claim to classify encrypted
inner TCP ACKs, and never replay a frame after a leg failure in this version.

- [ ] **Step 5: Implement an integer token bucket with a wake deadline**

Use `u128` arithmetic so refill rounding does not lose tokens:

```rust
struct RootPacer {
    rate_bps: u64,
    tokens_bytes: u64,
    burst_bytes: u64,
    refill_remainder: u128,
    last_refill_ns: u64,
}

impl RootPacer {
    fn refill(&mut self, now_ns: u64);
    fn try_consume(&mut self, wire_bytes: usize, now_ns: u64) -> Result<(), u64>;
}
```

On insufficient tokens, return the exact next eligible monotonic nanosecond. The Tokio actor will sleep until that instant or wake early for a command; the pure core never sleeps or reads wall clock.

- [ ] **Step 6: Export and verify the scheduler core**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests
git diff --check
git status --short
```

Expected: bounds, expiry, DRR, fairness, and pacing tests pass; no busy-loop path exists.

---

### Task 6: Add Feedback Windows, Chunk Placement, And Leg Health

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/scheduler.rs`

- [ ] **Step 1: Write failing chunk and multi-leg tests**

```rust
#[test]
fn one_session_aggregates_across_multiple_healthy_legs() {
    let mut s = feedback_fixture(&[(1, 100_000_000), (2, 100_000_000)]);
    admit_fixed_frames(&mut s, "one", 128, 1_024);
    let assignments = drain_assignments(&mut s);
    assert!(assignments.iter().any(|x| x.leg_id == 1));
    assert!(assignments.iter().any(|x| x.leg_id == 2));
}

#[test]
fn chunk_closes_at_target_without_splitting_a_datagram() {
    let mut s = feedback_fixture(&[(1, 300_000_000)]);
    admit_payloads(&mut s, "one", &[10_000, 10_000, 20_000]);
    let chunks = drain_chunks(&mut s);
    assert_eq!(chunks[0].payload_bytes, 40_000);
    assert_eq!(chunks[0].frame_count, 3);
    assert!(chunks[0].payload_bytes <= 65_536);
}

#[test]
fn deadline_pressure_closes_a_sub_minimum_chunk() {
    let mut s = feedback_fixture(&[(1, 300_000_000)]);
    s.admit(frame_with_deadline("one", 1_000, 2_000));
    s.set_now_us(1_999);
    assert_eq!(s.next_chunk().unwrap().payload_bytes, 1_000);
}
```

Include a 65,535-byte datagram case proving it is scheduled whole and does not starve even though it exceeds `chunk_target_bytes`.

- [ ] **Step 2: Write failing window and predicted-finish tests**

```rust
#[test]
fn decoded_progress_does_not_release_udp_accepted_credit() {
    let mut s = feedback_fixture(&[(1, 100_000_000)]);
    let sent = schedule_one(&mut s, "one", 32_000, 1);
    s.on_progress(1, progress(1, sent.wire_end, 0)).unwrap();
    assert_eq!(s.leg(1).outstanding_wire_bytes, sent.wire_bytes);
    s.on_progress(1, progress(1, sent.wire_end, sent.wire_end)).unwrap();
    assert_eq!(s.leg(1).outstanding_wire_bytes, 0);
}

#[test]
fn predicted_finish_avoids_a_delayed_leg() {
    let mut s = feedback_fixture(&[(1, 200_000_000), (2, 200_000_000)]);
    seed_feedback(&mut s, 1, 20, 200_000_000);
    seed_feedback(&mut s, 2, 80, 200_000_000);
    assert_eq!(schedule_one(&mut s, "one", 16_000, 1).leg_id, 1);
}

#[test]
fn dynamic_window_is_bdp_bounded() {
    assert_eq!(target_window(200_000_000, 20, 262_144, 4_194_304), 750_000);
    assert_eq!(target_window(1_000_000, 1, 262_144, 4_194_304), 262_144);
    assert_eq!(target_window(10_000_000_000, 1_000, 262_144, 4_194_304), 4_194_304);
}

#[test]
fn partial_negotiation_keeps_the_whole_scope_on_legacy() {
    let mut s = configured_feedback_fixture(&[(1, true), (2, false)]);
    assert_eq!(s.scope_mode("scope-a"), EffectiveSchedulerMode::Legacy);
    s.on_auth_ack(2, matching_ack(2));
    assert_eq!(s.scope_mode("scope-a"), EffectiveSchedulerMode::FeedbackDrr);
    s.on_leg_reconnected_without_ack(1, 99);
    assert_eq!(s.scope_mode("scope-a"), EffectiveSchedulerMode::Legacy);
}
```

`target_window` takes delivery bits per second and RTT milliseconds, adds the 10 ms margin, converts to bytes, and clamps to configured bounds.

- [ ] **Step 3: Write failing leg-state tests with virtual time**

```rust
#[test]
fn lagging_leg_stops_bulk_then_recovers_after_three_probes() {
    let mut s = feedback_fixture(&[(1, 200_000_000), (2, 200_000_000)]);
    seed_feedback(&mut s, 1, 20, 200_000_000);
    seed_feedback(&mut s, 2, 20, 200_000_000);
    repeat_feedback_periods(&mut s, 3, |s| make_leg_stale(s, 2, 120));
    assert_eq!(s.leg(2).state, LegState::Lagging);
    assert!(!s.bulk_eligible(2));
    make_leg_stale(&mut s, 2, 501);
    assert_eq!(s.leg(2).state, LegState::Quarantined);
    s.advance_ms(1_000);
    assert_eq!(s.leg(2).state, LegState::Probe);
    for _ in 0..3 { complete_healthy_probe(&mut s, 2); }
    assert_eq!(s.leg(2).state, LegState::Healthy);
}
```

Add independent cases for `write_stall > 10 ms`, window-without-progress, read/write failure, and `max(2*srtt,100 ms)`/`max(4*srtt,500 ms)` thresholds.

- [ ] **Step 4: Run the new tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests::one_session_aggregates_across_multiple_healthy_legs
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests::lagging_leg_stops_bulk_then_recovers_after_three_probes
```

Expected: compilation fails on missing chunks, windows, and leg-state APIs.

- [ ] **Step 5: Implement chunk formation and predicted-finish ranking**

Add:

```rust
pub enum LegState { Connecting, Warmup, Healthy, Lagging, Quarantined, Probe, LegacyFallback }

pub enum EffectiveSchedulerMode { Legacy, FeedbackDrr }

pub struct LegScheduleState {
    pub leg_id: u64,
    pub state: LegState,
    pub outstanding_wire_bytes: u64,
    pub target_window_bytes: u64,
    pub delivery_rate_ewma_bps: u64,
    pub progress_srtt_us: u64,
    pub progress_samples: u32,
    pub last_progress_us: u64,
    pub write_stall_started_us: Option<u64>,
    pub connection_epoch: u64,
}

pub struct ChunkAssignment {
    pub leg_id: u64,
    pub reservation_ids: Vec<u64>,
    pub payload_bytes: usize,
    pub wire_bytes: usize,
}
```

Close chunks on target, queue exhaustion, deadline pressure, leg state change, or `chunk_max_bytes`. Rank eligible legs by `outstanding / max(rate, bootstrap_rate) + srtt/2 + write_stall_penalty`; reject a candidate over the healthy median by `finish_skew_budget_ms` unless no other leg can meet the oldest frame deadline.

- [ ] **Step 6: Implement cumulative progress credit and state transitions**

Each leg owns a FIFO of sent records:

```rust
struct SentRecord {
    cumulative_wire_end: u64,
    reservation_id: u64,
    session: SchedulerSessionKey,
    wire_bytes: usize,
}
```

Move records to `RemoteDecoded` when crossed by `decoded_wire_bytes`; release remote outstanding only when crossed by `udp_accepted_wire_bytes`. Enable dynamic windows after two valid RTT/rate samples; before that use a 256 KiB warmup window and 16 KiB probes. Enforce the 8 MiB total window cap before every assignment.

- [ ] **Step 7: Preserve legacy selection as an explicit adapter**

When `scheduler_mode=legacy`, route frames through an adapter that calls the
imported `select_least_backlogged_after` and `LegQueueBudget`. Add a parity test
with the same candidate vectors as `queue.rs`; assert identical chosen leg IDs
and queue-full results. In configured `feedback_drr`, every active leg is tracked
as feedback-capable or `LegacyFallback`, but the entire scheduling scope uses the
legacy adapter until all active legs have matching Acks. Promote atomically when
all qualify; demote on an unacknowledged reconnect. Never compare an opaque
legacy leg with a feedback leg in predicted-finish ranking.

- [ ] **Step 8: Verify all scheduler behavior**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests
git diff --check
git status --short
```

Expected: chunk, multi-leg, window, state, and legacy-parity tests pass.

---

### Task 7: Make Reservation Ownership Cancellation-Safe And Exactly-Once

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/scheduler.rs`
- Modify: `wstunnel-main/leikwan-uot/src/telemetry.rs`

- [ ] **Step 1: Add failing lifecycle tests**

```rust
#[test]
fn writer_failure_releases_each_userspace_reservation_once() {
    let mut s = feedback_fixture(&[(1, 100_000_000)]);
    let ids = schedule_chunk(&mut s, "one", &[1_000, 1_000]);
    s.on_writer_closed(1, ids.clone());
    s.on_writer_closed(1, ids);
    assert_eq!(s.userspace_pending_bytes(), 0);
    assert_eq!(s.metrics().drops(DropReason::WriterClosed), 2);
    assert_eq!(s.metrics().duplicate_terminal_events, 2);
}

#[test]
fn disconnect_releases_assigned_records_but_not_other_legs() {
    let mut s = feedback_fixture(&[(1, 100_000_000), (2, 100_000_000)]);
    let a = schedule_on(&mut s, 1, "a", 1_000);
    let b = schedule_on(&mut s, 2, "b", 1_000);
    s.on_leg_disconnected(1);
    assert!(s.reservation(a).is_terminal());
    assert!(!s.reservation(b).is_terminal());
}

#[test]
fn invalid_progress_cannot_release_credit() {
    let mut s = feedback_fixture(&[(1, 100_000_000)]);
    let id = schedule_on(&mut s, 1, "a", 1_000);
    assert!(s.on_progress(1, regressing_progress()).is_err());
    assert_eq!(s.reservation(id).stage, ReservationStage::QueuedToLeg);
    assert_eq!(s.metrics().drops(DropReason::InvalidProgress), 1);
}
```

- [ ] **Step 2: Run the tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests::writer_failure_releases_each_userspace_reservation_once
```

Expected: fails because the explicit reservation ledger and idempotent terminal transitions do not exist.

- [ ] **Step 3: Implement the reservation ledger**

Use explicit ownership inside the scheduler actor:

```rust
pub enum ReservationStage {
    Admitted, Queued, Writing, LocalSocketAccepted,
    RemoteDecoded, RemoteUdpAccepted, Dropped(DropReason),
}

struct ReservationRecord {
    id: u64,
    session: SchedulerSessionKey,
    leg_id: Option<u64>,
    payload_bytes: usize,
    wire_bytes: usize,
    stage: ReservationStage,
    enqueue_us: u64,
}
```

Only the scheduler mutates the ledger. Every command carries reservation IDs. A transition validates the current state, updates counters once, and treats duplicate/late terminal events as no-ops plus `duplicate_terminal_events`. On leg disconnect, walk only that leg's bounded assigned-ID set; on session close, expire that session's queued IDs; on actor shutdown, close every nonterminal record once.

- [ ] **Step 4: Separate userspace pending from remote outstanding**

Calculate:

```text
userspace_pending = queued + assigned_to_writer + writing
remote_outstanding = local cumulative wire sent - matching remote UDP accepted wire
```

`LocalSocketAccepted` removes userspace pending but leaves remote outstanding. `RemoteUdpAccepted` removes outstanding. `RemoteDecoded` is a metric-only intermediate state. Writer channel close and write errors must not fabricate remote acceptance or replay data.

- [ ] **Step 5: Verify lifecycle accounting**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml scheduler::tests
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml telemetry::tests
git diff --check
git status --short
```

Expected: cancellation/error tests pass and all byte/packet counts return to zero after fixture shutdown.

---

### Task 8: Integrate Negotiation And Scheduling Into The Client Runtime

**Files:**
- Modify: `wstunnel-main/leikwan-uot/Cargo.toml`
- Modify: `wstunnel-main/leikwan-uot/src/client_runtime.rs`
- Create: `wstunnel-main/leikwan-uot/tests/feedback_runtime.rs`

- [ ] **Step 1: Add failing client-side mixed-version tests**

Build loopback fake-sidecar fixtures in `feedback_runtime.rs` and pin these outcomes:

```rust
#[tokio::test(start_paused = true)]
async fn new_client_sends_extended_auth_but_starts_in_legacy() {
    let peer = FakeSidecar::legacy().start().await;
    let runtime = start_client(feedback_config(peer.addr())).await;
    let auth = peer.next_auth().await;
    assert_eq!(auth["protocol_version"], 2);
    assert_eq!(auth["capabilities"], json!(["leg-progress-v1"]));
    tokio::time::advance(Duration::from_secs(1)).await;
    assert_eq!(runtime.leg_mode(1).await, LegState::LegacyFallback);
}

#[tokio::test(start_paused = true)]
async fn matching_auth_ack_enables_feedback_without_blocking_data() {
    let peer = FakeSidecar::feedback().start().await;
    let runtime = start_client(feedback_config(peer.addr())).await;
    peer.ack_matching_epoch().await;
    assert_eq!(runtime.leg_mode(1).await, LegState::Warmup);
    send_udp_datagram(runtime.listen_addr(), b"data").await;
    assert_eq!(peer.next_data().await.payload, b"data");
}

#[tokio::test(start_paused = true)]
async fn wrong_epoch_ack_is_counted_and_does_not_enable_feedback() {
    let peer = FakeSidecar::feedback().start().await;
    let runtime = start_client(feedback_config(peer.addr())).await;
    peer.ack_epoch(1).await;
    assert_eq!(runtime.leg_mode(1).await, LegState::LegacyFallback);
    assert_eq!(runtime.stats().await.drop_invalid_progress, 1);
}
```

Add a reconnect test proving a new connection epoch rejects delayed progress from the prior connection.
Add a late-negotiation test that advances past the one-second fallback timeout,
then delivers a matching `AuthAck` and proves the same live connection moves from
`LegacyFallback` to `Warmup` without dropping data.
Add a two-leg partial-negotiation test: one matching Ack plus one missing Ack
keeps the whole scope on legacy; the second matching Ack promotes it atomically;
reconnecting either leg with a new unacknowledged epoch demotes the scope.

- [ ] **Step 2: Run the integration tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test feedback_runtime new_client_sends_extended_auth_but_starts_in_legacy
```

Expected: test target fails to compile because the fake peer/runtime hooks and negotiation path do not exist.

Add Tokio's `test-util` feature alongside `full` in `Cargo.toml`; this is required
for `start_paused` and `tokio::time::advance`. Production time uses
`tokio::time::Instant`; pure scheduler tests continue to inject integer virtual
time.

- [ ] **Step 3: Replace direct UDP-to-leg dispatch with scheduler commands**

Create one bounded scheduler command channel and one scheduler task per client runtime:

```rust
pub enum SchedulerCommand {
    Admit { session: SchedulerSessionKey, payload: Bytes, class: TrafficClass, now_us: u64 },
    LegConnected { leg_id: u64, connection_epoch: u64, feedback_requested: bool },
    AuthAcknowledged { leg_id: u64, ack: AuthAck, now_us: u64 },
    Progress { leg_id: u64, progress: LegProgress, now_us: u64 },
    WriterStarted { leg_id: u64, reservation_ids: Vec<u64>, now_us: u64 },
    WriterAccepted { leg_id: u64, reservation_ids: Vec<u64>, cumulative_wire_bytes: u64, now_us: u64 },
    WriterFailed { leg_id: u64, reservation_ids: Vec<u64>, now_us: u64 },
    LegDisconnected { leg_id: u64, connection_epoch: u64, now_us: u64 },
    Tick { now_us: u64 },
    Shutdown,
}
```

Replace the old anonymous inbound wrapper with exact leg identity and accounting:

```rust
struct InboundFrame {
    leg_id: u64,
    connection_epoch: u64,
    wire_bytes: usize,
    frame: DataFrame,
}
```

Calculate `wire_bytes` at framed read time as the four-byte stream length prefix
plus the exact encoded frame length. Never reconstruct decoded-wire progress from
payload length after merge/delivery.

UDP receive increments `offered`, classifies only WireGuard types 1/2/3 as handshake traffic, and sends `Admit` with `try_send`. Full actor input is attributed to the correct bounded drop reason; do not await indefinitely in the UDP receive loop.

- [ ] **Step 4: Add extended Auth and control-priority writer queues**

Preserve all existing Auth fields and append `protocol_version`, `capabilities`,
and a unique nonzero `connection_epoch`. Derive one `process_epoch` from Unix
nanoseconds, PID, and a fixed mixer at startup; derive each connection epoch by
combining it with a monotonic `AtomicU64` reconnect counter. Add tests proving
nonzero values and distinct epochs across reconnects without adding a random
number dependency. Give each leg a `control_tx` sized by
`control_queue_packets` and a data queue bounded by the existing leg
packet/byte budgets. The writer loop must use:

```rust
tokio::select! {
    biased;
    Some(control) = control_rx.recv() => write_control(control).await?,
    Some(chunk) = data_rx.recv() => write_chunk(chunk).await?,
    else => break,
}
```

Emit `WriterStarted` before writing. Emit `WriterAccepted` only after both length prefix and encoded frame bytes complete. On any write error emit `WriterFailed` for the current frame and `LegDisconnected` for the remaining bounded leg assignment set.

- [ ] **Step 5: Parse AuthAck/LegProgress without disturbing data delivery**

In each leg reader, route `AuthAck` and `LegProgress` to scheduler commands; keep Data/Open/Close/Keepalive behavior unchanged. A missing Ack never blocks Data. A valid matching Ack may enable feedback after the one-second fallback timeout without reconnecting. Reject mismatched/invalid control frames, increment `drop_invalid_progress`, and keep the previous leg mode/state.

- [ ] **Step 6: Check every local UDP send result**

Replace ignored `send_to` results with a helper that increments success counters only after the OS call succeeds:

```rust
async fn send_udp_checked(socket: &UdpSocket, payload: &[u8], peer: SocketAddr) -> io::Result<usize> {
    let sent = socket.send_to(payload, peer).await?;
    if sent != payload.len() {
        return Err(io::Error::new(io::ErrorKind::WriteZero, "short UDP send"));
    }
    Ok(sent)
}
```

Map raw OS errors to `UdpErrorKind`, close the reservation with `UdpEgressError`, and never blindly retry a data datagram.

After a Data frame is decoded from sidecar-to-client, track decoded cumulative
wire bytes on that leg. After `send_udp_checked` succeeds, track UDP-accepted
cumulative wire bytes and enqueue `LegProgress` with
`Direction::SidecarToClient` on the priority control queue at the configured
byte/frame/time threshold. This is the feedback consumed by the sidecar's
reverse-direction scheduler.

Update the existing periodic client stats JSON writer to include the full
`telemetry::StatsSnapshot`, feedback-capable/legacy leg counts, configured hard
bounds, current/high-water memory, and per-leg state/progress/window values while
retaining legacy keys.

- [ ] **Step 7: Verify the client runtime**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test feedback_runtime client_
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml client_runtime::tests
git diff --check
git status --short
```

Expected: legacy, matching-Ack, invalid-Ack, reconnect, queue-full, and UDP-error tests pass.

---

### Task 9: Integrate Conditional Ack, Progress, And Reverse Scheduling Into The Sidecar

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`
- Modify: `wstunnel-main/leikwan-uot/tests/feedback_runtime.rs`

- [ ] **Step 1: Add failing old/new sidecar interoperability tests**

```rust
#[tokio::test(start_paused = true)]
async fn old_client_to_new_sidecar_receives_no_unknown_frame() {
    let sidecar = start_new_sidecar(feedback_sidecar_config()).await;
    let mut client = FakeLegacyClient::connect(sidecar.addr()).await;
    client.send_plain_token_auth().await;
    tokio::time::advance(Duration::from_millis(20)).await;
    assert!(client.try_next_frame().await.is_none());
}

#[tokio::test(start_paused = true)]
async fn new_sidecar_acknowledges_only_requested_capability() {
    let sidecar = start_new_sidecar(feedback_sidecar_config()).await;
    let mut client = FakeNewClient::connect(sidecar.addr()).await;
    let epoch = client.send_extended_auth().await;
    let ack = client.next_auth_ack().await;
    assert_eq!(ack.connection_epoch, epoch);
    assert_eq!(ack.capabilities, vec!["leg-progress-v1"]);
}

#[tokio::test(start_paused = true)]
async fn progress_emits_on_bytes_frames_or_timer_whichever_is_first() {
    assert_eq!(progress_trigger(65_536, 1, 1), Trigger::Bytes);
    assert_eq!(progress_trigger(1, 32, 1), Trigger::Frames);
    assert_eq!(progress_trigger(1, 1, 5), Trigger::Timer);
}

#[tokio::test]
async fn auth_ack_is_written_before_any_progress_frame() {
    let sidecar = start_new_sidecar(feedback_sidecar_config()).await;
    let mut client = FakeNewClient::connect(sidecar.addr()).await;
    client.send_extended_auth_and_immediate_data().await;
    assert_eq!(client.next_frame().await.msg_type, MsgType::AuthAck);
    assert_eq!(client.next_frame().await.msg_type, MsgType::LegProgress);
}
```

Add both-direction data tests and a UDP egress failure case whose success counter remains unchanged.

- [ ] **Step 2: Run the focused tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test feedback_runtime old_client_to_new_sidecar_receives_no_unknown_frame
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test feedback_runtime progress_emits_on_bytes_frames_or_timer_whichever_is_first
```

Expected: tests fail because the sidecar has no conditional Ack or progress emitter.

- [ ] **Step 3: Parse all existing Auth shapes plus optional capability fields**

Keep plain-token Auth and legacy JSON Auth accepted. Deserialize optional fields with defaults:

```rust
#[derive(Deserialize)]
struct AuthPayload {
    token: String,
    endpoint_id: Option<u64>,
    weight: Option<u16>,
    #[serde(default)] protocol_version: Option<u8>,
    #[serde(default)] capabilities: Vec<String>,
    #[serde(default)] connection_epoch: Option<u64>,
}
```

Only enqueue `AuthAck` when the authenticated request has protocol version 2,
capability `leg-progress-v1`, and a nonzero epoch. Enqueue the Ack before the leg
is registered as progress-capable; FIFO order in the priority control queue then
guarantees Ack precedes any progress. Never send types 6/7 to a peer that did not
request the capability, and never send an Ack before authentication succeeds.

- [ ] **Step 4: Track decoded and UDP-accepted cumulative progress**

Per authenticated TCP leg maintain monotonic direction-specific counters. For
client-to-sidecar Data, increment decoded counters only after full frame decode
and accepted counters only after a successful full-length UDP `send`. Enqueue
`Direction::ClientToSidecar` progress at the first of 64 KiB decoded wire, 32
decoded Data frames, or 5 ms with unsent progress. Use `session_id=0`, `seq=0`,
fixed 44-byte payload, and the dedicated bounded control queue.

Parse inbound `Direction::SidecarToClient` `LegProgress` frames from a negotiated
client, validate them against that leg's epoch and locally sent totals, and send
valid events to the reverse scheduler. Invalid progress changes no window/state
and increments only the invalid-control denominator/counter.

- [ ] **Step 5: Route reverse UDP data through the same scheduler actor**

Construct `SchedulerSessionKey { scope_key: SchedulerScopeKey::Sidecar {
forward_id, target_host, target_port, token_fingerprint }, session_id,
direction: Direction::SidecarToClient, process_epoch }` from the existing
`ScopeKey` inputs and current session ID. The token fingerprint remains the
existing credential-free fingerprint string; no token enters scheduler state or
stats. Replace the direct `reserve_next_leg`/`try_send` reverse path with
`Admit`; retain the exact legacy adapter when the scope is not fully negotiated.

- [ ] **Step 6: Check connected UDP `send` and classify errors**

Only advance `remote_udp_accepted` after `socket.send(&payload).await` returns exactly `payload.len()`. Map `WouldBlock`, platform `ENOBUFS`, unreachable errors, closed socket, and other errors into the fixed categories. Do not replay failed data.

Update the sidecar stats JSON writer with the same complete telemetry snapshot
and legacy compatibility keys used by the client.

- [ ] **Step 7: Verify both directions and mixed versions**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test feedback_runtime
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml sidecar_runtime::tests
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
git diff --check
git status --short
```

Expected: old/new matrix and both directions pass; no unknown control frame reaches an old peer.

---

### Task 10: Propagate The Contract Through Linux Agents And The Panel

**Files:**
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `client-agent/tests/test_sidecar_dual_runtime_regression.py`
- Modify: `master/tests/agent_udp_uot_sidepath_management_test.php`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Add failing Linux client generator assertions**

Extend `test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit` with an input containing `scheduler_mode: feedback_drr` and assert the generated runtime JSON has all 22 flat fields from Task 2 (`scheduler_mode` plus the 21 numeric settings). Pin representative values:

```python
self.assertEqual(runtime["scheduler_mode"], "feedback_drr")
self.assertEqual(runtime["aggregate_rate_bps"], 300_000_000)
self.assertEqual(runtime["queue_deadline_ms"], 10)
self.assertEqual(runtime["global_queue_bytes"], 1_048_576)
self.assertEqual(runtime["chunk_target_bytes"], 32_768)
self.assertEqual(runtime["max_total_outstanding_bytes"], 8_388_608)
self.assertEqual(runtime["control_queue_packets"], 256)
```

Add:

```python
def test_feedback_scheduler_rejects_python_uot_fallback(self) -> None:
    config = feedback_agent_config(uot_bond_bin="/usr/local/bin/leikwan-uot-bond.py")
    with self.assertRaisesRegex(ValueError, "feedback_drr requires the Rust leikwan-uot-bond runtime"):
        render_udp_uot_aux_unit(sample_forward(), config)
```

- [ ] **Step 2: Add failing sidecar and PHP propagation assertions**

In the sidecar test, assert the generated `udp-uot-runtime.json` has the same flat fields and exact values. In the PHP test, add the fields to `$sidepath`, invoke all three provisioning paths, and assert strict array equality so client/node/sidecar cannot drift.

- [ ] **Step 3: Run focused tests and confirm RED**

Run:

```powershell
python -m unittest discover -s client-agent/tests -p test_mptcp_bond_regressions.py
python -m unittest discover -s client-agent/tests -p test_sidecar_dual_runtime_regression.py
php master/tests/agent_udp_uot_sidepath_management_test.php
```

Expected: new scheduler-field assertions fail; the Python fallback test fails because no validation exists.

- [ ] **Step 4: Add one canonical Python key/default table**

In `install_forward_runtime.py`, define:

```python
UOT_SCHEDULER_DEFAULTS = {
    "scheduler_mode": "legacy",
    "aggregate_rate_bps": 300_000_000,
    "queue_target_ms": 5,
    "queue_deadline_ms": 10,
    "global_queue_bytes": 1_048_576,
    "global_queue_packets": 8_192,
    "per_session_queue_bytes": 262_144,
    "per_session_queue_packets": 512,
    "drr_quantum_bytes": 16_384,
    "chunk_min_bytes": 16_384,
    "chunk_target_bytes": 32_768,
    "chunk_max_bytes": 65_536,
    "min_leg_window_bytes": 262_144,
    "max_leg_window_bytes": 4_194_304,
    "max_total_outstanding_bytes": 8_388_608,
    "progress_interval_bytes": 65_536,
    "progress_interval_frames": 32,
    "progress_interval_ms": 5,
    "finish_skew_budget_ms": 2,
    "probe_interval_ms": 1_000,
    "max_sessions": 4_096,
    "control_queue_packets": 256,
}
```

Normalize explicit integers and copy the keys flat into client runtime JSON. Permit only `legacy` and `feedback_drr`. Reject `.py` only for feedback mode; preserve Python fallback behavior for legacy.

- [ ] **Step 5: Mirror the exact contract in sidecar and panel output**

Use the same key names/default values in `sidecar_agent.py`. In `ProvisioningService::buildUdpUotSidepathConfig`, return sanitized values using `max`/bounded clamps matching Rust hard bounds and publish `scheduler_mode=legacy` unless the stored configuration explicitly requests `feedback_drr`. Do not add credentials, host secrets, or topology changes.

- [ ] **Step 6: Prove Linux no-op signatures include scheduler and native identity**

Add a test that changes only `udp_uot_sidepath.scheduler_mode`, then only
`aggregate_rate_bps`, then only the native UoT binary hash/build-info, and asserts
`forward_apply_is_noop` becomes false in each case. Change only runtime stats and
assert it remains true. A failed native capability probe must leave the prior
`contract_signature`, `rendered_signature`, unit, and JSON unchanged.

- [ ] **Step 7: Re-run all focused propagation tests**

Run:

```powershell
python -m unittest discover -s client-agent/tests -p test_mptcp_bond_regressions.py
python -m unittest discover -s client-agent/tests -p test_sidecar_dual_runtime_regression.py
php master/tests/agent_udp_uot_sidepath_management_test.php
git diff --check
git status --short
```

Expected: all focused tests pass; legacy output defaults to legacy mode; feedback output requires Rust.

---

### Task 11: Add Native Artifacts And Fail-Closed Runtime Selection

**Files:**
- Modify: `wstunnel-main/leikwan-uot/src/bin/leikwan-uot-bond.rs`
- Modify: `wstunnel-main/leikwan-uot/src/bin/leikwan-udp-uot-merge.rs`
- Create: `wstunnel-main/leikwan-uot/tests/build_info.rs`
- Modify: `client-agent/leikwan_uot_bond.py`
- Modify: `sidecar-agent/leikwan_udp_uot_merge.py`
- Modify: `client-agent/client_agent.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `artifacts/client-agent.py`
- Modify: `artifacts/sidecar-agent.py`
- Modify: `artifacts/install-forward-runtime.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `client-agent/tests/test_artifact_versions_consistency.py`
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-client.sh`
- Modify: `install/install-bond-sidecar.sh`
- Modify: `scripts/build-windows-client-setup.ps1`
- Modify: `scripts/tests/test_windows_client_setup_installer.py`
- Modify: `artifacts/versions.json`
- Modify: `master/src/Controllers/ArtifactController.php`
- Modify: `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`
- Create: `artifacts/wstunnel/leikwan-uot-bond_linux_amd64`
- Create: `artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64`
- Create: `artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe`
- Modify: `master/tests/installer_artifact_hash_verification_test.php`
- Modify: `master/tests/artifact_capability_hash_consistency_test.php`
- Modify: `master/tests/artifact_versions_manifest_route_test.php`
- Modify: `master/tests/artifact_source_build_provenance_guard_test.php`
- Create: `master/tests/uot_native_artifact_regression_test.php`

- [ ] **Step 1: Add failing native build-info tests**

Create an integration test that invokes both binaries without a config:

```rust
#[test]
fn binaries_report_the_feedback_capability_contract() {
    for (path, name) in [
        (env!("CARGO_BIN_EXE_leikwan-uot-bond"), "leikwan-uot-bond"),
        (env!("CARGO_BIN_EXE_leikwan-udp-uot-merge"), "leikwan-udp-uot-merge"),
    ] {
        let output = Command::new(path).arg("--build-info-json").output().unwrap();
        assert!(output.status.success());
        let value: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(value["binary"], name);
        assert_eq!(value["protocol_version"], 2);
        assert_eq!(value["capabilities"], json!(["leg-progress-v1"]));
        assert_eq!(value["scheduler_modes"], json!(["legacy", "feedback_drr"]));
    }
}
```

- [ ] **Step 2: Add failing Python fallback and capability-probe tests**

For both legacy Python runtimes, load a temporary JSON config containing
`"scheduler_mode": "feedback_drr"` and assert startup exits with the exact
message `feedback_drr requires the native Rust UoT runtime` before opening any
socket.

In `test_mptcp_bond_regressions.py`, add these cases:

```python
def test_legacy_python_runtime_receives_strict_legacy_json(self):
    unit = render_udp_uot_aux_unit(sample_forward(), legacy_python_agent_config())
    runtime = json.loads(unit["config_json"])
    self.assertNotIn("scheduler_mode", runtime)
    self.assertNotIn("aggregate_rate_bps", runtime)

def test_feedback_apply_refuses_native_binary_without_capability(self):
    with fake_binary_probe({"scheduler_modes": ["legacy"], "capabilities": []}):
        with self.assertRaisesRegex(ValueError, "native UoT runtime lacks feedback_drr/leg-progress-v1"):
            render_udp_uot_aux_unit(sample_forward(), feedback_agent_config())
```

Add client/sidecar-agent tests proving a failed/malformed/timed-out build-info
probe leaves the existing unit file, runtime JSON, PID, and applied signature
unchanged.

- [ ] **Step 3: Run the focused tests and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test build_info
python -m unittest discover -s client-agent/tests -p test_mptcp_bond_regressions.py
python -m unittest discover -s client-agent/tests -p test_sidecar_dual_runtime_regression.py
```

Expected: build-info CLI and fail-closed assertions fail.

- [ ] **Step 4: Implement a machine-readable native capability probe**

Both Rust binary CLIs accept mutually exclusive `--config PATH` and
`--build-info-json`. The latter prints one compact JSON object and exits before
Tokio runtime/socket setup:

```json
{"binary":"leikwan-uot-bond","version":"0.1.0","protocol_version":2,"capabilities":["leg-progress-v1"],"scheduler_modes":["legacy","feedback_drr"]}
```

The merge binary differs only in `binary`. A missing mode, both modes, or neither
mode is a clap error. Keep normal `--config` behavior unchanged.

- [ ] **Step 5: Make legacy Python loaders and generated JSON fail closed**

At the start of both Python runtime `run` paths, before socket creation, require:

```python
scheduler_mode = str(config.get("scheduler_mode", "legacy") or "legacy").strip().lower()
if scheduler_mode != "legacy":
    raise RuntimeError("feedback_drr requires the native Rust UoT runtime")
```

In `install_forward_runtime.py`, a `.py` runtime in legacy mode receives the
strict pre-scheduler JSON shape with no scheduler fields. A native binary receives
all 22 flat fields. Feedback mode requires a native path plus successful
`--build-info-json` within two seconds, exact protocol version 2,
`feedback_drr`, and `leg-progress-v1`.

- [ ] **Step 6: Include sidepath and binary identity in Linux no-op signatures**

Extend `ClientAgent.forward_contract_signature` runtime inputs with:

```python
"udp_uot_sidepath": config.get("udp_uot_sidepath"),
"uot_bond_bin": str(uot_bond_bin),
"uot_bond_sha256": file_sha256(uot_bond_bin),
"uot_bond_build_info": probe_uot_build_info(uot_bond_bin),
```

Normalize the sidepath through the same canonical key/default table used by the
renderer. A scheduler/default/binary hash/capability change must change the
contract signature and trigger one apply. Observation-only stats must not.

Before client or sidecar writes JSON/restarts a feedback runtime, repeat the
capability/hash check against the exact executable path. Failure records a
redacted apply error and preserves the last running unit/config/signature.

- [ ] **Step 7: Add and verify native artifact manifest entries**

Build Linux binaries with locked musl dependencies and the Windows client binary
with `x86_64-pc-windows-msvc`, copy them to the three exact artifact paths, then
add manifest entries with `version`, `path`, `artifact_url`, `sha256`,
`size_bytes`, `protocol_version: 2`, `capabilities: ["leg-progress-v1"]`, and
`scheduler_modes: ["legacy", "feedback_drr"]`.

Run:

```powershell
wsl bash -lc 'set -euo pipefail; cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/uot-feedback-scheduler-20260808/wstunnel-main; RUSTFLAGS=-Awarnings cargo build --locked --release --target x86_64-unknown-linux-musl -p leikwan-uot'
cargo build --locked --release --target x86_64-pc-windows-msvc -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
Copy-Item wstunnel-main/target/x86_64-unknown-linux-musl/release/leikwan-uot-bond artifacts/wstunnel/leikwan-uot-bond_linux_amd64
Copy-Item wstunnel-main/target/x86_64-unknown-linux-musl/release/leikwan-udp-uot-merge artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64
Copy-Item wstunnel-main/target/x86_64-pc-windows-msvc/release/leikwan-uot-bond.exe artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe
```

If WSL uses its default target directory rather than the Windows-mounted
`wstunnel-main/target`, copy from the exact path printed by Cargo's build output;
do not search other build/rebuild directories for a similarly named binary.

Use generic `/artifacts/wstunnel/{filename}` serving; no special secret-bearing
route is added. The manifest tests must recompute size/hash from the files and
invoke `--build-info-json` to compare capability metadata.

Add the native client Linux/Windows entries to `clientManifest` and the native
merge Linux entry to `sidecarManifest`, while retaining legacy Python entries for
rollback. Route tests must prove each advertised URL returns the exact manifest
hash and size.

Add all three native keys to `LeigodOnlyArtifactSourceBuildMap` with source path
`wstunnel-main/leikwan-uot`, exact artifact output path, route path, and Linux or
Windows platform family. Extend provenance tests so source-fingerprint or
build-output-hash drift blocks activation metadata. The new native artifact
regression test checks both Linux files for ELF/static/no `GLIBC_`, the Windows
file for PE magic, manifest hash/size, and host-appropriate build-info output.

Synchronize the established source mirrors byte-for-byte:

```powershell
Copy-Item client-agent/client_agent.py artifacts/client-agent.py
Copy-Item sidecar-agent/sidecar_agent.py artifacts/sidecar-agent.py
Copy-Item client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py
```

Bump the changed client agent, sidecar agent, and installer versions to the
repo's `2026.08.08.N` format, then recompute every changed manifest entry's
SHA-256 and size. Also add hash/size for the modified legacy Python UoT entries;
do not leave blank hashes that would permit an unverified download.

- [ ] **Step 8: Update Linux installers with verified atomic native installs**

`install-client-agent.sh` and `install-client.sh` install
`leikwan-uot-bond_linux_amd64` as `/usr/local/bin/leikwan-uot-bond`.
`install-bond-sidecar.sh` installs `leikwan-udp-uot-merge_linux_amd64` as
`/usr/local/bin/leikwan-udp-uot-merge`. Reuse the manifest-aware protected
download, expected SHA-256, temporary file, `--build-info-json`, then atomic
rename pattern. Keep Python files for legacy rollback; never point feedback mode
at them.

- [ ] **Step 9: Make the Windows setup consume only the manifest artifact**

Change the setup builder's default UoT input to
`artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe`. Remove fallback selection
from old `target/release` and `artifacts/windows/rebuild_payload` paths. Require
the expected manifest SHA-256 and successful build-info contract before staging
the EXE. Extend installer tests so a stale, wrong-hash, or legacy-only binary
fails without replacing the prior setup payload.

- [ ] **Step 10: Verify artifact, installer, and fallback behavior**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test build_info
python -m unittest discover -s client-agent/tests -p test_artifact_versions_consistency.py
python -m unittest discover -s client-agent/tests -p test_mptcp_bond_regressions.py
python scripts/tests/test_windows_client_setup_installer.py
php master/tests/installer_artifact_hash_verification_test.php
php master/tests/artifact_capability_hash_consistency_test.php
php master/tests/artifact_versions_manifest_route_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
php master/tests/uot_native_artifact_regression_test.php
git diff --check
git status --short
```

Expected: native metadata matches artifact bytes, Python fallback is legacy-only,
and every feedback selection is fail-closed without replacing a running runtime.

---

### Task 12: Add Windows Configuration And Runtime-JSON Parity

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UdpUotSidepathConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/UotRuntimeCapabilityProbe.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`

- [ ] **Step 1: Add a failing Windows payload-parity test**

Extend the existing UoT runtime test JSON with the feedback fields and assert the generated process config:

```csharp
using var doc = JsonDocument.Parse(udpRuntime.ConfigJson!);
var root = doc.RootElement;
Assert.Equal("feedback_drr", root.GetProperty("scheduler_mode").GetString());
Assert.Equal(300_000_000L, root.GetProperty("aggregate_rate_bps").GetInt64());
Assert.Equal(5, root.GetProperty("queue_target_ms").GetInt32());
Assert.Equal(10, root.GetProperty("queue_deadline_ms").GetInt32());
Assert.Equal(1_048_576, root.GetProperty("global_queue_bytes").GetInt32());
Assert.Equal(8_192, root.GetProperty("global_queue_packets").GetInt32());
Assert.Equal(262_144, root.GetProperty("per_session_queue_bytes").GetInt32());
Assert.Equal(512, root.GetProperty("per_session_queue_packets").GetInt32());
Assert.Equal(16_384, root.GetProperty("drr_quantum_bytes").GetInt32());
Assert.Equal(32_768, root.GetProperty("chunk_target_bytes").GetInt32());
Assert.Equal(8_388_608, root.GetProperty("max_total_outstanding_bytes").GetInt32());
Assert.Equal(256, root.GetProperty("control_queue_packets").GetInt32());
```

Add a second case omitting every scheduler key and assert `scheduler_mode == "legacy"` and all approved defaults.
Add a signature case proving one scheduler-field change changes the contract
signature, while stats/observation changes do not. Add a polling case proving a
new scheduler contract reapplies a running forward exactly once.

- [ ] **Step 2: Run the test and confirm RED**

Run:

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~BondRuntimeBuilderTests
```

Expected: the new property assertions fail because the model/builder drops the fields.

- [ ] **Step 3: Add strongly typed model properties**

Add `[JsonPropertyName]` properties for all Task 2 fields. Use `long` for `aggregate_rate_bps`; use `int` for the bounded byte/packet/time values. Defaults must match the canonical Python/Rust table. For example:

```csharp
[JsonPropertyName("scheduler_mode")]
public string SchedulerMode { get; set; } = "legacy";

[JsonPropertyName("aggregate_rate_bps")]
public long AggregateRateBps { get; set; } = 300_000_000;

[JsonPropertyName("queue_target_ms")]
public int QueueTargetMs { get; set; } = 5;

[JsonPropertyName("control_queue_packets")]
public int ControlQueuePackets { get; set; } = 256;
```

Define every intervening field in the same order as `SchedulerConfig` to make parity review mechanical.

- [ ] **Step 4: Emit every scheduler field flat in `BuildUdpUotRuntimePayload`**

Add one dictionary entry per property, with exact snake-case keys. Do not change route suffix, endpoint host/port, credential fields, leg weights, or `client -> slave -> sidecar` addressing.

- [ ] **Step 5: Include sidepath and native identity in Windows signatures**

Change the signature API to:

```csharp
public static string Compute(
    ForwardConfig forward,
    UdpUotSidepathConfig? udpUotSidepath = null,
    string uotBinaryIdentity = "")
```

Serialize all scheduler properties in stable declared order plus the exact
native binary SHA-256/build-info identity. Update `ForwardRuntimeManager` and
polling call sites to pass the active sidepath and probed identity. Endpoint
ordering remains normalized. A sidepath or binary identity change must defeat
the no-op signature; runtime stats must not.

- [ ] **Step 6: Fail closed before stopping a Windows feedback runtime**

`UotRuntimeCapabilityProbe` runs the selected EXE with `--build-info-json`, a
two-second timeout, no shell, captured stdout/stderr, and a 64 KiB output limit.
It requires protocol 2, scheduler mode `feedback_drr`, and capability
`leg-progress-v1`. `ForwardRuntimeManager` performs the probe and SHA-256 before
stopping/replacing any process or JSON. On failure, retain the old runtime and
applied signature and surface a redacted apply error. Legacy mode does not
require feedback capability.

- [ ] **Step 7: Re-run Windows tests**

Run:

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~BondRuntimeBuilderTests|FullyQualifiedName~ForwardContractSignatureTests|FullyQualifiedName~ConfigPollingServiceTests"
git diff --check
git status --short
```

Expected: feedback and legacy-default payload cases pass, sidepath changes trigger
one reapply, invalid native capabilities preserve the old process, and Windows
runtime JSON remains byte-for-value consistent with Linux output.

---

### Task 13: Add Deterministic High-Concurrency Scheduler Tests

**Files:**
- Create: `wstunnel-main/leikwan-uot/tests/scheduler_load.rs`

- [ ] **Step 1: Add a failing 60-second virtual 64-session test**

```rust
#[test]
fn sixty_four_sessions_sustain_fair_bounded_virtual_load() {
    let result = SchedulerLoad::new()
        .duration(Duration::from_secs(60))
        .offered_rate_bps(300_000_000)
        .sessions(64)
        .legs(5)
        .datagram_bytes(1_200)
        .run();
    assert!(result.useful_rate_bps >= 270_000_000);
    assert!(result.queue_delay_p99 <= Duration::from_millis(10));
    assert!(result.scheduler_drop_ratio <= 0.001);
    assert!(result.jain_fairness >= 0.90);
    assert_eq!(result.zero_rate_seconds, 0);
    assert_eq!(result.memory_bound_violations, 0);
}
```

The harness advances integer virtual time to the next enqueue, pacer wake, progress event, or deadline; it must not sleep for 60 wall-clock seconds.

- [ ] **Step 2: Add a failing single-session multi-leg and impairment matrix**

```rust
#[test]
fn one_tunnel_session_uses_all_healthy_legs_without_cross_session_stall() {
    let result = SchedulerLoad::new()
        .duration(Duration::from_secs(60))
        .offered_rate_bps(300_000_000)
        .sessions(1)
        .synthetic_inner_flows(32)
        .legs(5)
        .run();
    assert!(result.active_legs >= 2);
    assert_eq!(result.zero_rate_seconds, 0);
    assert!(result.queue_delay_p99 <= Duration::from_millis(10));
}

#[test]
fn delayed_stalled_and_reconnected_legs_are_bounded() {
    for impairment in [
        Impairment::DelayMs(1), Impairment::DelayMs(5), Impairment::DelayMs(10),
        Impairment::LossPermille(1), Impairment::LossPermille(5),
        Impairment::StallMs(500), Impairment::DisconnectReconnect,
        Impairment::FeedbackDelayMs(10),
    ] {
        let result = run_impaired(impairment);
        assert_eq!(result.zero_rate_seconds, 0, "{impairment:?}");
        assert_eq!(result.memory_bound_violations, 0, "{impairment:?}");
        assert_eq!(result.udp_send_errors, 0, "{impairment:?}");
        assert!(!result.cross_session_stall, "{impairment:?}");
    }
}
```

- [ ] **Step 3: Run and confirm RED**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test scheduler_load
```

Expected: compilation fails because `SchedulerLoad` and deterministic link events are not implemented.

- [ ] **Step 4: Implement the pure deterministic harness against public scheduler events**

The harness must use production `SchedulerConfig`, `SchedulerCommand`, progress validation, chunk placement, and reservation transitions. It may provide fake writer/remote-link adapters, but must not duplicate the scheduling algorithm. Record per-second useful bytes, per-session rates, queue delays, drops, state transitions, and high-water marks.

For 0.1%/0.5% deterministic loss, drop every 1000th/200th eligible frame with a fixed starting offset. For delays, add exact virtual delivery time. For stall, hold one leg's progress events for 500 ms. For reconnect, increment epoch and inject one stale prior-epoch progress frame to prove rejection.

- [ ] **Step 5: Run load tests twice for determinism**

Run:

```powershell
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test scheduler_load -- --nocapture
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test scheduler_load -- --nocapture
git diff --check
git status --short
```

Expected: both runs pass with identical aggregate counters and state-transition totals.

---

### Task 14: Build The 60-Second Acceptance And Evidence Tooling

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/bin/leikwan-uot-qa.rs`
- Create: `wstunnel-main/leikwan-uot/tests/qa_cli.rs`
- Create: `tools/uot-feedback/acceptance.py`
- Create: `tools/uot-feedback/test_acceptance.py`
- Create: `tools/uot-feedback/netns.sh`
- Create: `tools/uot-feedback/manifests/local-netns.json`

- [ ] **Step 1: Write failing gate-calculation tests**

Use a minimal manifest with explicit measurement files:

```python
PASSING = {
    "duration_seconds": 60,
    "mode": "feedback_drr",
    "pair_id": "normal-64-a",
    "workload": "sessions64",
    "offered_rate_bps": 300_000_000,
    "useful_rate_bps": 272_000_000,
    "queue_p99_ms": 9.0,
    "scheduler_drop_ratio": 0.0009,
    "udp_send_errors": 0,
    "zero_rate_seconds": 0,
    "jain_fairness": 0.91,
    "memory_bound_violations": 0,
    "receiver_loss_ratio": 0.004,
    "loaded_rtt_p99_ms": 35.0,
    "inner_tcp_retransmissions": 10,
    "direction": "client_to_sidecar",
    "datagram_bytes": 1_200,
    "topology_id": "netns-five-leg",
    "active_legs": 5,
    "feedback_capable_active_legs": 5,
    "legacy_fallback_active_legs": 0,
}

class GateTests(unittest.TestCase):
    def test_absolute_gates_pass(self):
        self.assertEqual(evaluate_absolute(PASSING), [])

    def test_each_boundary_failure_is_named(self):
        for key, value in {
            "useful_rate_bps": 269_999_999,
            "queue_p99_ms": 10.01,
            "scheduler_drop_ratio": 0.00101,
            "udp_send_errors": 1,
            "zero_rate_seconds": 1,
            "jain_fairness": 0.899,
            "memory_bound_violations": 1,
            "feedback_capable_active_legs": 4,
            "legacy_fallback_active_legs": 1,
        }.items():
            case = PASSING | {key: value}
            self.assertIn(key, evaluate_absolute(case))

    def test_feedback_must_not_regress_paired_legacy(self):
        legacy = PASSING | {"mode": "legacy", "receiver_loss_ratio": 0.003, "loaded_rtt_p99_ms": 20.0, "inner_tcp_retransmissions": 9}
        failures = evaluate_pair(PASSING, legacy)
        self.assertEqual(failures, ["receiver_loss_ratio", "loaded_rtt_p99_ms", "inner_tcp_retransmissions"])
```

- [ ] **Step 2: Run and confirm RED**

Run:

```powershell
python tools/uot-feedback/test_acceptance.py -v
```

Expected: import fails because `acceptance.py` does not exist.

- [ ] **Step 3: Add a failing native high-rate QA CLI test**

Pin the non-network contract first:

```rust
#[test]
fn qa_cli_requires_bounded_formal_parameters() {
    let output = Command::new(env!("CARGO_BIN_EXE_leikwan-uot-qa"))
        .args(["send", "--sessions", "64", "--rate-bps", "300000000",
            "--duration-seconds", "60", "--payload-bytes", "1200",
            "--target", "127.0.0.1:9", "--dry-run-json"])
        .output().unwrap();
    assert!(output.status.success());
    let value: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(value["sessions"], 64);
    assert_eq!(value["rate_bps"], 300_000_000_u64);
    assert_eq!(value["duration_seconds"], 60);
}
```

Add rejection cases for zero sessions/rate/payload, payload above 65,535, and a
formal duration other than 60 seconds.

- [ ] **Step 4: Implement strict manifest parsing and gate evaluation**

`acceptance.py` must implement these concrete functions:

```python
import hashlib
import json
import math
import pathlib

REQUIRED = {
    "duration_seconds", "mode", "pair_id", "workload", "offered_rate_bps",
    "useful_rate_bps", "queue_p99_ms", "scheduler_drop_ratio",
    "udp_send_errors", "zero_rate_seconds", "jain_fairness",
    "memory_bound_violations", "receiver_loss_ratio", "loaded_rtt_p99_ms",
    "inner_tcp_retransmissions", "direction", "datagram_bytes", "topology_id",
    "active_legs", "feedback_capable_active_legs", "legacy_fallback_active_legs",
}

def load_manifest(path: pathlib.Path) -> dict:
    run = json.loads(path.read_text(encoding="utf-8"))
    missing = sorted(REQUIRED - run.keys())
    if missing:
        raise ValueError(f"missing manifest keys: {', '.join(missing)}")
    for key, value in run.items():
        if isinstance(value, float) and not math.isfinite(value):
            raise ValueError(f"non-finite value: {key}")
    return run

def evaluate_absolute(run: dict) -> list[str]:
    checks = {
        "duration_seconds": run["duration_seconds"] == 60,
        "offered_rate_bps": run["offered_rate_bps"] == 300_000_000,
        "useful_rate_bps": run["useful_rate_bps"] >= 270_000_000,
        "queue_p99_ms": run["queue_p99_ms"] <= 10.0,
        "scheduler_drop_ratio": run["scheduler_drop_ratio"] <= 0.001,
        "udp_send_errors": run["udp_send_errors"] == 0,
        "zero_rate_seconds": run["zero_rate_seconds"] == 0,
        "jain_fairness": run["jain_fairness"] >= 0.90,
        "memory_bound_violations": run["memory_bound_violations"] == 0,
        "feedback_capable_active_legs": run["mode"] != "feedback_drr" or run["feedback_capable_active_legs"] == run["active_legs"],
        "legacy_fallback_active_legs": run["mode"] != "feedback_drr" or run["legacy_fallback_active_legs"] == 0,
    }
    return [key for key, passed in checks.items() if not passed]

def evaluate_pair(feedback: dict, legacy: dict) -> list[str]:
    identity = ("pair_id", "workload", "direction", "datagram_bytes", "topology_id")
    if any(feedback[key] != legacy[key] for key in identity):
        raise ValueError("paired runs do not share workload identity")
    failures = []
    if feedback["receiver_loss_ratio"] > legacy["receiver_loss_ratio"] or feedback["receiver_loss_ratio"] > 0.005:
        failures.append("receiver_loss_ratio")
    if feedback["loaded_rtt_p99_ms"] - legacy["loaded_rtt_p99_ms"] > 10.0:
        failures.append("loaded_rtt_p99_ms")
    if feedback["inner_tcp_retransmissions"] > legacy["inner_tcp_retransmissions"]:
        failures.append("inner_tcp_retransmissions")
    return failures

def sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()

def write_report(runs: list[dict], output: pathlib.Path) -> None:
    report = {"runs": runs, "all_passed": all(not run["failures"] for run in runs)}
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
```

Implement without third-party Python packages. Reject missing keys, non-60-second runs, offered rates other than 300,000,000 for formal normal-path gates, non-finite numbers, mismatched pair topology/direction/datagram size, and duplicate evidence paths. `evaluate_pair` fails when feedback receiver loss is above legacy, receiver loss exceeds the 0.5% target, p99 RTT regresses by more than 10 ms, or inner TCP retransmissions exceed legacy.

The CLI must support:

```text
python tools/uot-feedback/acceptance.py evaluate --manifest RUN.json --legacy LEGACY.json --output REPORT.json
python tools/uot-feedback/acceptance.py hash --root QA_DIRECTORY --output SHA256SUMS
python tools/uot-feedback/acceptance.py run-matrix --topology tools/uot-feedback/manifests/local-netns.json --artifact-dir QA_DIRECTORY --seed INTEGER
```

`run-matrix` builds all legacy/feedback pairs, randomizes order with the recorded
seed, calls only the bounded `netns.sh` actions, stops on a hard safety failure,
and evaluates/hashes each completed pair. Python orchestrates processes and
parses evidence; it does not generate the 300 Mbps UDP workload.

- [ ] **Step 5: Implement the native 64-session generator/receiver**

`leikwan-uot-qa` provides:

```text
leikwan-uot-qa send --target HOST:PORT --sessions 64 --rate-bps 300000000 --duration-seconds 60 --payload-bytes 1200 --json PATH
leikwan-uot-qa recv --bind HOST:PORT --duration-seconds 60 --json PATH
```

`send` binds one UDP socket per session, uses an integer aggregate token bucket,
round-robins active sockets, embeds session ID/sequence/send monotonic timestamp,
and emits exact offered/sent/error/per-second counters. `recv` validates the
header and reports useful bytes, gaps, duplicates, out-of-order events,
per-session rates, Jain fairness, and zero-rate seconds. Use bounded vectors of
exactly `sessions`; no per-packet retained history. For `combined`, the manifest
assigns 150 Mbps to this generator and 150 Mbps to the 32-flow tunnel workload.

- [ ] **Step 6: Add parser cases for iperf3 JSON, QA JSON, and scheduler stats**

Tests must prove extraction of useful receiver bitrate/loss, complete one-second intervals, and scheduler p99/drop/fairness/UDP-error/high-water values. Reject iperf runs with omitted seconds, errors, non-normal termination, or duration below 60 seconds.

- [ ] **Step 7: Implement the isolated netns topology runner**

`netns.sh` must accept only these explicit actions:

```text
netns.sh setup --artifact-dir DIR --client-bin BIN --sidecar-bin BIN
netns.sh run --case sessions64|tunnel32|combined --mode legacy|feedback_drr --impairment none|delay1|delay5|delay10|loss01|loss05|stall500|reconnect|feedback-delay10 --artifact-dir DIR
netns.sh teardown --artifact-dir DIR
```

Before the performance matrix, `run-matrix` executes real-process compatibility
smokes using the retained legacy Python binaries and new native binaries:

```text
legacy Python client -> new native sidecar: no type 6/7 sent to legacy peer
new native client -> legacy Python sidecar: one-second scope fallback, data passes
new native client -> new native sidecar: all active legs negotiate feedback
new/new reconnect: epoch changes and stale progress is rejected
new/new partial Ack fixture: entire scope remains legacy until every active leg Acks
```

Each smoke covers both traffic directions and records process exit status,
negotiated/fallback leg counts, and a payload hash. The formal performance cases
run only after all five smokes pass.

The script must:

1. create `uot-client`, `uot-slave`, and `uot-sidecar` namespaces;
2. create five independent client-to-slave-to-sidecar veth paths;
3. expose only per-leg TCP ingress in `7000-9000` and preserve the same hop order as production;
4. generate ephemeral WireGuard keys at runtime for the tunnel workload;
5. invoke `leikwan-uot-qa` so `sessions64` uses 64 distinct bound UDP sockets at the requested aggregate rate;
6. use `iperf3 -P 32 -t 60 -J` inside one WireGuard tunnel for `tunnel32`;
7. cap aggregate offered load at 300 Mbps;
8. apply `tc netem` to exactly one leg for each impairment;
9. sample scheduler JSON, `ss -tinp`, `/proc/net/snmp`, process RSS, ping RTT, and per-second throughput;
10. trap `EXIT`, stop only namespaced processes, and delete only namespaces/veth devices bearing the `uot-qa-20260808` marker.

`tools/uot-feedback/manifests/local-netns.json` contains only namespace names,
RFC 1918 addresses, five leg definitions, workload rates, datagram size, and
artifact-relative paths. It contains no SSH host, token, key, or production
address.

Use this exact credential-free shape:

```json
{
  "marker": "uot-qa-20260808",
  "namespaces": {"client": "uot-client", "slave": "uot-slave", "sidecar": "uot-sidecar"},
  "legs": [
    {"id": 1, "client_addr": "10.250.1.1/30", "slave_addr": "10.250.1.2/30", "sidecar_addr": "10.251.1.2/30", "ingress_port": 8801},
    {"id": 2, "client_addr": "10.250.2.1/30", "slave_addr": "10.250.2.2/30", "sidecar_addr": "10.251.2.2/30", "ingress_port": 8802},
    {"id": 3, "client_addr": "10.250.3.1/30", "slave_addr": "10.250.3.2/30", "sidecar_addr": "10.251.3.2/30", "ingress_port": 8803},
    {"id": 4, "client_addr": "10.250.4.1/30", "slave_addr": "10.250.4.2/30", "sidecar_addr": "10.251.4.2/30", "ingress_port": 8804},
    {"id": 5, "client_addr": "10.250.5.1/30", "slave_addr": "10.250.5.2/30", "sidecar_addr": "10.251.5.2/30", "ingress_port": 8805}
  ],
  "workloads": {"sessions64_rate_bps": 300000000, "tunnel32_rate_bps": 300000000, "combined_each_rate_bps": 150000000, "duration_seconds": 60, "datagram_bytes": 1200}
}
```

Before destructive namespace cleanup, resolve and print the exact namespace/device list and refuse any name without that marker.

- [ ] **Step 8: Syntax-check and test the tooling**

Run:

```powershell
python tools/uot-feedback/test_acceptance.py -v
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --test qa_cli
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/uot-feedback-scheduler-20260808 && bash -n tools/uot-feedback/netns.sh'
python tools/uot-feedback/acceptance.py --help
git diff --check
git status --short
```

Expected: unit tests pass, shell syntax is valid, CLI documents the exact subcommands, and no run touches production.

---

### Task 15: Run The Complete Local Gate And Build Reproducible Static Artifacts

**Files:**
- No source file changes expected; outputs stay under ignored Cargo target directories and local `qa-artifacts/uot-feedback-20260808/`.

- [ ] **Step 1: Run formatting, compile, lint, and all Rust tests**

Run:

```powershell
cargo fmt --manifest-path wstunnel-main/Cargo.toml --all -- --check
cargo check -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --all-targets
cargo clippy -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml --all-targets -- -D warnings
cargo test -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
```

Expected: every command exits 0. Fix new warnings; do not suppress them globally. Existing unrelated workspace warnings may be recorded only if they cannot be attributed to changed code.

- [ ] **Step 2: Run all affected language suites**

Run:

```powershell
python -m unittest discover -s client-agent/tests -p test_mptcp_bond_regressions.py
python -m unittest discover -s client-agent/tests -p test_sidecar_dual_runtime_regression.py
python -m unittest discover -s client-agent/tests -p test_artifact_versions_consistency.py
python scripts/tests/test_windows_client_setup_installer.py
python tools/uot-feedback/test_acceptance.py -v
php master/tests/agent_udp_uot_sidepath_management_test.php
php master/tests/installer_artifact_hash_verification_test.php
php master/tests/artifact_capability_hash_consistency_test.php
php master/tests/artifact_versions_manifest_route_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
php master/tests/uot_native_artifact_regression_test.php
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj -c Release
```

Expected: all affected suites pass.

- [ ] **Step 3: Build static musl binaries twice under WSL**

Run:

```powershell
wsl bash -lc 'set -euo pipefail; cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/uot-feedback-scheduler-20260808/wstunnel-main; rm -rf /tmp/uot-build-a /tmp/uot-build-b; CARGO_TARGET_DIR=/tmp/uot-build-a RUSTFLAGS=-Awarnings cargo build --locked --release --target x86_64-unknown-linux-musl -p leikwan-uot; CARGO_TARGET_DIR=/tmp/uot-build-b RUSTFLAGS=-Awarnings cargo build --locked --release --target x86_64-unknown-linux-musl -p leikwan-uot; for bin in leikwan-uot-bond leikwan-udp-uot-merge leikwan-uot-qa; do cmp /tmp/uot-build-a/x86_64-unknown-linux-musl/release/$bin /tmp/uot-build-b/x86_64-unknown-linux-musl/release/$bin; done; cmp /tmp/uot-build-a/x86_64-unknown-linux-musl/release/leikwan-uot-bond ../artifacts/wstunnel/leikwan-uot-bond_linux_amd64; cmp /tmp/uot-build-a/x86_64-unknown-linux-musl/release/leikwan-udp-uot-merge ../artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64'
```

Expected: both builds succeed; all three binaries reproduce byte-for-byte; the
two publishable Linux artifacts equal the manifest files. Record `rustc -Vv`,
`cargo -V`, target triple, and `Cargo.lock` SHA-256 in the QA directory.

- [ ] **Step 4: Prove static compatibility and capture hashes**

Run under WSL:

```bash
for bin in leikwan-uot-bond leikwan-udp-uot-merge leikwan-uot-qa; do
  path="/tmp/uot-build-a/x86_64-unknown-linux-musl/release/$bin"
  file "$path"
  ldd "$path" || true
  readelf --version-info "$path"
  sha256sum "$path"
  stat -c '%n %s' "$path"
done
```

Expected: x86-64 static/static-PIE ELF, `ldd` says statically linked or not dynamic, no `GLIBC_` version dependency, and hashes/sizes are recorded.

Build the Windows binary twice into explicit fresh target directories and compare
both outputs plus the manifest artifact:

```powershell
$env:CARGO_TARGET_DIR = "$env:TEMP\uot-win-a"
cargo build --locked --release --target x86_64-pc-windows-msvc -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
$env:CARGO_TARGET_DIR = "$env:TEMP\uot-win-b"
cargo build --locked --release --target x86_64-pc-windows-msvc -p leikwan-uot --manifest-path wstunnel-main/Cargo.toml
Remove-Item Env:CARGO_TARGET_DIR
$a = Get-FileHash "$env:TEMP\uot-win-a\x86_64-pc-windows-msvc\release\leikwan-uot-bond.exe" -Algorithm SHA256
$b = Get-FileHash "$env:TEMP\uot-win-b\x86_64-pc-windows-msvc\release\leikwan-uot-bond.exe" -Algorithm SHA256
$published = Get-FileHash artifacts/wstunnel/leikwan-uot-bond_windows_amd64.exe -Algorithm SHA256
if ($a.Hash -ne $b.Hash -or $a.Hash -ne $published.Hash) { throw 'Windows UoT artifact is not reproducible' }
```

- [ ] **Step 5: Run the isolated netns matrix and evaluate paired results**

Run `setup`, then randomized legacy/feedback pairs for `sessions64`, `tunnel32`, and `combined`, followed by every impairment listed in Task 14. Every case is exactly 60 seconds. Evaluate each normal feedback run against its matching legacy run, hash all raw JSON/log/process/socket/config files, then run `teardown` in the script trap.

Expected normal feedback gates:

```text
useful throughput >= 270 Mbps
scheduler queue p99 <= 10 ms
scheduler drop ratio <= 0.1%
UDP socket send errors == 0
complete zero-rate seconds == 0
Jain fairness >= 0.90
memory hard-bound violations == 0
feedback-capable active legs == all active legs
legacy-fallback active legs == 0
receiver loss no worse than paired legacy and target <= 0.5%
end-to-end p99 regression versus legacy <= 10 ms
inner TCP retransmissions no worse than legacy
```

Impaired runs additionally require no cross-session stall and no more than 10% useful-throughput loss versus the same topology's unimpaired feedback run.

If the matched legacy/unimpaired topology itself cannot reach 270 Mbps, record
the physical-path capacity as a separate failed absolute gate and do not attribute
that shortfall to scheduler behavior. The feature still cannot be called a pass
until the relative legacy-loss, latency, retransmission, and scheduler safety
gates are independently satisfied.

- [ ] **Step 6: Record the local no-commit checkpoint**

Run:

```powershell
git diff --check
git status --short --branch
git diff --stat
git diff --cached --stat
```

Expected: `git diff --cached --stat` is empty; source/docs changes and QA artifacts remain uncommitted.

---

### Task 16: Perform An Isolated Stage Validation Without Touching Production

**Files:**
- Create: `docs/superpowers/runbooks/uot-feedback-stage.md`
- Create local evidence under: `qa-artifacts/uot-feedback-20260808/stage/`

- [ ] **Step 1: Write the stage runbook before connecting**

The runbook must state these non-negotiable checks:

```text
topology: client -> slave -> sidecar only
slave ingress: unused port selected inside 7000-9000
stage service/config names: distinct from every production unit/path
port 20013: listener and application probe before, during, after
backup: binaries, unit files, JSON, hashes before replacement
rollback: stop stage units, restore stage backup if applicable, recheck all listeners
production: no restart, reload, binary overwrite, config overwrite, or route flip
```

- [ ] **Step 2: Read local inventory securely and select an isolated endpoint**

From WSL, read `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` from the authoritative `leikwan-br1` checkout. Do not print their contents into logs, the plan, git diff, or user-facing output. Reach a sidecar only through its slave. On the selected slave, enumerate `ss -Hlnptu` and select the first unused candidate by descending scan of `8900..8800`; abort if no isolated port is free.

- [ ] **Step 3: Capture a read-only preflight and the `20013` guard**

Record timestamped outputs for host identity, installed units, process command lines, active listeners, route ownership, current binary/config hashes, WireGuard status, and:

```bash
ss -Hlnptu '( sport = :20013 )'
timeout 3 bash -c '</dev/tcp/127.0.0.1/20013'
```

Run the applicable application-level `20013` probe from its actual client path as well. Abort on any discrepancy before uploading files.

- [ ] **Step 4: Back up and install only isolated stage files**

Create a timestamped `/root/leikwan-uot-feedback-stage-backup/<UTC_TIMESTAMP>/` on each involved host. Copy the two candidate binaries to new `.stage-20260808` paths, verify SHA-256 against local evidence, write new stage-only JSON/unit files, and use unique unit names. Do not overwrite `/usr/local/bin/leikwan-uot-bond`, `/usr/local/bin/leikwan-udp-uot-merge`, production JSON, or production units.

- [ ] **Step 5: Run the exact 60-second stage matrix**

Run 64 UoT sessions, one WireGuard tunnel with 32 inner flows, combined pressure, both directions, and the approved impairment cases at 300 Mbps aggregate. Sample stats once per second. Randomize each feedback/legacy pair order. Retain raw `iperf3 -J`, scheduler JSON, `ss -tinp`, process/RSS, ping, WireGuard, config, and journal evidence with hashes.

At least every 10 seconds during the matrix, re-run the `20013` listener and application probe. A single failure stops the stage run immediately.

- [ ] **Step 6: Enforce rollback signals automatically**

Stop stage units and restore only stage paths on any UDP error, queue p99 above 10 ms, scheduler drop ratio above 0.1%, zero-rate second, capability/counter regression, memory violation, bulk traffic on a feedback-capable lagging leg, WireGuard failure, topology deviation, or `20013` failure. Recheck all preflight listeners after cleanup.

A formal feedback run also fails if
`feedback_capable_active_legs != active_legs` or
`legacy_fallback_active_legs != 0`. Protocol compatibility fallback is valid for
mixed versions, but it cannot be reported as an enabled feedback canary.

- [ ] **Step 7: Evaluate evidence and leave production unchanged**

Use `acceptance.py` for every pair. Write pass/fail per gate and SHA-256 manifest. Confirm production unit PIDs, binary hashes, config hashes, routes, and `20013` state equal the preflight snapshot. Do not promote the stage artifact even when all gates pass.

---

### Task 17: Rewrite The Damaged Handoffs From Actual Evidence

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\findings20260808.md`
- Modify: `C:\Users\frankniubi\Downloads\leigod-forward\maybesolution1.md`

- [ ] **Step 1: Rewrite findings with explicit evidence strength**

Use these sections:

```text
Scope and exact source revision
Confirmed code facts
Measured local results
Measured isolated-stage results
Hypotheses not proven
Acceptance gate table
Raw artifact paths and SHA-256
Production state (explicitly unchanged)
Remaining risks
```

Correct the prior broken paths, truncated sentences, per-session sequence wording, frame-rate arithmetic, and iperf out-of-order interpretation. Do not claim the scheduler visibility gap was the sole root cause unless new controlled evidence isolates it.

- [ ] **Step 2: Replace solution chatter with the implemented contract**

Document the actual protocol types 5/6/7, 44-byte progress payload, flattened config keys/defaults, feedback/legacy fallback behavior, queue/window separation, state machine, drop reasons, UDP error semantics, test matrix, and limitations. State that feedback reduces avoidable cross-leg skew but does not promise perfect order or replay unconfirmed data.

- [ ] **Step 3: Add a machine-checked acceptance table**

Populate values directly from `REPORT.json` for:

```text
270 Mbps throughput
10 ms scheduler p99
0.1% scheduler drop ratio
zero UDP send errors
zero zero-rate seconds
0.90 Jain fairness
paired legacy receiver loss
paired p99 RTT delta
paired inner-TCP retransmissions
memory bounds
20013 continuity
```

Mark each `PASS`, `FAIL`, or `NOT RUN`; never convert missing evidence into a pass.

- [ ] **Step 4: Validate the rewritten Markdown and no-secret boundary**

Run:

```powershell
rg -n "leigod-unordered-dain|leigod-unordered-dpowers|25,7,000" C:\Users\frankniubi\Downloads\leigod-forward\docs\handoffs\findings20260808.md C:\Users\frankniubi\Downloads\leigod-forward\maybesolution1.md
git -C C:\Users\frankniubi\Downloads\leigod-forward diff --check
git -C C:\Users\frankniubi\Downloads\leigod-forward status --short
```

Expected: the bad-fragment scan has no matches, Markdown contains no credentials, and changes remain uncommitted.

---

### Task 18: Final Verification And Worktree Handoff

**Files:**
- Verify all files changed in Tasks 1-17.

- [ ] **Step 1: Re-run the complete local test gate from a clean process state**

Run every command in Task 15 Steps 1-2 again. Do not reuse a still-running test server or stale stats file.

Expected: all affected Rust, Python, PHP, acceptance-tool, and .NET tests pass.

- [ ] **Step 2: Verify scope, whitespace, and absence of staged/committed work**

Run:

```powershell
git diff --check
git diff --cached --exit-code
git log -1 --format='%H %s'
git status --short --branch
git diff --name-only
```

Expected: cached diff is empty; HEAD remains `11550550b3c6c36d0878443c79dd5814aa260a48`; all intended files are visible as unstaged/untracked worktree changes.

- [ ] **Step 3: Verify hard safety constraints in code, configs, runbook, and evidence**

Search for stage ingress ports outside `7000-9000`, direct sidecar endpoints, production unit/path overwrites, and any action against `20013`. Confirm every stage reference is isolated and every `20013` reference is a non-mutating guard/probe.

- [ ] **Step 4: Report measured outcomes without committing**

The final implementation report must include changed-file summary, test command/result summary, static artifact hashes/sizes, local and stage gate table, production-unchanged proof, exact worktree path, branch, and the statement `No commit was created.`

Do not offer or perform a production deployment in this implementation run.
