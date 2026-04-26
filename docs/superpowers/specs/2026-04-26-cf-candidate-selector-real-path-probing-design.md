# CF Candidate Selector Real-Path Probing Design

**Current branch:** `codex/android-single-bond-linux-parity`

## Goal

Improve slave-side `cf_mode` candidate selection so the chosen `selected_cf_ip` correlates with real websocket tunnel quality instead of just plain TCP connect latency.

## Problem

The current slave-side selector in `slave-agent/slave_agent.py` picks CF candidate IPs using a very weak signal:

- plain TCP connect latency to `candidate_ip:dial_port`
- `jitter_ms` is always `0.0`
- `failures` is always `0`
- switching logic is effectively too naive for real tunnel quality

This makes it easy to choose CF IPs that look good for a fast TCP connect but behave badly for the real path used by the forward:

- TLS handshake quality may be poor
- websocket upgrade success may be poor
- sustained tunnel behavior may be poor
- tiny latency differences can cause unnecessary churn if not gated by a meaningful improvement threshold

## Approved scope

This design changes only slave-side CF candidate selection behavior.

It does **not**:

- change `client-agent` behavior
- change `sidecar-agent` behavior
- change panel provisioning schema
- change UDP behavior
- change who owns selection; the slave still owns `selected_cf_ip`

## Architecture

The boundary stays entirely on the slave side.

1. Expand a bounded list of candidate CF IPs from the configured CIDRs.
2. Probe only a small top slice of candidates within a 3 to 5 second budget.
3. Rank candidates using the real path shape:
   - TCP connect
   - TLS handshake
   - websocket upgrade with the real `Host` header and real `path_prefix`
4. Keep the currently cached `selected_cf_ip` unless a new candidate beats it by at least `switch_improvement_ms`.
5. Persist failure/cooldown information so obviously bad candidates are not immediately reselected.

This keeps the same owner for selection while making the selection signal much more trustworthy.

## Behavior

### Candidate set

The selector still expands IPs from the configured CIDRs, but it does not try to probe the full expanded set in one cycle.

Approved first-pass rule:
- probe only a bounded top slice, not every possible candidate
- practical default target: first 4 to 8 candidate IPs

This keeps runtime predictable and respects the approved 3 to 5 second budget.

### Probe signal

Replace the current weak probe with a real-path probe using the actual forward shape:

1. TCP connect to `candidate_ip:dial_port`
2. TLS handshake using configured SNI
3. websocket upgrade using:
   - configured `Host` header
   - configured `path_prefix`
4. record success/failure and total elapsed time

This is the smallest stronger signal that matches the live path more closely.

### Ranking

Ranking should prefer:

1. successful real-path websocket probes
2. lower total probe latency
3. lower measured jitter across quick repeated samples
4. lower recent failure count

A candidate that fails the real-path probe in the current cycle must be demoted immediately.

### Failure handling

Approved first-pass rule:
- no retry in the same cycle
- a failed candidate is marked bad for that cycle and the selector moves on immediately

This preserves the 3 to 5 second budget and reduces noisy overfitting to transient retries.

### Cached selection and switching

If the slave already has a cached selected IP:

- keep it unless a newly probed candidate is better by at least `switch_improvement_ms`

This makes `switch_improvement_ms` a real anti-thrash guard instead of dead configuration.

### Failure memory and cooldown

Existing fields should start affecting selection behavior:

- `consecutive_failures`
- `cooldown_until`
- `backup_ips`

Expected first-pass semantics:

- candidates with active cooldown are skipped
- candidates that fail the real-path probe increase failure count
- cooldown suppresses immediate reselection of known-bad candidates
- backups are retained for observability and fallback ordering

## Code changes

### Production code

#### `slave-agent/slave_agent.py`

Primary implementation file.

Expected additions/changes:

1. Add a real-path probe helper for CF candidates
   - TCP connect
   - TLS handshake
   - websocket upgrade with real SNI/Host/path
2. Update `probe_cf_candidates()` to return meaningful success/failure and timing data
3. Update `select_cf_location_ip()` to:
   - honor cached current IP
   - only switch when improvement exceeds threshold
   - honor failure/cooldown state
   - keep a bounded candidate budget
4. Preserve existing metadata/state file structure where possible

### Tests

#### `slave-agent/tests/test_slave_cf_location_group_selection.py`

Add or extend regression tests for:

- successful real-path candidate beats a raw-latency-only loser
- failed candidate is demoted immediately
- cached selected IP is retained unless threshold is beaten
- cooldown suppresses bad candidates

### No first-pass changes

- `client-agent/*`
- `master/src/Support/ProvisioningService.php`
- `sidecar-agent/*`

## Verification

### Local verification

Primary proof command:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

This should prove:

- real-path probing is used
- failed candidates are demoted immediately
- cached IPs only switch when the improvement threshold is real
- cooldown suppresses bad candidates

### Live verification

After rollout:

1. push the new slave-agent version to the panel
2. restart Shanghai slave agents
3. restart the Shanghai `wstunnel-svc-2-31x.service` backend services
4. inspect each slave for:
   - selected CF IP
   - live backend cmdline
5. restart the client
6. run exact TCP verification:
   - `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M`

### Success criteria

This pass is successful if:

- selected CF IPs are chosen by the real websocket-path probe instead of naive TCP-only ranking
- obviously bad candidates stop getting reselected immediately
- client TCP throughput improves meaningfully over the current rolled-back baseline
- UDP remains untouched

## Non-goals

This pass does **not**:

- solve general bond behavior outside CF candidate choice
- redesign client-side bond health scoring
- change panel data shape
- enable or debug UDP

## Recommendation

Implement the smallest real-path probe first. If that still does not improve throughput, the remaining problem is likely outside CF candidate selection itself and deeper in the live path behavior.
