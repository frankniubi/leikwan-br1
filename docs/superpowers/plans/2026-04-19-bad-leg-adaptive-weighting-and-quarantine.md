# 2026-04-19 Adaptive Bad-Leg Detection and Weight Rebalancing for Bond/UoT

Status: reviewed via compact `/autoplan` pass
Branch: `codex/android-single-bond-linux-parity`
Scope: `forward 26` first, then generalize to all bond forwards

## What I'm working with

User direction:

- 坏腿识别
- 权重自动调整
- 坏腿不要立刻硬踢掉
- 让程序能感觉到腿变坏, 无感降级, 其他腿自动顶上
- 如果腿恢复, 再平滑恢复权重

UI scope: no
DX scope: light, only operator observability and debugging commands

Verdict:

- **Approve the direction**
- But tighten the mechanism:
  - not "never remove"
  - instead: **healthy -> degraded -> probation -> quarantined**
  - degraded legs stay in rotation with tiny weight
  - quarantined legs only get probe traffic until they prove recovery

That is the right system. Anything softer will keep poisoning aggregate throughput. Anything harsher will flap.

---

## Phase 1: CEO Review

### 0A. Premise challenge

Premise 1:

- "The problem is lack of multi-leg scheduling."

Assessment:

- **Rejected.**
- Fresh evidence shows current live `leikwan-bond` still enters `Warmup -> Bulk`, and sidecar debug logs show data chunks scheduled across multiple legs.
- The real problem is **bad legs are still treated as first-class once they are technically alive**.

Premise 2:

- "If a leg is slow but not dead, it should not be fully removed."

Assessment:

- **Accepted, with a caveat.**
- Good instinct.
- A slow leg should not be instantly dropped.
- But a leg that consistently collapses control flow or contributes near-zero goodput must eventually be quarantined, or it drags the whole pool down.

Premise 3:

- "This should be automatic and invisible to operators."

Assessment:

- **Partially accepted.**
- Automatic yes.
- Invisible no.
- Runtime behavior should be automatic, but operators still need:
  - per-leg scores
  - state transitions
  - why a leg was degraded/quarantined

### 0B. Existing code leverage map

We already have most of the primitives we need.

#### Existing leverage, do not rebuild

- `wstunnel-main/leikwan-bond/src/session.rs`
  - `LegState`
  - `select_leg_with_mode_filtered()`
  - `select_stream_leg()`
  - `select_stream_leg_warmup()`
  - `select_udp_leg()`
  - `apply_leg_cooldown()`
  - `mark_leg_inactive()`
  - `apply_ack()`
  - `acknowledge_udp_datagram()`
  - `retransmit_pending()`
  - `retransmit_udp_datagrams()`

#### Already-available signals

- TCP per-chunk ack completion by `last_leg_id`
- UDP per-datagram ack completion by `last_leg_id`
- retransmit counts
- open failures
- reset reasons
- smoothed RTT
- inflight bytes
- cooldown state

This is the whole game. We do **not** need panel changes, new services, or a separate health daemon.

### 0C. Dream state mapping

```text
CURRENT STATE                 THIS PLAN                             12-MONTH IDEAL
static priorities       ->    runtime adaptive scoring       ->    self-healing multipath
bad leg poisons pool    ->    degraded/probation/quarantine  ->    fleet-wide leg reputation
manual operator triage  ->    automatic local rebalance      ->    policy-driven routing tiers
```

### 0C-bis. Implementation alternatives

#### Option A: Hard-remove bad legs immediately

Pros:

- simplest
- fastest short-term win

Cons:

- too blunt
- flaps on transient noise
- violates user goal

Decision:

- **Reject**

#### Option B: Pure weighting, never quarantine

Pros:

- smooth
- elegant on paper

Cons:

- toxic legs still receive meaningful traffic
- control-path failures keep hurting streams
- hard to stop catastrophic pool poisoning

Decision:

- **Reject**

#### Option C: Weighted adaptation with bounded quarantine

Mechanism:

- healthy legs get normal weight
- degraded legs keep a low but non-zero weight
- probation legs get sparse test traffic
- quarantined legs only get probe traffic until recovery

Decision:

- **Recommended**

This matches the failure mode we just proved with `8.163.90.235`.

### 0D. Scope choice

Mode:

- **SELECTIVE EXPANSION**

Why:

