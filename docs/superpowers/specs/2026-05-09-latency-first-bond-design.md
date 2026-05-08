# Latency-First Bond Design

## Date
- 2026-05-09

## Branch
- `master`

## Goal

Build a new bond policy that gives users:

- latency close to the best single leg
- higher throughput than single-leg mode
- stable game-play behavior
- the same product promise in both normal bond mode and CF-bond mode

This is a transport-policy change, not a topology rewrite.

## Problem

Current bond behavior is too eager to use multiple legs for live traffic. That is good for raw throughput, but it raises felt latency and can introduce jitter for interactive traffic.

In the live Guangzhou -> HK environment:

- normal bond mode can add the slave -> HK sidecar hop cost to the user-visible path
- CF-bond mode can also choose helper legs too early, or choose the wrong CF primary if selection is based on shallow reachability
- UDP gameplay traffic is especially sensitive to per-packet path variation and reorder buffering

Users want both:

- low latency for game play and responsiveness
- high speed for large transfers

The design must preserve the current `client -> slave -> sidecar` topology and keep the sidecar as the final HK aggregation point.

## Hard invariants

- Keep topology `client -> slave -> sidecar`
- Do not bypass slaves
- Do not move final aggregation away from the sidecar in this environment
- Keep the current leg-health logic family:
  - healthy
  - probation / degraded
  - quarantined
  - cooldown / weight reduction / suppression
- Keep bond and MPTCP ingress on slave public hosts within `7000-9000`
- Treat normal bond and CF-bond as policy variants over the same transport foundation

## Non-goals

- No direct client -> sidecar mode
- No removal of the sidecar from HK pathing
- No assumption that outer WireGuard UDP or generic UDP alone identifies game traffic
- No phase-one bulk striping for unknown UDP
- No rewrite of the existing health engine

## Design summary

Introduce a new internal bond policy:

- `bond_latency_first`

This policy keeps one leg as the **primary latency leg** and treats all other legs as **helper legs**.

### Primary leg

The primary leg is the latency anchor. It handles:

- stream open
- TCP handshake-sensitive traffic
- ACK-heavy traffic
- short request-response traffic
- interactive / game-class UDP
- first response path for new sessions

### Helper legs

Helper legs do not participate in interactive traffic. They are allowed to:

- join clearly bulk TCP sessions
- act as failover candidates
- optionally participate in future bulk UDP work, but not in phase one

### Sidecar

The sidecar stays:

- the final HK aggregation point
- the premium CNIX / IEPL-side transport endpoint
- the place where the real upstream target connection is finalized

## Architecture

### Normal bond mode

```text
client
  -> primary Guangzhou slave
  -> HK sidecar
  -> target

client
  -> helper Guangzhou slave(s)
  -> HK sidecar
  -> target
```

### CF-bond mode

```text
client
  -> primary slave
  -> selected CF IP
  -> sidecar CF ingress
  -> sidecar bond server
  -> target

client
  -> helper slave(s)
  -> selected helper CF IP(s)
  -> sidecar CF ingress
  -> sidecar bond server
  -> target
```

The difference between normal bond and CF-bond is path selection, not the latency-first policy itself.

## Leg-health model

The current health engine stays in place and continues to answer:

- which legs are safe
- which legs are degraded
- which legs are quarantined
- which legs are available for failover or helper use

This health engine remains the path-quality layer.

The new design adds a separate policy layer above it:

- health says which legs are usable
- policy says whether the session should use one leg or many

## Traffic policy

## TCP

Every TCP session starts in **interactive mode**:

- primary leg only
- helper legs silent

The session stays interactive while it still looks like:

- stream open / handshake
- small writes
- low in-flight bytes
- low total bytes transferred
- ACK-dominant traffic
- short request-response behavior

The session is promoted to **bulk mode** only after strong evidence:

- sustained payload above threshold
- sustained backlog / in-flight growth
- total bytes above promotion threshold
- repeated scheduling windows showing bulk behavior

### TCP policy

- interactive TCP -> primary only
- bulk TCP -> primary + healthy helpers

### TCP hysteresis

The implementation must avoid rapid promote/demote flapping.

Rules:

- promote on a higher threshold
- demote only on a materially lower threshold, or not until session end

This protects user-visible stability.

## UDP

UDP is stricter because reorder and jitter hurt more.

### Phase-one UDP rule

- all interactive / unknown UDP stays on primary only
- helper legs are failover-only

### Game / interactive UDP

Traits:

- small packets
- frequent packets
- high sensitivity to jitter and reorder
- often bidirectional

Rule:

- primary only

### Bulk UDP

This is intentionally deferred.

Future versions may allow helper use for explicitly bulk UDP, but phase one should not stripe unknown UDP.

## Application detection

Protocol label alone is not enough for tunneled traffic.

