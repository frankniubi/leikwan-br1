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

### Experience requirements

1. Admin should understand the current operational problem within 5 seconds of opening `/admin`.
2. Tenant / tenantor should only see reputation data that is relevant to their own client / forward.
3. Tenant-facing wording must explain that reputation is reference-only and that runtime already adapts automatically.
4. New operational UI must reuse the existing panel design language instead of creating a new dashboard style.

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

## 9.5 Information architecture and surface ownership

The runtime design is clear. The human-facing surfaces also need to be explicit.

### Admin surface ownership

#### `/admin`

This remains the fast triage page.

Show, in order:

1. existing hero and action row
2. KPI strip
3. `Task Queue Health`
4. `Recent Failed Tasks`
5. `Worst Legs Right Now`
6. link to deeper diagnostics

ASCII structure:

```text
/admin
  Hero
    - Republish All Forwards
    - Diagnostics
  KPI Strip
    - existing counters
  Task Queue Health
    - pending / running / failed
    - oldest pending / oldest running
    - stale pending purge action
  Recent Failed Tasks
    - compact table
    - inline expandable details
  Worst Legs Right Now
    - top degraded / quarantined legs
    - link to full diagnostics
```

#### `/admin/diagnostics`

This becomes the deep operational page.

Add a `Bond Leg Reputation` section with:

1. filter row
2. current reputation table
3. recent reputation events table

ASCII structure:

```text
/admin/diagnostics
  Existing diagnostics sections
  Bond Leg Reputation
    - filters
    - current rows
    - recent events
```

### Tenant surface ownership

Do not create a new tenant page in phase 1.

Reuse the existing diagnostics affordance already present on `/tenant/clients`:

- the existing `View Diagnostics` action

That view should gain a new scoped `Leg Reputation` section.

ASCII structure:

```text
/tenant/clients
  Client card
    - Run Diagnostics
    - View Diagnostics
      -> diagnostics dialog / details surface
         - current tunnel health
         - scoped leg reputation
         - recent reputation changes
         - calm reference-only explanation
```

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

Locked decisions:

- purge action lives on `/admin`
- thresholds offered in UI:
  - 15 min
  - 30 min
  - 60 min
- purge flow shows a count preview before deletion
- purge is admin-only
- purge result must return:
  - deleted count
  - skipped count
  - oldest affected timestamp
- purge never silently touches `running`

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

Locked decisions:

- details render inline as expandable rows, not a modal
- newest failures first
- default visible limit is the 20 most recent failed tasks
- if a natural action hint exists, show it next to the row:
  - republish
  - inspect node heartbeat
  - purge stale pending
  

This gives operators a fast "what is broken, why, and can I safely clear it?" board.

## 10.5 Interaction state coverage

These states are part of phase 1, not polish.

```text
FEATURE                    | LOADING | EMPTY | ERROR | SUCCESS | PARTIAL
---------------------------|---------|-------|-------|---------|--------
Task Queue Health          | skeleton summary + muted placeholders | "No queued tasks right now." | query failed, show retry copy and preserve last known counts if possible | counts + oldest ages + purge controls visible | counts visible but oldest age unavailable
Stale Pending Purge        | button busy state, controls disabled | preview shows 0 stale tasks | purge rejected with exact reason | success flash with deleted/skipped counts | some tasks deleted, some skipped due to race
Recent Failed Tasks        | skeleton rows | "No failed tasks recently." | task detail query failed | table rows + inline expanders | summary available but result/payload detail missing
Admin Reputation Overview  | skeleton table | "No reputation data yet." | query failed | worst-leg summary visible | current rows exist but no recent events
Admin Reputation Diagnostics | filters + skeleton table | "No legs match these filters." | malformed/stale payload query failure | full current + recent events tables | TCP present but UDP missing, or stale observer snapshots
Tenant Reputation View     | diagnostics dialog skeleton | "No reputation data yet for this client." | scoped diagnostics fetch failed | current scoped states + recent changes | snapshot stale or only one protocol available
```

For every state, the spec must describe what the user sees, not just backend conditions.

## 10.6 Trust semantics and wording

Tenant-facing wording must stay calm.

Do not present reputation as an outage meter.

Required intent:

- "Reference only"
- "Runtime already adapts automatically"
- "No action required" unless the user truly needs to intervene

Avoid wording that implies:

- "your node is bad"
- "your route is broken"
- "you must act now"

Admin wording can be more direct.

## 10.7 Responsive and accessibility rules

These are required in phase 1.

### Responsive

- admin summary cards remain usable at small widths
- wide admin tables may horizontally scroll, but controls must remain discoverable
- failed-task inline expansion must remain usable on narrow screens
- tenant diagnostics must avoid unreadable multi-column overflow
- `/admin/diagnostics` filters wrap instead of overflowing

### Accessibility

- status must never be color-only
- inline task detail expansion must be keyboard reachable
- purge confirmation must preserve and restore focus correctly
- destructive actions must use explicit confirmation copy
- minimum touch target: 44px
- timestamps, status, and reasons must remain readable without hover

## 10.8 Design-system alignment

Follow the existing panel UI language already visible in:

- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/TenantController.php`

Hard rules:

- reuse current hero/card/table/button vocabulary
- do not create a second ornamental dashboard shell
- cards summarize, tables explain
- reputation details are table-first, not card-mosaic-first
- tenant diagnostics should feel like a natural extension of the existing diagnostics UI

## 10.9 User journey storyboard

### Admin journey

```text
STEP | USER DOES                | USER FEELS                    | SPEC SUPPORT
-----|--------------------------|-------------------------------|-------------
1    | Opens /admin             | "What is burning?"            | Task Queue Health high on page
2    | Sees stale/failed counts | "Is the queue stuck?"         | counts + oldest ages + purge preview
3    | Expands failed task row  | "Why did this fail?"          | inline error details + context
4    | Opens diagnostics        | "Bad queue or bad leg?"       | reputation tables + recent events
```

### Tenant journey

```text
STEP | USER DOES                 | USER FEELS                        | SPEC SUPPORT
-----|---------------------------|-----------------------------------|-------------
1    | Opens View Diagnostics    | "Is my line okay?"                | scoped diagnostics reuse
2    | Reads leg reputation      | "Am I affected?"                  | scoped current states + recent changes
3    | Reads helper copy         | "Do I need to do anything?"       | reference-only, runtime adapts automatically
```

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
- explicit interaction-state coverage for admin and tenant operations UI
- responsive and accessibility coverage for those same surfaces

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
