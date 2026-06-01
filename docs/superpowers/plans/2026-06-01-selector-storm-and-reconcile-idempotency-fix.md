# Plan: Selector port-collision storm + reconcile idempotency fix (2026-06-01)

## Problem (root-cause cascade — see memory `selector-port-collision-storm`)

After the InnoDB-deadlock fix (d237a63) unblocked heartbeats, forwards 65/66 were still stuck.
Investigation found the panel emits **non-idempotent / non-deterministic provisioning output every
heartbeat**, which the agents react to destructively:

1. **Selector loopback-port collisions (dominant slave-killer).** `ProvisioningService::stableSidecarSelectorPort`
   (~L7560) is a naive hash `45000 + (…)%5000` with NO collision check. Two different (forward,endpoint)
   selectors on the same node hash to the same `127.0.0.1` port (observed 45210 shared). The deployed
   agent crashes on `EADDRINUSE` (line 905) → systemd `Restart=on-failure`/`RestartSec=5` → crash-storm
   (node2: 14,972 restarts/30min across 40 selectors) → load 18–30 on 2-vCPU boxes → agent pull/exec
   starved → endpoints never publish. (Newer agent at slave_agent.py:1019 already exits cleanly on
   EADDRINUSE — stops the *storm* but leaves the loser selector dead; collision-free ports are the real fix.)

2. **Publish-task churn livelock.** `reconcileForwardEndpoints` (~L5560) re-queues a publish for any
   `pending|error|degraded` endpoint EVERY heartbeat; `collapsePendingProvisionTasksForNode` supersedes the
   prior pending task. With oldest-first `LIMIT 20` pull, the fresh task is buried + superseded before any
   agent claims it. 514K churn rows / 5.7 GB / ~12K rows/hr; fwd65/node13 endpoint never published
   (real_agent_done=0).

3. **Endpoint membership flap.** Per-node fwd65/66 rows accumulate dozens of `deleting` rows + a fresh
   `pending` — suggesting the desired-node set (`pickFastestSlavesForCombination` / `pickNodesForGroups`)
   is **non-deterministic** across reconciles → endpoints flap `pending`↔`deleting` → endpoint + task churn.

4. **Artifact/version mismatch (self-inflicted, partly fixed-forward).** versions.json bump declared a
   binary not actually served → agents can't update to the graceful agent. Fixed served artifact to
   f315bc63/249908; STILL need AGENT_VERSION→2026.06.01.1 + versions.json sha realign so the version
   update-loop stops.

## Operational relief (already applied / in progress, out of band)
- Stop+disable crash-looping selector units on slaves via panel jump (node2 load 14.6→2.48).
- Fix-forward published graceful slave-agent.py to /artifacts (curl 200/249908/f315bc63).

## Durable code fixes (this plan — TDD, then deploy)

### FIX A — Idempotent publish re-queue (stop task churn)
In `reconcileForwardEndpoints`, before re-queueing a publish for an unconverged (`pending|error|degraded`)
endpoint, SKIP if a viable task already exists for that (node,action='publish',forward,endpoint):
a `pending` task, OR a `running` task whose `lease_expires_at` is in the future. Only queue when none
exists OR the existing pending task is stale (older than a threshold, e.g. 2× heartbeat) OR the payload
fingerprint drifted. Goal: one stable queued task the agent can actually pull; no per-heartbeat supersession.
Keep the existing fingerprint-drift re-publish for ACTIVE endpoints unchanged.

### FIX B — Collision-free selector ports (stop the crash-storm at source)
Replace the naive hash in `stableSidecarSelectorPort` with a **deterministic, per-node collision-free**
allocation: when computing a forward/endpoint's selector_port, probe within [45000, 45000+5000) starting
at the hash bucket and walk forward (wrapping) skipping ports already assigned to OTHER selector-bearing
endpoints on the same node (reuse `probeFreeSlotInSubrange` ~L7624). Must be deterministic given the
node's selector-endpoint set so republishes reproduce the same port (no gratuitous restarts). Enumerate
the node's selector-bearing endpoints in a stable order (endpoint_id ASC) to build the assigned-set.

### FIX C — Deterministic desired-node selection (stop endpoint flap)  [investigate-then-fix]
Confirm whether `pickFastestSlavesForCombination` / `pickNodesForGroups` returns a non-deterministic set
across calls (e.g. ordering by volatile latency/health, or random tie-break). If so, make selection STABLE:
deterministic tie-break (e.g. node_id) and/or hysteresis (only change membership on a persistent, material
change, not transient latency noise). Goal: stop endpoints flapping pending↔deleting every reconcile.

### FIX D — Agent version reconciliation (stop update-loop)  [deploy step, not code-logic]
Bump `slave_agent.py` `AGENT_VERSION` to "2026.06.01.1"; recompute sha; set `artifacts/versions.json`
slave_agent sha256/size to the new file. Verify `curl /artifacts/slave-agent.py` sha == manifest sha and
== AGENT_VERSION-consistent.

## Verification
- New behavioral tests: (A) reconcile does NOT create a 2nd pending publish when a viable one exists;
  (B) two endpoints on one node never get the same selector_port; ports stable across repeated calls;
  (C) desired-node set identical across repeated calls with unchanged inputs.
- Full PHP suite: 0 new regressions.
- Prod after deploy: churn rows/5min → ~0; fwd65/66 endpoints converge (publish→active as agents recover);
  selector procs stable; slave loads normal.
- Purge 514K superseded churn rows (batched) after the churn stops.