This is especially true for:

- WireGuard UDP
- generic Shadowsocks UDP relay

The outer UDP tunnel does not prove the inner application is a game. It may also be downloads, web, intranet transfers, backup traffic, or anything else.

### Detection order

#### 1. Explicit hints

Best signal. Prefer:

- DSCP / fwmark
- per-forward profile
- local port / profile mapping
- process or cgroup marks where available

#### 2. Policy mapping

Fallback:

- admin-assigned profile per forward
- optional user-facing profile later

#### 3. Flow heuristics

Last resort:

- packet size
- packet rate
- bidirectionality
- byte rate
- sustained in-flight growth
- reorder sensitivity

### Safe default

If classifier confidence is low:

- prefer latency safety over aggressive striping
- unknown UDP stays primary-only
- short / interactive TCP stays primary-only

## WireGuard-specific rule

For WireGuard-carried traffic:

- do not classify by outer UDP identity alone
- use explicit hints first
- otherwise start primary-only and only promote after strong bulk evidence

## CF-bond mode rules

CF-bond uses the same latency-first policy, with extra selection rules.

### CF primary selection

Primary CF leg must be chosen using the strongest real-path signal available, in this order:

1. real bond/session success
2. real websocket / WSS path probe
3. repeated latency + jitter measurements
4. health history
5. shallow reachability only as last resort

### CF safeguards

- do not rank a leg as perfect if its real latency is missing
- do not churn primary leg on tiny improvements
- require meaningful improvement before switching primary
- helper CF legs may be healthy for bulk but still forbidden for interactive traffic

### CF traffic policy

- interactive TCP -> primary CF leg only
- bulk TCP -> primary + healthy helper CF legs
- UDP -> primary only by default

## Session pinning

Session pinning is mandatory.

### Rules

- choose primary once at session start
- keep the session pinned there unless hard failure happens
- do not round-robin interactive sessions across legs
- do not re-elect primary repeatedly during live gameplay

For TCP:

- helpers may join after bulk promotion
- primary still defines the latency anchor

For UDP:

- keep the whole session on primary in phase one

## Failover

### Soft degradation

If the primary degrades slightly:

- do not flap immediately
- require repeated failure evidence

### Hard failure

If the primary fails clearly:

- promote the best healthy helper
- pin the session to the new primary where possible

### Failure signals

Examples:

- stream open timeout
- control socket closed unexpectedly
- repeated retransmit burst
- leg closed
- missing ACK progress
- extreme RTT growth

Failover should be:

- fast enough to preserve usability
- conservative enough to avoid ping oscillation

## Product behavior

Users should experience:

- game and speedtest latency close to the best single leg
- large transfers scaling above single-leg throughput
- stable game sessions
- no need to understand sidecars, helpers, or striping stages

Phase one can expose this as a single user-facing mode:

- `Smart Bond`

Internal policy name:

- `bond_latency_first`

## Rollout plan

### Phase 1

Ship:

- latency-first TCP
- primary-only UDP
- helper failover
- current health engine preserved
- normal bond + CF-bond support

### Phase 2

Add:

- DSCP / fwmark support
- per-forward profile hints
- optional user-visible policy mapping if needed

### Phase 3

Optional future work:

- explicitly bulk UDP helper participation
- richer app classification
- smarter helper diversity in CF mode

## Verification

### Latency tests

Compare:

- best single leg
- current bond mode
- new latency-first bond

Success:

- new mode much closer to single-leg latency than current eager bond

### Throughput tests

Compare:

- single leg
- current bond peak
- new latency-first bond

Success:

- new mode materially exceeds single-leg throughput
- slight loss vs maximum eager-bond peak is acceptable if latency drops significantly

### Gameplay tests

Verify:

- no ping oscillation
- no helper-leg contamination of interactive traffic
- no reorder-buffer spikes for game-class UDP

### Failure tests

Force primary failure.

Success:

- helper promotion works
- no endless switching loop

## Risks

- Misclassification can hide helper capacity or hurt latency
- Too-aggressive helper promotion can reintroduce jitter
- Too-conservative promotion can reduce throughput gain
- CF path noise can cause poor primary selection if real-path probes are weak

These risks are why:

- health and policy stay separate
- UDP stays conservative in phase one
- explicit hints are preferred over guessing

## Final recommendation

Implement a new latency-first bond policy that:

- keeps the sidecar unchanged as final HK aggregation point
- preserves the current health engine
- uses one primary latency leg for interactive traffic
- allows helper legs only for proven bulk TCP at first
- keeps UDP primary-only by default
- extends the same product promise to CF-bond mode with stronger real-path primary selection

This gives the user a clear outcome:

- low latency when they feel it
- high speed when they need it
- stable gameplay instead of fast-but-shaky transport
