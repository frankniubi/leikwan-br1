# Bond Leg Adaptive Weighting and Panel Reputation Design

Date: 2026-04-19
Status: Proposed
Scope: `forward 26` first, then generalize to other bond forwards after validation

## Goal

Build a self-healing bond runtime that can detect bad legs while traffic is live, reduce their influence automatically, probe them for recovery, and surface protocol-specific reputation to panel for operator reference.

This is not just a speed optimization.

It is a runtime survival feature. One toxic leg should not be able to collapse an otherwise healthy pool.

## Why this exists

Fresh live evidence shows:

- current live `leikwan-bond` still enters `Sticky -> Warmup -> Bulk`
- TCP chunks are still being scheduled across multiple legs
- UDP sidepath traffic is also using multiple legs
- but a single bad leg can still destroy aggregate throughput

The proven example is `8.163.90.235`.

Single-leg tests show that several legs can each deliver around `173-175 Mbps` alone, while `8.163.90.235` can collapse to about `2.8 Mbps` and break the control socket.

That means the system is not suffering from "no multi-leg scheduling".

It is suffering from "bad legs are still treated as first-class participants".

## Product requirements

### Runtime requirements

1. TCP and UDP leg health must be evaluated independently.
2. Runtime behavior must adapt automatically as conditions change.
3. A slow leg should not be dropped immediately.
4. A toxic leg must eventually stop receiving normal bulk traffic.
5. Recovery must be automatic and gradual.

### Control-plane requirements

1. Reputation must sync to panel.
2. Reputation in panel is reference-only.
3. Panel reputation must not influence routing for other clients or other forwards.
4. Admin can see global reputation.
5. Tenant / tenantor can only see legs related to their own clients / forwards.

### Ops requirements

1. Admin index must show queue health clearly.
2. Admin index must include a stale pending-task purge path.
3. Admin index must include recent task error details.

## Non-goals

- No panel-driven routing decisions in phase 1
- No cross-client runtime reputation sharing
- No cross-forward runtime reputation sharing
- No new external service or daemon
- No ML-style global scoring engine
- No automatic operator actions from panel beyond safe purge

## Core design

## 1. Runtime health model

Each bond leg keeps two independent runtime health tracks:

- `tcp`
- `udp`

Because a leg can be:

- TCP bad, UDP acceptable
- UDP bad, TCP acceptable

Sharing one score would contaminate both schedulers.

### Per-protocol leg states

For both TCP and UDP, a leg can be in one of four states:

- `Healthy`
- `Degraded`
- `Probation`
- `Quarantined`

State meaning:

- `Healthy`: full participation
- `Degraded`: low but non-zero real traffic weight
- `Probation`: sparse real traffic or tightly limited trials
- `Quarantined`: probe-only, excluded from normal bulk distribution

### State transitions

Downward transitions happen on bad evidence:

- retransmit pressure
- resets
- open failures
- low delivered goodput
- repeated no-ack / timeout behavior

Upward transitions happen on good evidence:

- sustained delivered bytes
- stable ACK progression
- successful probes
- low recent penalties

Recovery is intentionally slower than degradation.

Fast down, slow up.

## 2. Self-adjusting indicators

Thresholds should not be mostly hard-coded forever.

Instead, indicators should adapt automatically using:

- EWMAs
- rolling windows
- per-leg recent baseline
- pool median / percentile comparison

Examples:

- if one leg's delivered EWMA falls far below the pool median while penalty EWMA rises, it should degrade
- if a previously degraded leg sustains delivered-goodput above a recovery threshold relative to the pool, it should promote

### Fixed guardrails that should remain explicit

Only a few things should stay fixed:

- minimum probe rate
- maximum quarantine hold time before re-probe
- minimum promotion cooldown
- minimum degradation cooldown
- maximum tolerated reset/open-failure burst before immediate quarantine

This keeps the system adaptive without becoming mushy.

## 3. Scoring signals

### TCP signals

Positive:

- acked bytes
- ack velocity
- successful stream open / open-ack
- stable RTT
- sustained delivered goodput

Negative:

- retransmits
- open timeout
- `recv_reset`
- abnormal control close
- repeated "selected but unproductive" windows

### UDP signals

Positive:

- acked datagrams
- delivered bytes
- stable session continuity
- low drop / reorder impact

Negative:

- resend volume
- no-ack timeouts
- gap-drop contribution
- delivered/attempted mismatch
- persistent high loss relative to sibling legs

## 4. Weighting rule

Each protocol derives:

- a numeric score
- an effective runtime weight

Recommended shape:

- `Healthy`: `1.0`
- `Degraded`: `0.10 - 0.25`
- `Probation`: effectively probe-biased, one real scheduling slot every N opportunities
- `Quarantined`: no normal bulk traffic

The important rule is not the exact constants.

The important rule is:

- bad legs remain visible
- weak legs remain testable
- toxic legs stop dragging the whole pool

## 5. Scheduler behavior

Current scheduling already knows:

- inflight bytes
- RTT
- priority
- sticky / warmup / bulk stages

That stage machine should stay.

What changes is candidate ranking.

### Candidate ranking should become health-first

Roughly:

1. protocol-specific health state
2. effective weight / delivered score
3. inflight pressure
4. RTT
5. static priority

### Preferred-leg rule

Preferred leg is still useful for sticky behavior, but only when the leg remains healthy enough.

If a preferred leg degrades, health state must override affinity.

## 6. Recovery behavior

If a leg only gets punished, the pool ossifies.

So every non-healthy state needs a recovery path:

- `Degraded`: keep tiny real traffic share
- `Probation`: mostly probe traffic, occasional real traffic
- `Quarantined`: probe-only

Successful probe windows promote slowly:

