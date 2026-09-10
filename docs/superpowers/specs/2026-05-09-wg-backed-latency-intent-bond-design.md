# WG-Backed Latency-Intent Bond Design

## Date
- 2026-05-09

## Branch
- `master`

## Goal

Build a forwarding architecture that gives users:

- low and stable latency for game play and interactive traffic
- high throughput for downloads and large transfers
- stable behavior across normal bond mode and CF-bond mode
- minimal interruption when slaves are added, removed, or repaired

This design keeps the existing topology shape:

```text
client -> slave -> sidecar -> target
```

but changes the transport layering and path policy.

---

## Problem

The current design can achieve very good throughput, but latency remains unstable. The observed behavior is not just "high latency", but bimodal or jittery latency, where short tests can alternate between low and high RTT classes.

The root issue is that the current system is trying to make bond policy solve problems that actually belong to the slave-to-sidecar backhaul and path-stability layers.

Three facts drive this design:

1. Sidecar should remain the final HK aggregation point because it benefits from CNIX / IEPL path quality.
2. The unstable part is often the `slave -> sidecar` back half, not only the `client -> slave` half.
3. Users care about both latency and speed, so the system must optimize toward a declared intent instead of only chasing the fastest one-time probe or the highest throughput.

---

## Hard invariants

- Keep topology `client -> slave -> sidecar`
- Do not bypass slaves
- Do not move final aggregation away from sidecar
- Keep bond and MPTCP ingress on slave public hosts within `7000-9000`
- Preserve the current leg-health engine family:
  - healthy
  - probation / degraded
  - quarantined
  - cooldown / suppression
- Make path changes with minimal blast radius
- Prefer sidepath changes over disruptive interface restarts

---

## Non-goals

- No direct client -> sidecar connectivity
- No all-in-one sidecar WireGuard interface for all slaves
- No phase-one bulk striping for unknown UDP / game-like traffic
- No per-forward dedicated WireGuard tunnels
- No panel-driven real-time micromanagement of every immediate path switch

---

## Design summary

This design combines four layers:

1. **Latency-first bond policy**
2. **Persistent per-slave WireGuard uplinks from slave to sidecar**
3. **Forward-chain latency intent policy**
4. **Rolling-window path evaluation and ordered remediation**

Each layer solves a different part of the problem.

---

## Layer 1: Latency-first bond policy

Bond remains the transport used from client to the slave pool.

### Behavior

- one **primary** slave leg is used for interactive TCP traffic
- helper legs join only for clearly bulk TCP traffic
- UDP stays primary-only in phase one
- helper legs are failover-only for UDP in phase one

### Purpose

This stops tiny flows and interactive traffic from being striped across unstable paths.

### Important limitation

Latency-first bond alone is not enough. It reduces helper contamination, but it does not fix unstable `slave -> sidecar` backhaul by itself.

---

## Layer 2: Persistent slave-to-sidecar WireGuard uplinks

The `slave -> sidecar` segment should not depend on repeatedly opening unstable dynamic backhaul state.

### Chosen topology

Use:

- **one persistent WireGuard uplink per slave**
- **one dedicated WireGuard interface per slave on sidecar**

This means:

- each slave has one stable sidecar uplink
- sidecar has one WG interface per slave
- slaves can be added or removed independently
- existing slave uplinks are not interrupted when a new slave is introduced

### Why per-slave sidecar interfaces

This minimizes blast radius:

- add one slave, touch one interface
- remove one slave, touch one interface
- restart one bad slave uplink, do not disturb all others

### Transport inside WG

Phase one uses:

- **simple raw TCP relay over WireGuard**

This is the least clever and most stable starting point.

### Forwarding model

After client traffic enters a slave:

```text
client
  -> slave ingress
  -> slave local relay
  -> slave-specific persistent WG uplink
  -> sidecar per-slave WG interface
  -> sidecar local forward relay
  -> target
```

Bond only optimizes `client -> slave`. WireGuard stabilizes `slave -> sidecar`.

