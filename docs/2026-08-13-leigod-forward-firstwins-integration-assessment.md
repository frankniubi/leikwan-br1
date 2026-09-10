# leigod-forward First-Wins Latency Scheduler — Integration Assessment for leikwan-br1

## Landed (2026-08-13)

The port was executed on branch **`uot-first-wins-port-20260813`** (off `master`), as three commits matching the vendor dependency order:

| br1 commit | vendor | scope |
|---|---|---|
| `6325815` | `f60d7ad` | probe recovery (5 scheduler.rs stability fixes) |
| `ab11279` | `5a33595` | Progress `connection_epoch` guard |
| `6290c25` | `09eb16e` | first-wins latency scheduler, **config-gated default OFF** |

All three commits build and pass the full suite: **77 unit tests + build_info + 2 feedback_runtime + 4 scheduler_load**, `cargo fmt --check` clean (modulo the pre-existing SOCKS5 formatting in client_runtime.rs), `--build-info-json` advertises `["leg-progress-v1","selective-first-wins-v1"]`. The first-wins feature is OFF by default (`wireguard_latency_sensitive_max_bytes=0`, `copies=1`, mode `Legacy`); two scheduler unit tests pin the OFF path. **Not committed, not pushed, not deployed** — per user instruction.

**Still deferred (activation prerequisites):** the full ON-path runtime (FirstWinsCache commit/suppress in client/sidecar, DATA_FLAG_FIRST_WINS egress, `first_wins_*` telemetry), the Python/agent/panel config plumbing for the 5 new fields, the multi-path-group provisioning re-architecture, the coordinated client+sidecar fleet binary upgrade, and the canary/soak. See §9 step 4 and §10.

---

**Date:** 2026-08-13
**Assessed by:** synthesis agent (Claude Fable 5), 6-axis parallel investigation
**Source under evaluation:** `C:\Users\frankniubi\Downloads\leigod-forward\vendor\leikwan-uot` at HEAD `09eb16e` "feat(uot): add negotiated first-wins latency scheduler"
**Target integration tree:** `wstunnel-main/leikwan-uot/` (br1 embedded workspace member)
**Baseline already on br1 master:** `8f0ec31` (feedback-driven scheduler) + `7a2cb2e` (7 scheduler stability fixes + SOCKS5 leg support, ported from vendor `64dc465`)

---

## 1. TL;DR verdict

**Partial port now; defer the first-wins feature itself.**

Port the two self-contained scheduler stability fixes (commits `5a33595` and `f60d7ad`) into br1's embedded crate now — they are low-risk, improve the existing `feedback_drr` scheduler stability that br1 already ships, and require neither the path-group diversity nor the negotiated capability that the first-wins feature depends on.

**Defer the negotiated first-wins latency scheduler (commit `09eb16e`) until at least three preconditions are met, because for br1's current leigod topology it is largely dead code that adds cost and risk on the exact resource that is already the bottleneck:**

1. **br1's leigod forwards never create multi-path-group legs.** `ProvisioningService.php` assigns the *same* `combination['leigod_line_id']` to every bond lane (`:7182`), and mptcp underlay legs carry no `line_id` at all (`:9735-9805`). First-wins requires `leg.path_group != primary_group` (vendor `scheduler.rs:1003-1015`); with one line per forward the diversity predicate is always false and first-wins never activates. br1's real path-diversity race is a **route-level** race across separate combinations executed by the Python helper `leikwan-tcp-race.py`, not a UoT-scheduler leg-level race. First-wins operates inside one UoT session across its legs — the wrong layer for br1's diversity.
2. **br1's 2-vCPU slaves are already CPU-bound.** Per `[[leigod_uot_cpu_bound]]`, each leg consumes ~70-100% of a 2-vCPU slave on gost UoT user-space per-packet + WG crypto; pure-leigod load already collapsed and was rolled back to cf-hybrid (`[[leigod_mode_automation_gap]]`). First-wins *doubles* small latency-sensitive WG packets onto a second leg (`scheduler.rs:891-892`, `DATA_FLAG_FIRST_WINS` replica) — adding load on the scarce resource while solving a loss/jitter problem br1 does not have. The throughput limiter is CPU headroom, not packet loss/jitter.
3. **Both-end coordinated fleet upgrade is required** for the negotiated pair to form: client `leikwan-uot-bond` AND sidecar `leikwan-udp-uot-merge` must both advertise `selective-first-wins-v1`. br1 ships static musl binaries through a three-place artifact set (`[[panel_artifact_serving]]`); a mixed fleet is *safe* (negotiation fails closed to single-copy/legacy) but yields zero benefit and creates operational confusion.