- We should solve the whole runtime adaptation problem.
- We should **not** expand into panel, database, or global fleet reputation yet.

### 0E. Temporal interrogation

Hour 1:

- stop one toxic leg from collapsing the pool

Hour 6:

- stabilize TCP and UDP aggregate throughput under mixed leg quality

Next week:

- expose per-leg score/state in runtime stats and journald

Later:

- optional cross-host leg reputation, panel surfacing, historical tuning

### 0F. CEO conclusion

This is the right problem to solve.

Not because it is fancy. Because we already proved the current pool can contain one leg that is "alive enough to be selected" but "bad enough to destroy aggregate throughput". That is exactly what adaptive weighting is for.

---

## Phase 2: Design Review

Skipped. No product/UI scope.

---

## Phase 3: Eng Review

## Architecture

### Root cause to design against

Fresh evidence:

- all 5 TCP legs can be scheduled in `Bulk`
- all 5 UDP legs can carry data
- one toxic leg can still collapse aggregate throughput
- removing `8.163.90.235` improves full-pool TCP from about `16-22 Mbps` to about `342-417 Mbps`

So the system requirement is:

> selection must optimize **effective delivered throughput**, not just "alive + low RTT + low inflight"

### Proposed architecture

Add adaptive health state directly into `LegState`.

#### Extend `LegState`

Add fields like:

- `health_state: LegHealthState`
- `effective_weight: f32`
- `delivered_bytes_ewma: f64`
- `delivered_events_ewma: f64`
- `retransmit_penalty_ewma: f64`
- `open_failure_penalty_ewma: f64`
- `reset_penalty_ewma: f64`
- `last_good_at: Instant`
- `last_probe_at: Option<Instant>`

New enum:

- `Healthy`
- `Degraded`
- `Probation`
- `Quarantined`

#### Update points

##### TCP success signal

In `apply_ack()`:

- when a chunk is acked, credit `chunk.last_leg_id`
- increase delivered EWMA by `chunk.payload.len()`
- reduce penalty
- refresh `last_good_at`

##### TCP failure signal

In:

- `retransmit_pending()`
- `recv_reset`
- stream-open timeout / resend path
- open failure path

Apply penalties to the responsible leg.

##### UDP success signal

In `acknowledge_udp_datagram()`:

- credit `datagram.last_leg_id`
- update UDP delivered EWMA

##### UDP failure signal

In:

- `retransmit_udp_datagrams()`
- no-ack timeout path
- fragment resend path

Apply penalties to the responsible leg.

### Selection rule change

Current selector mostly sorts by:

- inflight
- priority
- RTT

That is not enough.

Replace static ordering with a score that includes health:

```text
selection_score =
  delivered_weight_bonus
  - retransmit_penalty
  - reset_penalty
  - open_failure_penalty
  - inflight_penalty
  - rtt_penalty
```

Then apply state caps:

- `Healthy`: full weight
- `Degraded`: low but non-zero weight, e.g. 0.10 to 0.25
- `Probation`: one chunk every N scheduling opportunities
- `Quarantined`: control/probe traffic only, no normal bulk scheduling

### Recovery rule

This is critical.

If we only punish, the pool ossifies.

So:

- degraded leg can still receive sparse real traffic
- quarantined leg receives periodic probe traffic
- successful probe windows promote:
  - `Quarantined -> Probation`
  - `Probation -> Degraded`
  - `Degraded -> Healthy`

Recovery must be slower than degradation. Easy to fall, harder to re-enter.

## Failure modes registry

| Failure mode | Why it happens | Required mitigation |
|---|---|---|
| Toxic leg stays selectable forever | liveness is not performance | adaptive health score |
| Short transient blip causes full removal | punishment too aggressive | degraded/probation ladder |
| Recovered leg never comes back | no probe path | sparse recovery probes |
| UDP still melts while TCP improves | health model only wired for TCP | symmetric TCP + UDP signals |
| One stream gets pinned to a bad preferred leg | sticky logic too strong | health score must dominate preferred_leg when degraded |

## Code-quality review

### What to reuse

Do not add a new service.

Do not add panel schema.

Do not add a cross-process shared database.

Keep it in `session.rs`, near the existing scheduler and ack/retransmit machinery.

### What to avoid

- giant generic scoring framework
- config explosion
- distributed consensus about leg quality