### Addressing model

Use a small dedicated subnet per slave uplink, for example:

- slave 316 <-> sidecar:
  - `10.240.16.1/30`
  - `10.240.16.2/30`
- slave 317 <-> sidecar:
  - `10.240.17.1/30`
  - `10.240.17.2/30`

Use stable addressing tied to slave identity, not random per-session allocation.

### Keepalive

Enable persistent keepalive on every slave uplink peer.

This keeps:

- peer state warm
- conntrack state warm
- back-half RTT more stable

---

## Layer 3: Forward-chain latency intent policy

Policy is declared at the **forward-chain** level only.

This keeps all participants aligned:

- client
- slave
- sidecar
- CF selector

### Fields

- `desired_latency_ms`
- `max_exceed_time_seconds`
- `max_exceed_ratio_percent`
- `max_loss_ratio_percent`
- `evaluation_window_seconds`

### Meaning

#### `desired_latency_ms`
The target latency this chain should try to satisfy.

#### `max_exceed_time_seconds`
How long latency may remain above target continuously before action is required.

#### `max_exceed_ratio_percent`
How much of the recent rolling window may exceed target before the path is unacceptable.

#### `max_loss_ratio_percent`
How much loss is tolerated before the path is unacceptable.

#### `evaluation_window_seconds`
The rolling observation window for all judgments.

This is a rolling window, not a clock-reset bucket. Any moment can be the effective start of the current evaluation interval.

### Example policies

#### Gaming-oriented chain
- desired latency: `60`
- exceed time: `8`
- exceed ratio: `15`
- loss ratio: `1`
- evaluation window: `3600`

#### Balanced chain
- desired latency: `90`
- exceed time: `15`
- exceed ratio: `25`
- loss ratio: `2`
- evaluation window: `3600`

#### Throughput-oriented chain
- desired latency: `140`
- exceed time: `30`
- exceed ratio: `40`
- loss ratio: `5`
- evaluation window: `3600`

### Why forward-chain scope is correct

It keeps the whole chain coherent. Path selectors are not fighting per-forward conflicting priorities inside the same chain.

---

## Layer 4: Measurement and control loop

The system needs a clear answer for:

- who measures what
- who decides what

to avoid path-flap chaos.

### Client owns front-half truth

Client measures:

- one-leg interactive RTT
- active flow count
- helper usefulness
- local flow behavior
- primary-leg stability

Client decides:

- primary selection
- helper participation
- session pinning
- client-side failover

### Slave owns backhaul truth

Slave measures:

- WireGuard handshake freshness
- WireGuard transfer counters
- relay success into sidecar
- backhaul loss / timeout patterns
- CF path quality if in CF-bond mode

Slave decides:

- sidepath changes
- CF path changes
- temporary local suppression of bad backhaul choices

### Sidecar owns ingress truth

Sidecar measures:

- per-slave WG interface health
- arrival quality by slave uplink
- target-side relay success / failure

Sidecar decides:

- whether one slave-specific WG uplink looks broken
- whether a restart of that one WG interface is needed as last resort

### Panel owns intent, not instant switching

Panel publishes:

- thresholds
- allowed slave pools
- chain intent
- CF mode policy

Panel does not micromanage every immediate switch.

---

## Application classification

Outer protocol type is not enough to infer application class.

This is especially true for:

- WireGuard UDP
- Shadowsocks UDP relay

The outer UDP tunnel may carry games, downloads, intranet transfers, or anything else.

### Detection order

1. explicit hints:
   - DSCP
   - fwmark
   - forward-chain policy
   - future profile tags
2. local policy mapping
3. flow heuristics

### Phase-one safe default

- unknown UDP stays primary-only
- helper legs join only for clearly bulk TCP
- no guessing that "UDP means game"

---

## Primary selection rules

### Normal bond

Primary should be selected using the best available **real one-leg interactive RTT** signal, not just lightweight readiness probe score.

### CF-bond

CF path selection must also obey the same chain intent.

For JP and other CF-bond chains:

- choose CF IP / path combinations that satisfy the forward-chain latency policy consistently
- prefer a stable acceptable path over a noisy path that wins one sample
- switch consistently for the whole chain when policy is truly violated

### Stable primary lease

Once primary is chosen:

- do not switch on tiny probe differences
- require meaningful improvement
- require repeated confirmation
- freeze primary while active sessions exist, unless hard failure occurs

### Helper stability

Keep helper membership stable unless:

- leg health changes materially
- chain policy violation requires a different path

Do not churn helper ordering for tiny score noise.

---

## Rolling-window evaluation

Within `evaluation_window_seconds`, track:

- latency samples
- median / EMA latency
- p95 latency
- exceed ratio
- continuous exceed duration
- loss ratio
- current primary
- current helper set
- last switch time
- last switch reason
- last WG restart time

### Path state

A path is **acceptable** if:

- latency is at or below target most of the time
- exceed ratio is below threshold
- exceed duration is below threshold
- loss is below threshold

A path is **unacceptable** if one or more of those thresholds is violated persistently.

---

## Ordered remediation

The action ladder is critical.

### First choice: modify the sidepath

Preferred action:

- switch primary slave leg
- adjust helper membership
- switch CF IP / path consistently for the chain
- suppress or quarantine a bad path element

This is preferred because it is lower interruption.

### Second choice: quarantine bad path elements

Examples:

- remove a bad slave from the eligible pool
- suppress a bad CF edge/path
- suppress a degraded helper

### Last choice: restart the affected WG interface

If path changes and quarantining still cannot restore policy compliance:

- restart only the **slave-specific** WG interface
- never restart all sidecar peers unless absolutely required

This matches the per-slave interface design and minimizes disruption.

### Action ladder

1. compliant -> do nothing
2. mild brief violation -> observe only
3. real persistent violation -> modify sidepath first
4. still bad -> quarantine bad path element
5. still bad -> restart only the affected slave-specific WG interface

---

## Admin UX

At the forward-chain level, add a **Network intent** section.

### Fields

- Desired latency (ms)
- Latency may exceed for up to (s)
- Allowed exceed ratio (%)
- Allowed loss ratio (%)
- Evaluation window (s)

### Help text

- **Desired latency**: target latency this chain should try to satisfy
- **Exceed time**: continuous exceed duration tolerated before switching
- **Exceed ratio**: percent of recent window allowed above target
- **Loss ratio**: packet loss tolerance before path is unacceptable
- **Evaluation window**: rolling time window used for all latency/loss judgments

### Observability

Show, per chain:

- current primary
- current helpers
- current effective latency
- exceed ratio
- exceed duration
- loss ratio
- current CF selection if applicable
- current backhaul health state if WG-backed
- whether the chain is policy-compliant or degraded

---

## Rollout strategy

### Phase 1

Implement:

- per-slave WG sidecar uplinks
- raw TCP relay inside WG
- latency-first bond
- forward-chain latency intent policy
- rolling-window evaluation
- sidepath-first remediation
- slave-specific WG restart only as last resort

### Phase 2

Add:

- richer DSCP / fwmark support
- deeper CF selector integration
- more detailed observability

### Phase 3

Optional:

- bulk UDP path expansion for explicitly non-game workloads
- more advanced shared mux inside WG if needed later

---

## Success criteria

The design is successful if:

- repeated short RTT tests cluster tightly instead of splitting into low/high classes
- game traffic stays stable
- throughput remains strong
- path churn is reduced
- adding or removing one slave does not interrupt the others
- remediation prefers path adjustment over interface restart

---

## Final recommendation

Build:

- latency-first bond on the client-to-slave half
- persistent per-slave WG uplinks on the slave-to-sidecar half
- plain TCP forwarding inside WG
- forward-chain latency intent policy with rolling-window evaluation
- sidepath-first remediation and WG restart only as last resort

This is the cleanest architecture for delivering what users actually want:

- low latency
- high speed
- stable behavior
- low operational blast radius