**Conditions where first-wins is a no-op vs. where it helps:**

| Topology | First-wins behavior |
|---|---|
| Single leg per forward | No-op — no secondary leg to pair with |
| All legs in the same path group (br1's current leigod forwards) | No-op — `enable_first_wins_pair` returns false because no healthy secondary in a *different* `path_group` |
| `scheduler_mode != feedback_drr` (br1 default is `Legacy`) | No-op — `enable_first_wins_pair` short-circuits on `EffectiveSchedulerMode != FeedbackDrr` |
| One end upgraded, the other not | No-op (safe) — `first_wins_capable` stays false; degrades to single-copy/legacy |
| Restart warm-up (first ~10s, `progress_samples < 2` or SRTT=0) | No-op during the window most exposed to transient flap |
| Multi-line multi-path-group, both ends upgraded, feedback_drr, CPU headroom available (the handoff's 5-leg `[455,455,693,693,456]` topology done *per-UoT-session*) | **Helps** — vendor reported p99 tail latency 388ms -> 147ms |

**Bottom line:** port the two fixes (`5a33595`, `f60d7ad`) now for free stability; treat `09eb16e` first-wins as a future option gated on a provisioning re-architecture (multi-line-per-forward with per-leg `path_group`), CPU-cap relief, and a full-fleet client+sidecar binary upgrade. If the user wants parity with leigod-forward regardless of immediate benefit, port the full chain in the layered, config-gated form described below — it defaults off and fails closed.

---

## 2. What changed in leigod-forward since the last port

br1 last ported from vendor `64dc465` (via `7a2cb2e`). Three commits landed in vendor on top of that baseline:

| Commit | Subject | Logical change | br1 status |
|---|---|---|---|
| `5a33595` | `fix(scheduler): guard Progress command with connection_epoch to stop stale-epoch drops` | Adds a `connection_epoch` field to `SchedulerCommand::Progress`; dispatch silently discards stale-epoch frames, mirroring the epoch guard already on `WriterStarted`/`WriterAccepted`/`WriterFailed`/`LegDisconnected`. Commit message cites `invalid_progress_ratio` dropping 82% -> 1.5% after reconnects. Cross-file: every `Progress` sender in `client_runtime.rs` and `sidecar_runtime.rs` must supply the leg's current epoch. | **Absent.** br1's `SchedulerCommand::Progress { leg_id, progress }` (`scheduler_runtime.rs:52-55`) has no epoch; dispatch at `:242-244` forwards unconditionally. Other commands in br1 *do* have the guard (`:256`). |
| `f60d7ad` | `fix(scheduler): fix probe recovery for legs with stale/low SRTT` | Five bundled scheduler.rs changes: (a) `stale_periods = 0` on `Quarantined->Probe` transition; (b) 200ms SRTT floor extended to `LegState::Probe` (not only `progress_samples < 4`); (c) `last_progress_us = now_us` on `writer_started` while in `Probe`; (d) per-sample rate capped at `aggregate_rate_bps.max(BOOTSTRAP_RATE_BPS)` before EWMA to kill Gbps outliers; (e) `predicted_finish_us_with_floor()` + n_active rate floor replacing `predicted_finish_us()` directly, changing the per-leg selection tiebreak to `(pf, outstanding_wire_bytes, leg_id)`. | **All five absent.** br1 `scheduler.rs:1402-1404` omits `stale_periods` reset; `:1450-1454` SRTT guard has no `Probe` condition; `:1072` resets `last_progress_us` only when `progress_samples==0`; `:1222-1235` feeds raw rate into EWMA; no `predicted_finish_us_with_floor`. |
| `09eb16e` | `feat(uot): add negotiated first-wins latency scheduler (+ standalone crate release)` | The first-wins feature proper, plus standalone-crate release scaffolding. New: `src/first_wins.rs` (`FirstWinsCache` commit-on-success receiver cache); latency-sensitive `TrafficClass` variants (`WireGuardSmallTransport`/`...Replica`) + `is_latency_sensitive()`; `path_group: u64` on `ClientLegConfig`/`LegRegistration`/`LegScheduleState` + replica `avoid_path_group` admission; 5 new config knobs (`startup_leg_window_bytes`, `session_reorder_budget_ms`, `wireguard_latency_sensitive_max_bytes`, `wireguard_latency_sensitive_copies`, per-leg `path_group`) + validators; `FIRST_WINS_CAPABILITY="selective-first-wins-v1"` auth negotiation (additive on top of `leg-progress-v1`, fails back to legacy); `DATA_FLAG_FIRST_WINS` wire flag; `wireguard::is_latency_sensitive_transport()`; `client_runtime` `FirstWinsCache` commit/suppress + `first_wins_*` telemetry; `lib.rs` capability wiring; standalone-release artifacts (Cargo.lock, upstream-workspace lock, IMPORT_MANIFEST.json, PROVENANCE.md, tests, `src/bin/`). | **Entirely absent** — no `first_wins.rs`, zero `FirstWins`/`path_group`/`FIRST_WINS_CAPABILITY`/`DATA_FLAG_FIRST_WINS`/`is_latency_sensitive_transport` references anywhere in br1 `src/`. Standalone release artifacts are irrelevant to br1's embedded layout (see Section 4). |

`delivery.rs`, `queue.rs`, `udp_socket.rs` are **byte-identical** between vendor `09eb16e` and br1; no porting needed there.

---

## 3. Logical delta vs br1 today

**br1 already has (from `8f0ec31` + `7a2cb2e`):**
- The full runtime file split (`client_runtime.rs`, `scheduler_runtime.rs`, `sidecar_runtime.rs`, `progress.rs`, `frame.rs`, `wireguard.rs`, `merge.rs`, `delivery.rs`, `queue.rs`, `udp_socket.rs`, `telemetry.rs`)
- Feedback-driven scheduler with `SchedulerMode::{Legacy, FeedbackDrr}` (default `Legacy`), `LEG_PROGRESS_CAPABILITY="leg-progress-v1"`, `connection_epoch` guards on `WriterStarted`/`WriterAccepted`/`WriterFailed`/`LegDisconnected`
- The `feedback_drr` enabling path in the Python agent + the `require_uot_feedback_capability` probe (checks `protocol_version==2`, `feedback_drr` in `scheduler_modes`, `leg-progress-v1` in `capabilities`)
- `UOT_SCHEDULER_DEFAULTS` (20 keys) duplicated in `client-agent/install_forward_runtime.py:11-34` and `sidecar-agent/sidecar_agent.py:41-64`, and a matching builder in `master/src/Support/ProvisioningService.php:3638-3698`

**Genuinely new vs br1 (must be ported to integrate):**

| Area | New logic | br1 file to edit |
|---|---|---|
| Progress epoch guard (`5a33595`) | `connection_epoch` on `SchedulerCommand::Progress`; epoch-gated dispatch; epoch threaded through all Progress senders | `scheduler_runtime.rs`, `client_runtime.rs`, `sidecar_runtime.rs` |
| Probe recovery (`f60d7ad`) | 5 bundled scheduler.rs changes (above) | `scheduler.rs` (hand-merge against br1's `8f0ec31` lineage) |
| First-wins cache (`09eb16e`) | new `src/first_wins.rs` (`FirstWinsCache`); `pub mod first_wins;` + `FIRST_WINS_CAPABILITY` in `build_info()` | new file + `lib.rs` |
| TrafficClass + replica admission | `WireGuardSmallTransport`/`...Replica` variants, `is_latency_sensitive()`, `classify`, replica issuance with `avoid_path_group` | `scheduler.rs`, `wireguard.rs` |
| Path-group propagation | `path_group: u64` on `ClientLegConfig`, `LegRegistration`, `LegScheduleState`; replica must differ from primary group | `config.rs`, `scheduler.rs` |
| Config knobs + validators | 4 new `SchedulerConfig` fields + 5 new `validate()` clauses + `validate_latency_sensitive_transport` cross-struct fn | `config.rs` |
| Auth negotiation | `FIRST_WINS_CAPABILITY`, `AuthCapabilities::leg_progress_with_first_wins(...)`, `requests_first_wins()`, `AuthAck::supports_first_wins(epoch)` | `progress.rs` |
| Wire flag | `DATA_FLAG_FIRST_WINS: u16 = 0x0001`; `wireguard::is_latency_sensitive_transport(payload, max_bytes)` | `frame.rs`, `wireguard.rs` |
| Client commit-on-success | `FirstWinsCache` embedded in `client_runtime`, commit/suppress on egress, `first_wins_*` telemetry stats | `client_runtime.rs`, `telemetry.rs` |
| Tests | `tests/feedback_runtime.rs` (196 -> 767 lines, adds `first_wins_delivers_one_datagram_and_releases_both_leg_ledgers`), `tests/build_info.rs`, `tests/scheduler_load.rs`, 3 unit tests in `first_wins.rs` | `tests/` |

**Structural caveat (important):** at the `64dc465` baseline vendor's crate contained only `config.rs` + `scheduler.rs`; vendor materialized all other files during the standalone-crate extraction. br1 independently grew its own versions of all these files. Therefore **a wholesale file copy is infeasible** — it would overwrite br1-specific branches. The port must be a **logic merge**, especially for `scheduler.rs` (br1 88905B vs vendor 133959B; +45054B of new logic intermixed with br1-specific code).

---

## 4. Build / Cargo approach

**Keep br1's workspace-member layout.** br1 embeds `leikwan-uot` as a member of the `wstunnel-main` cargo workspace (`members: leikwan-bond, leikwan-ingress, leikwan-mptcp-relay, leikwan-uot, leikwan-client-win/src-tauri`, `resolver="2"`). vendor's `Cargo.toml` is **byte-identical** to br1's (same name `leikwan-uot` v0.1.0, edition 2021, identical dependency pins), so no dependency/resolver drift to reconcile. No build-system restructuring is needed.

### Files to SKIP (standalone release artifacts — would pollute/collide with the workspace)

| Vendor file | Why skip |
|---|---|
| `Cargo.lock` | Standalone-package lock; copying it risks shadowing the authoritative `wstunnel-main/Cargo.lock` for the member. |
| `Cargo.lock.upstream-workspace` | A byte-copy of br1's own `wstunnel-main/Cargo.lock` kept only as provenance. |
| `IMPORT_MANIFEST.json` / `PROVENANCE.md` | Provenance only. |
| vendor `.gitignore` | br1's workspace .gitignore already governs `/target/`. |
| `src/bin/leikwan-uot-bond.rs`, `src/bin/leikwan-udp-uot-merge.rs` | **Byte-identical** to br1's existing bins; br1 already auto-discovers them and already produces the fleet binaries. |
| `docs/handoffs/2026-08-13-*.md` | Vendor-internal release handoff narrative. |
| vendor `tests/*` | **Port the logical assertions into br1's existing test modules** — vendor tests assume the standalone crate layout. |

### Source modules to PORT

| File | Action |
|---|---|
| `src/first_wins.rs` | **ADD** (only new source module). |
| `src/lib.rs` | **ADAPT** (2-line edit): add `pub mod first_wins;` after `pub mod delivery;`; append `progress::FIRST_WINS_CAPABILITY` to the `build_info()` capabilities array. |
| `src/scheduler_runtime.rs` | Port `5a33595` Progress epoch guard. |
| `src/scheduler.rs` | Hand-merge `f60d7ad` (5 changes) + `09eb16e` TrafficClass/path_group/replica admission. High blast-radius; do not overwrite. |
| `src/client_runtime.rs` | Port `5a33595` Progress senders; port `09eb16e` `FirstWinsCache` commit/suppress + egress gate + `first_wins_*` telemetry. |
| `src/sidecar_runtime.rs` | Port `5a33595` Progress senders; port `09eb16e` ack path / replica admission. |
| `src/config.rs` | Add 4 `SchedulerConfig` fields + default fns + 5 `validate()` clauses + `validate_latency_sensitive_transport`; add `path_group` to `ClientLegConfig`. |
| `src/progress.rs` | Add `FIRST_WINS_CAPABILITY`, `AuthCapabilities::leg_progress_with_first_wins(...)`, `requests_first_wins()`, `AuthAck::supports_first_wins(epoch)`. |
| `src/frame.rs` | Add `DATA_FLAG_FIRST_WINS: u16 = 0x0001`. |
| `src/wireguard.rs` | Add `is_latency_sensitive_transport(payload, max_bytes)`. |
| `src/telemetry.rs` | Add `first_wins_*` stats fields emission. |
| `src/delivery.rs`, `src/queue.rs`, `src/udp_socket.rs` | **No action** — byte-identical. |
| `tests/build_info.rs` | Assert both `["leg-progress-v1","selective-first-wins-v1"]`. |
| `tests/feedback_runtime.rs` | Merge the new first-wins runtime test. |
| `tests/scheduler_load.rs` | ~2 line diff. |

**Build validation (workspace, not standalone):** after porting, run `cargo build -p leikwan-uot` and `cargo test -p leikwan-uot --all-targets` from `wstunnel-main/`. Do **NOT** use vendor's `--manifest-path .../Cargo.toml --locked` standalone pattern.

---

## 5. Config plumbing — new config fields to br1 Python/agent/panel

First-wins adds 5 fields. Because the Rust struct uses `#[serde(default)]`, omitting them silently disables the feature (latency-sensitive off). But the three independent emitter definitions (`client-agent`/`sidecar-agent` `UOT_SCHEDULER_DEFAULTS` + PHP `ProvisioningService` builder) must stay in lock-step, or a half-plumbing silently leaves first-wins off or trips a Rust `validate()` bail.

| Field | Type | Rust default | Where to plumb | Bounds / cross-rules |
|---|---|---|---|---|
| `startup_leg_window_bytes` | `usize` | 131072 (128KiB) | client `UOT_SCHEDULER_DEFAULTS` (`install_forward_runtime.py:11-34`); sidecar `UOT_SCHEDULER_DEFAULTS` (`sidecar_agent.py:41-64`); PHP `ProvisioningService.php:3638-3698` | `0 < startup <= min_leg_window_bytes` |
| `session_reorder_budget_ms` | `u64` | 1 | same three layers | `<= queue_deadline_ms` |
| `wireguard_latency_sensitive_max_bytes` | `usize` | 0 (feature OFF) | same three layers | `0` disables; nonzero requires `delivery_mode=unordered` AND `wireguard_aware=true`; `<= max_datagram_bytes` |
| `wireguard_latency_sensitive_copies` | `u8` | 1 | same three layers | `1..=2`; `copies==2` requires `max_bytes>0` AND `scheduler_mode=feedback_drr` |
| `path_group` (per-leg `ClientLegConfig`) | `u64` | 0 | `render_udp_uot_aux_unit` per-leg dict (`install_forward_runtime.py:508-519`) | `#[serde(default)]=0` auto-maps to `endpoint_id` in the Rust runtime; omitting is safe |

**Additional plumbing work:**
- Extend `normalize_uot_scheduler`/`validate_uot_scheduler` in **both** `client-agent` and `sidecar-agent` to mirror the new Rust `validate()` cross-rules. Otherwise the agent accepts configs the Rust binary rejects -> service crash-loops.
- Extend the capability probe `require_uot_feedback_capability` (`install_forward_runtime.py:101-113`) to optionally check `selective-first-wins-v1` in `capabilities` before setting `wireguard_latency_sensitive_copies=2`.
- **Do NOT** widen the `SchedulerMode` enum or the Python `mode not in {legacy, feedback_drr}` check: first-wins is a `feedback_drr` sub-mode (copies=2), not a new mode.
- Keep the two Python runtimes (`leikwan_uot_bond.py`/`leikwan_udp_uot_merge.py`) unchanged; their warn-and-downgrade of `scheduler_mode != legacy` is the correct safety net. First-wins is only meaningful for the native Rust binary.
- **DB/panel:** `forward_combinations` (migration `20260420`) has no scheduler-config JSON column. To make first-wins reachable from the UI requires a nullable `scheduler_config` JSON column with safe defaults, or leaving the fields crate-default (legacy behavior). Also **reconcile divergent panel defaults** (independent bug): `ProvisioningService.php` emits `aggregate_rate_bps=300M`/`queue_deadline_ms=10`/`global_queue_bytes=1MiB`/`max_total_outstanding_bytes=8MiB`/`finish_skew_budget_ms=2` — these diverge from the Rust crate defaults (`450M/30/4MiB/16MiB/10`) already today.

---

## 6. Migration & back-compat

**Verdict: existing panel-emitted and agent-emitted config JSON parses unchanged.** Every new field is serde-optional with a default that reproduces legacy behavior. No deployed config breaks. Mixed-version fleets are safe.

| Field | Old | New | Default | Required? | Back-compat risk |
|---|---|---|---|---|---|
| `startup_leg_window_bytes` | absent | `usize`, serde-optional | 131072 | no | none (additive) |
| `session_reorder_budget_ms` | absent | `u64`, serde-optional | 1 | no | panel must keep `<= queue_deadline_ms` |
| `wireguard_latency_sensitive_max_bytes` | absent | `usize`, serde-optional | 0 (feature OFF) | no | `0` disables; nonzero requires `unordered`+`wireguard_aware` |
| `wireguard_latency_sensitive_copies` | absent | `u8`, serde-optional | 1 | no | `copies==2` requires `feedback_drr`+`unordered`+`wireguard_aware`+nonzero `max_bytes` else `validate()` bails at startup |
| `path_group` (per-leg) | absent | `u64`, serde-optional | 0 | no | none — `0` auto-maps to `endpoint_id` |

**Negotiation fails closed to single-copy/legacy, explicitly:**
- A leg is `first_wins_capable` only when the sidecar's `AuthAck` advertises `selective-first-wins-v1` for the matching `connection_epoch`.
- `enable_first_wins_pair` returns false if `copies != 2`, if effective mode `!= FeedbackDrr`, if the primary leg is not capable, or if no healthy secondary leg in a **different** `path_group` with `first_wins_capable` exists.
- When the pair is not enabled, the replica admitted at admission-time is dropped via `skip_queued_replica`, and a single datagram with `flags=0` is sent.
- The sidecar emits `legacy_process_epoch_ack_frame` when feedback is not negotiated.
- Test `latency_sensitive_copy_falls_back_cleanly_without_negotiated_peer` proves `copies=2` with un-negotiated legs yields exactly one datagram, `flags==0`, then `Idle` — i.e. **old peer + new sidecar (or new peer + old sidecar) cleanly degrades to single-copy.**

**The only new failure surface** is a config that explicitly opts into `copies=2` without all prerequisites — which no existing emitter does today. A half-plumbing hits `validate()` bail and crashes the binary at startup. This is why the validator must be ported together with the knobs.

---

## 7. leigod-mode runtime impact

**Verdict: LOW value for br1's current leigod topology; the feature is largely inert and adds CPU cost on the bottleneck resource.**

| Impact dimension | Assessment |
|---|---|
| Activation feasibility | **No-op today.** br1 assigns one `leigod_line_id` per forward combination to all lanes (`ProvisioningService.php:7182`); mptcp underlay legs carry no `line_id` (`:9735-9805`). All legs share one path group -> `enable_first_wins_pair` predicate always false. br1's path diversity is a route-level race via `leikwan-tcp-race.py` across separate combinations. |
| Scheduler mode | br1 defaults to `Legacy`; first-wins requires `FeedbackDrr`. |
| CPU on 2-vCPU slaves | **Negative.** First-wins doubles small WG transport packets onto a second leg. 2-vCPU slaves are already CPU-bound ~70-100%/leg; pure-leigod load already collapsed and was rolled back. |
| Both-end upgrade | Required. `lguot` (sidecar) + `leikwan-uot-bond` (client) must both be rebuilt and deployed fleet-wide, through the three-place artifact set. |
| Warm-up gate | First-wins requires `progress_samples >= 2` and `progress_srtt_us > 0` per leg. After the ~10s restart window, legs lack enough samples — first-wins cannot engage during the flap-prone warm-up. |
| Mixed fleet | Safe (negotiation falls back), but masks the no-op state. |
| To make it useful | (1) Re-architect `ProvisioningService` so one `leigod_mode+bond` forward carries legs on **different** leigod line families. (2) Switch default to `FeedbackDrr` for leigod forwards. (3) Relieve the 2-vCPU CPU cap. All three are prerequisites before first-wins yields benefit. |

---

## 8. Test / build / rollout checklist

### Branch & source
- [ ] Branch off **`master`** (per `CLAUDE.md`; `frontend-skin-v2` is NOT the baseline). Create e.g. `uot-first-wins-port-20260813`.
- [ ] Land the three pieces in separate commits: (1) `f60d7ad` probe-recovery; (2) `5a33595` Progress epoch guard; (3) `09eb16e` opt-in first-wins (config-gated, defaults off). Run the br1 uot test suite + workspace build after **each** commit.

### Rust gates (br1 workspace, NOT standalone)
- [ ] `cd wstunnel-main && cargo fmt --all -- --check`
- [ ] `cd wstunnel-main && cargo test -p leikwan-uot --all-targets`
- [ ] `cd wstunnel-main && cargo clippy -p leikwan-uot --all-targets -- -D warnings` (on a newer workstation toolchain; the fleet Rust 1.94.1 ICEs on dead-code warnings, forcing `RUSTFLAGS=-Awarnings`)

### Build fleet binaries (static musl)
- [ ] `cd wstunnel-main && RUSTFLAGS=-Awarnings cargo build --release --target x86_64-unknown-linux-musl -p leikwan-uot`
- [ ] Verify: `file`/`ldd`/`readelf --version-info` (no GLIBC dep), `sha256sum`, `stat -c %s` on both bins.
- [ ] (If Windows clients in canary) rebuild `leikwan-uot-bond_windows_amd64.exe`.

### Artifact landing
- [ ] `cp .../leikwan-uot-bond -> artifacts/wstunnel/leikwan-uot-bond_linux_amd64`; same for merge binary.
- [ ] Edit `artifacts/versions.json`: new `sha256` + `size`; add `selective-first-wins-v1` to `capabilities` (keep `protocol_version: 2`, `scheduler_modes: ["legacy","feedback_drr"]`).

### Artifact-consistency regression gates
- [ ] PHP: `master/tests/*artifact_regression_test.php`, `tcp_accel_artifact_distribution_test.php`, `leigod_forward_helper_artifact_distribution_test.php`, `provisioning_leigod_uot_impl_test.php`.
- [ ] Python: `pytest client-agent/tests/test_artifact_versions_consistency.py slave-agent/tests/test_slave_artifact_consistency.py`.

### Canary (matched pair, both ends in same window)
- [ ] One client (Hammer/source running `leikwan-uot-bond`) + one sidecar (Frankfurt or Tokyo sidecar `185.220.238.143` running `leikwan-udp-uot-merge`), on a contained UDP-heavy low-traffic forward.
- [ ] Back up current binaries + config before deploy.
- [ ] Deploy BOTH ends in the same window so first-wins negotiation handshakes.

### Soak (30 min under real offered load, ignore first ~10s warm-up)
- [ ] Watch: `first_wins_delivered > 0`; `first_wins_duplicates_suppressed > 0` (with `first_wins_cache_high_water << 65536`); `redundant_drops` not climbing as logical loss; `logical_offered_packets` stable; 5/5 healthy legs; `invalid_progress_ratio < 1.5%`; queue bytes 0; sidecar outstanding bytes 0.

### Deadman / rollback
- [ ] If any of 5/5 legs drops, `invalid_progress_ratio` spikes, queue bytes accumulate, or p99 tail regresses: restore pre-first-wins binaries + config, restart both services, verify listener + 5/5 legs + `10.7.1.1` ping no-loss. Code fails closed — a partial rollback of one end is safe.

### Fleet rollout (after canary passes)
- [ ] Sync production nginx docroot `master/public/artifacts/wstunnel/*` from `artifacts/wstunnel/` to defeat the static-shadow churn.
- [ ] Bump `AGENT_VERSION` and republish so the fleet cleanly picks up the new binaries.

---

## 9. Recommended sequencing

1. **Now (low-risk, high-confidence):** Port `5a33595` (Progress epoch guard) + `f60d7ad` (5 probe-recovery changes) into br1's `scheduler_runtime.rs`/`scheduler.rs`/`client_runtime.rs`/`sidecar_runtime.rs` as a self-contained diff against br1's `8f0ec31` lineage. Independent of first-wins; improve existing `feedback_drr` stability. Run tests; rebuild musl binaries; ship via the three-artifact set. No config/panel/agent plumbing required.
2. **Now (gate plumbing, feature off):** Port the `09eb16e` first-wins crate logic in layered, config-gated form — (a) `config.rs` knobs + validators, (b) `src/first_wins.rs` + `lib.rs` wiring, (c) `frame.rs`/`wireguard.rs` flags/classify, (d) `TrafficClass` + `path_group` + replica admission in `scheduler.rs`, (e) `progress.rs` negotiation, (f) `client_runtime` commit/suppress + `sidecar_runtime` ack + `build_info` capabilities. Defaults keep the feature **off**; safe to land without activating.
3. **After (1)+(2) are on master and binaries rebuilt:** Port vendor `tests/*` **assertions** (not files) into br1's existing test modules; reconcile divergent `ProvisioningService.php` defaults as an independent fix.
4. **Only after the user confirms the preconditions in Section 10 are met:** plumb the 5 new config fields through `client-agent`/`sidecar-agent`/PHP; extend `require_uot_feedback_capability`; add DB column for panel reachability; run the canary in Section 8.
5. **Do not** copy standalone-crate release files into br1's embedded member.

---

## 10. Open questions for the user

1. **Do br1 forwards actually use multi-path-group legs today?** br1 assigns one `leigod_line_id` per forward combination to all lanes, so first-wins' `path_group != primary_group` predicate is never satisfied. Is there a current or planned deployment where a single forward carries legs on **different** leigod line families? Without that, the feature is dead code.
2. **Is the 2-vCPU slave CPU cost acceptable?** First-wins doubles small WG transport packets onto a second leg. br1's 2-vCPU slaves are already CPU-bound; pure-leigod load already collapsed. Is there a plan to relieve the CPU cap (larger slaves / batched `lguot` recvmmsg-sendmmsg rollout) before relying on first-wins?
3. **Which canary hosts?** Confirm the exact client + sidecar hosts (e.g. Tokyo sidecar `185.220.238.143:14950`) and forward (fwd65/11035 analog) for the canary.
4. **Port for parity/future, or only when it yields benefit?** If parity is wanted regardless of immediate benefit, the full config-gated port (steps 1-2) is safe to land now (feature defaults off, fails closed). If value-first, defer step 2; land only the two scheduler fixes (step 1).
5. **Reconcile divergent panel defaults now or separately?** `ProvisioningService.php` emits 300M/10/1MiB/8MiB/2 vs the Rust crate defaults 450M/30/4MiB/16MiB/10. This divergence exists independently today.
6. **Tighten installer capability gates?** `install-bond-sidecar.sh` and `install-client-agent.sh` assert `protocol_version==2` + `feedback_drr` + `leg-progress-v1` but not `selective-first-wins-v1`. Should they require the new capability once first-wins is declared mandatory?

---

*Referenced br1 paths:* `wstunnel-main/leikwan-uot/`, `wstunnel-main/Cargo.toml`, `artifacts/wstunnel/` + `artifacts/versions.json`, `client-agent/install_forward_runtime.py`, `client-agent/leikwan_uot_bond.py`, `sidecar-agent/sidecar_agent.py`, `sidecar-agent/leikwan_udp_uot_merge.py`, `master/src/Support/ProvisioningService.php`, `master/tests/*artifact_regression_test.php`.
*Referenced vendor commits:* `5a33595`, `f60d7ad`, `09eb16e` (on top of `64dc465`).
*Referenced br1 commits:* `8f0ec31` (feedback-driven scheduler), `7a2cb2e` (7 scheduler stability fixes + SOCKS5 leg support from leigod-forward).