This wants to be 1 explicit struct + 4-6 update hooks + selector changes. That's it.

## Test review

This needs real simulation coverage. Happy-path unit tests are not enough.

### Mandatory tests

#### TCP scheduler behavior

- one bad leg, four good legs
- verify aggregate throughput score converges away from bad leg
- verify bad leg still receives sparse probe traffic while degraded
- verify bad leg gets quarantined after repeated resets / poor delivery
- verify recovered leg can re-enter after sustained good acked bytes

#### UDP scheduler behavior

- one high-loss leg, four good legs
- verify per-datagram ack credit downweights bad leg
- verify aggregate send mix shifts to good legs
- verify recovered leg re-enters gradually

#### State transition tests

- `Healthy -> Degraded`
- `Degraded -> Probation`
- `Probation -> Quarantined`
- `Quarantined -> Probation -> Degraded -> Healthy`

#### Regression tests

- pool with one toxic leg must not collapse near single-bad-leg throughput
- removing the toxic leg manually and letting adaptive weighting run automatically should converge to similar goodput

## Performance review

The main performance target is not micro-latency. It is **pool robustness under heterogeneous legs**.

Success metrics for `forward 26`:

- full 5-leg pool with one toxic leg should land near the `good4` result, not the current `all5` disaster
- TCP goodput target:
  - current bad state: about `16-22 Mbps`
  - proven good4 state: about `417 Mbps`
  - target after fix: within 15-20% of good4
- UDP goodput target:
  - must keep 5-leg scheduling from collapsing into 70%+ receiver loss

---

## Implementation plan

### Task 1: Add explicit leg health model

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`

Steps:

1. Add `LegHealthState`
2. Extend `LegState` with delivery / penalty / probe fields
3. Add helper functions:
   - `credit_leg_delivery(...)`
   - `penalize_leg_retransmit(...)`
   - `penalize_leg_open_failure(...)`
   - `penalize_leg_reset(...)`
   - `recompute_leg_health_state(...)`

### Task 2: Wire TCP signals into health scoring

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`

Steps:

1. Credit acked chunks in `apply_ack()`
2. Penalize retransmits in `retransmit_pending()`
3. Penalize reset/open failure paths
4. Clear or decay penalties on sustained success

### Task 3: Wire UDP signals into health scoring

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`

Steps:

1. Credit datagram ack in `acknowledge_udp_datagram()`
2. Penalize resend paths in `retransmit_udp_datagrams()`
3. Make UDP selector respect degraded/probation/quarantine

### Task 4: Replace static selector ordering with health-aware ordering

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`

Steps:

1. keep the existing stage machine
2. keep sticky/warmup/bulk shape
3. change candidate ranking to prefer effective weight / delivery score over merely being alive
4. allow preferred leg only if health state is still acceptable

### Task 5: Add minimal observability

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`

Outputs:

- log state transitions:
  - `bond leg X health Healthy -> Degraded reason=...`
- optionally sampled stats lines with:
  - delivered bytes EWMA
  - penalty EWMA
  - effective weight

### Task 6: Add simulation tests

Files:

- `wstunnel-main/leikwan-bond/src/session.rs`
- existing test blocks in that file, or a dedicated adjacent test module if cleaner

---

## Not in scope

- panel UI for leg scores
- database persistence of leg reputation
- cross-forward global reputation
- fleet-wide coordination

Those are good phase 2s, not phase 1.

---

## Final recommendation

Do it.

But do it as **adaptive weighting plus bounded quarantine**, not just "soft weights forever".

The exact production shape should be:

- healthy legs dominate
- degraded legs never dominate
- toxic legs get quarantined automatically
- recovered legs come back slowly

That solves the real bug we just proved, which is not missing multi-leg scheduling. It's letting one rotten leg vote equally in the pool.

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|---|---|---:|---:|---|---|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 1 | Complete | Direction approved, but must be bounded quarantine not infinite soft weighting |
| Codex Review | `codex review` | Independent 2nd opinion | 0 | Not run | Compact autoplan pass only |
| Eng Review | `/plan-eng-review` | Architecture & tests | 1 | Complete | Reuse `session.rs` signals, no new service/panel schema, add health-aware selector |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | Skipped | No UI scope |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | Skipped | Minimal DX scope only |

**VERDICT:** APPROVED WITH REVISIONS