- `Quarantined -> Probation`
- `Probation -> Degraded`
- `Degraded -> Healthy`

## 7. Runtime data flow

### Local source of truth

Runtime decisions stay local inside `leikwan-bond`.

Panel never participates in scheduling.

### Local runtime snapshot files

Recommended outputs:

- client:
  - `/run/leikwan/bond-leg-health-forward-<forward_id>.json`
- sidecar:
  - `/var/lib/leikwan-sidecar-<sidecar_id>/bond-leg-health.json`

Each snapshot includes:

- forward / observer identity
- per-leg identity
- TCP state / score / weight
- UDP state / score / weight
- last good timestamp
- last degraded reason
- recent transition ring buffer
- recent delivery / penalty aggregates

## 8. Agent heartbeat sync to panel

Use existing heartbeats.

### Client path

- `client-agent/client_agent.py`
- `master/src/Controllers/ClientAgentController.php`

Client agent reads the local snapshot and includes it in heartbeat payload.

### Sidecar path

- `sidecar-agent/sidecar_agent.py`
- `master/src/Controllers/SidecarAgentController.php`

Sidecar agent does the same for its local snapshot.

No routing instructions come back from panel.

This is telemetry upward, not control downward.

## 9. Panel reputation library

Panel stores a global reference view.

Global for visibility, not global for routing.

### Recommended tables

#### `bond_leg_reputation_current`

One row per:

- observer type (`client` / `sidecar`)
- observer id
- forward id
- endpoint id
- protocol (`tcp` / `udp`)

Fields:

- node/slave identity
- endpoint identity
- current state
- score
- effective weight
- delivered EWMA
- penalty EWMA
- last reason
- last good at
- updated at

#### `bond_leg_reputation_events`

Append-only recent history:

- state transition
- score delta
- reason
- observed at
- observer type/id
- forward id
- endpoint id
- protocol

This becomes the panel-side global reputation library.

### Visibility rules

#### Admin

Can see:

- all legs
- all forwards
- all observers
- instability trends
- chronically weak nodes

#### Tenant / tenantor

Can only see:

- legs tied to their own client / forward
- current protocol state
- recent history for those same legs

No fleet-global visibility.

## 10. Admin index task board

Add a new `Task Queue Health` area on `/admin`.

### Board A: pending-task purge

Show:

- pending count
- running count
- failed count
- oldest pending age
- oldest running age

Add purge action:

- only for `status = pending`
- only older than operator-selected threshold
- never purge running tasks
- require confirmation

### Board B: task error detail board

Show recent failed tasks with:

- task id
- node
- action
- forward / endpoint
- attempt count
- updated at
- short error summary
- expandable details

Details should include:

- full `error_message`
- compact `result_json`
- compact task context from `payload_json`

This gives operators a fast "what is broken, why, and can I safely clear it?" board.

## 11. Security and tenancy boundaries

The global reputation library must obey these boundaries:

- panel-only persistence
- no runtime pullback
- no cross-client scheduling influence
- no cross-forward scheduling influence
- tenant scoping on reads

That keeps the system useful without creating hidden coupling.

## 12. Natural decomposition for multiple AI agents

This design is intentionally shaped so multiple AI agents can implement it in parallel with minimal overlap.

### Workstream A: bond runtime health and scheduler

Primary files:

- `wstunnel-main/leikwan-bond/src/session.rs`
- possibly `wstunnel-main/leikwan-bond/src/main.rs`

Owns:

- leg states
- scoring
- adaptive weighting
- quarantine/probe logic
- local snapshot emission
- runtime tests

### Workstream B: client and sidecar snapshot sync

Primary files:

- `client-agent/client_agent.py`
- `sidecar-agent/sidecar_agent.py`
- `master/src/Controllers/ClientAgentController.php`
- `master/src/Controllers/SidecarAgentController.php`

Owns:

- reading local snapshot files
- heartbeat payload extensions
- controller persistence
- heartbeat regression tests

### Workstream C: panel reputation library and admin boards

Primary files:

- `master/migrations/...`
- `master/src/Controllers/AdminController.php`
- maybe `master/src/Support/ProvisioningService.php` if query helpers help clarity
- `master/tests/...`

Owns:

- reputation tables
- event persistence
- admin global board
- tenant-scoped board
- pending purge
- task error board

This split is good for parallel AI execution because the write sets are mostly disjoint.

## 13. Success metrics

### Runtime

For a pool with one toxic leg:

- aggregate throughput should converge near the healthy-subset result
- not the current poisoned full-pool result

### TCP target

Observed:

- bad all-5 case: about `16-22 Mbps`
- healthy 4-leg subset: about `417 Mbps`
- strong 2-leg subset: about `342 Mbps`

Phase 1 target:

- full pool with one bad leg should land within `15-20%` of the healthy subset, not within `15-20%` of the bad all-5 state

### UDP target

- no 70%+ loss collapse under a single toxic leg
- traffic mix should bias toward healthy legs while still permitting bounded recovery probes

### Ops

- admin can identify stale pending tasks without SQL
- admin can inspect recent task failures without log diving
- tenant can inspect only their own leg reputation

## 14. Phase boundary

### Phase 1

- adaptive runtime health
- local snapshots
- panel sync
- panel reputation tables
- admin task queue board
- tenant-scoped reputation visibility

### Phase 2

- richer analytics
- trend charts
- operator tuning UI
- fleet-wide analytics dashboards

## Final recommendation

Ship:

- per-protocol adaptive bad-leg detection
- automatic weight rebalance
- bounded quarantine with recovery probes
- panel-only global reputation library
- admin index task purge + task error board

That solves the real problem.

The system already knows how to stripe. It does not yet know how to distrust a leg that is technically alive but operationally toxic.
