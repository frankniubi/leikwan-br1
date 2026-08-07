# LeiGod Throughput-Aware Race Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in passive throughput learner so LeiGod race stops preferring a sustained-throughput-degraded line solely because it has the lowest SOCKS connect latency.

**Architecture:** Keep each TCP connection pinned to one candidate and keep connect latency as the default policy. Under `selection_signal=throughput_ewma`, reserve unsampled candidates across concurrent connections, collect direction-aware delivered-byte/stall samples from real application flows, maintain expiring per-candidate EWMA scores, and periodically explore the stalest healthy candidate. Metrics remain scoped to one relay target and are atomically persisted in the existing state file.

**Tech Stack:** Python 3/asyncio/unittest (`leigod_forward`), PHP 8 panel publishing, JSON state/artifact manifests, controlled iperf3 canary measurements.

---

## File Map

- Modify `slave-agent/leigod_forward_relay.py`: throughput policy parsing, flow telemetry, candidate scoring/reservation, and metric restore.
- Modify `slave-agent/tests/test_leigod_forward_relay.py`: pure scoring, concurrent exploration, qualification, expiry, and integration tests.
- Modify `slave-agent/slave_agent.py`: accept bounded race policy values from signed publish tasks.
- Modify `slave-agent/tests/test_slave_leigod_forward_publish.py`: strict policy contract tests.
- Modify `master/src/Support/ProvisioningService.php`: publish opt-in race policy from configuration without changing single or default race behavior.
- Modify `master/tests/publish_dedicated_leigod_candidates_test.php`: policy publication and default regression checks.
- Synchronize the slave artifact mirror and `artifacts/versions.json` after source tests pass. The
  relay artifact route reads its source file directly.

### Task 1: Define and Validate the Throughput Policy

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:52-75`

- [ ] **Step 1: Write failing policy parsing tests**

Add a valid policy:

```python
payload["policy"].update({
    "selection_signal": "throughput_ewma",
    "throughput_min_bytes": 1_048_576,
    "throughput_min_seconds": 2.0,
    "throughput_ewma_alpha": 0.25,
    "throughput_explore_every_connections": 20,
    "throughput_sample_ttl_seconds": 1800,
    "throughput_stall_interval_seconds": 1.0,
})
```

Assert exact parsed values. Add separate rejection tests for an unknown signal, zero/negative limits,
alpha outside `(0,1]`, exploration outside `2..10000`, TTL outside `30..86400`, and enabling
throughput scoring outside race mode.

- [ ] **Step 2: Run the relay tests and verify RED**

Expected: unsupported policy keys.

- [ ] **Step 3: Implement bounded policy fields**

Keep `selection_signal="connect_latency"` as the default. Parse the exact fields above and enforce the
test bounds. Reject `throughput_ewma` unless `mode == "race"` and TCP is enabled.

- [ ] **Step 4: Run policy tests and verify GREEN**

Expected: valid policy passes and every invalid boundary fails closed.

- [ ] **Step 5: Commit policy parsing**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(race): validate passive throughput selection policy"
```

### Task 2: Add Direction-Aware Flow Telemetry

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:450-535`

- [ ] **Step 1: Write failing pure telemetry tests with a fake clock**

Test a 4-second upload flow with bytes in seconds 0, 1, and 3. Assert one zero interval, exact byte
count, qualifying duration, and delivered Mbps. Test a short control flow and a sub-1-MiB flow are
not qualifying. Test download metrics are independent from upload metrics.

Use the wished-for API:

```python
telemetry = ConnectionTelemetry(started_at=100.0, stall_interval_seconds=1.0)
telemetry.record("upload", 524_288, now=100.2)
telemetry.record("upload", 524_288, now=101.2)
telemetry.record("upload", 524_288, now=103.2)
sample = telemetry.finish(now=104.2, min_bytes=1_048_576, min_seconds=2.0)
self.assertEqual(1, sample.upload_zero_intervals)
self.assertTrue(sample.upload_qualifies)
```

- [ ] **Step 2: Run tests and verify RED**

Expected: telemetry classes do not exist.

- [ ] **Step 3: Implement telemetry without a background timer**

Bucket delivered bytes by `int((now - first_payload_at) / interval)`. At finish, count missing buckets
between first and last activity only for directions that carried payload. This keeps one bounded map
per connection and does not schedule one timer per stream.

- [ ] **Step 4: Run telemetry tests and verify GREEN**

Expected: exact byte, duration, Mbps, and zero-interval assertions pass.

- [ ] **Step 5: Commit telemetry**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(race): measure delivered flow throughput and stalls"
```

### Task 3: Implement EWMA Scores and Concurrent Exploration Reservations

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:131-162,330-450`

- [ ] **Step 1: Write failing candidate-score tests**

Feed candidate 451 `1 Mbps / 0.5 stall` and candidate 447 `100 Mbps / 0 stall`; assert 447 wins even
when 451 has lower connect latency. Feed only upload for a candidate and assert its score receives the
documented 0.5 incomplete-direction discount. Expire one candidate beyond TTL and assert it becomes
unsampled.

- [ ] **Step 2: Write failing concurrent reservation tests**

Call `reserve_tcp_candidate(now)` four times before completing any sample and assert four distinct
unsampled candidates are returned. Release a nonqualifying reservation and assert it becomes
available for exploration again. After all candidates have samples, assert normal selection uses the
highest balanced score and every 20th qualifying assignment selects the stalest healthy candidate.

- [ ] **Step 3: Run focused tests and verify RED**

Expected: state metrics and reservation methods do not exist.

- [ ] **Step 4: Implement candidate throughput state**

Add bounded fields:

```text
upload_ewma_mbps, download_ewma_mbps,
upload_stall_ewma, download_stall_ewma,
qualifying_samples, sample_updated_at,
sampling_in_flight
```

Use the configured alpha. Balanced score is the minimum valid directional score times the worst
stall penalty; one-direction-only state is multiplied by `0.5`. Latency and configured order remain
tie-breakers.

- [ ] **Step 5: Implement selection and exploration without changing defaults**

`connect_latency` continues through `_reselect_tcp` exactly as today. `throughput_ewma` uses
`reserve_tcp_candidate`, marks in-flight before awaiting SOCKS, releases on connect failure, and
updates `selected_tcp_line_id` to the current best for heartbeat observability. Existing connections
remain pinned.

- [ ] **Step 6: Run selection tests and the existing latency race tests**

Expected: all new tests pass and `test_race_selects_lowest_latency_after_hysteresis` remains green.

- [ ] **Step 7: Commit the learner**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(race): select lines by expiring throughput EWMA"
```

### Task 4: Wire Real Relay Connections into the Learner

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:470-535,600-640`

- [ ] **Step 1: Add a failing integration test with fast test thresholds**

Configure two fake SOCKS routes, throughput minimum 1024 bytes / 0.05 seconds, and alpha 1.0. Make
line 451 echo with a throttled delay and 453 echo immediately. Run qualifying connections through
both, then assert a third connection reserves 453 even though 451's connect delay is lower.

- [ ] **Step 2: Run the integration test and verify RED**

Expected: connection data does not update candidate throughput state.

- [ ] **Step 3: Return candidate identity from `connect_for_client`**

Return `(line_id, reader, writer)` and create one `ConnectionTelemetry` in `handle_tcp`. Extend
`pipe_stream` with a byte callback and direction name. In `finally`, finish the sample, update the
candidate, clear its reservation, reselect the best throughput candidate, and atomically write state.

- [ ] **Step 4: Handle errors and cancellation once**

Ensure an exception before a qualifying sample releases `sampling_in_flight`, increments existing
failure state only once, and never records zero Mbps as a valid sample. Keep current close/drain
behavior unchanged.

- [ ] **Step 5: Run the entire relay suite**

Expected: deterministic bond, single failover, latency race, UDP, reload, and throughput race all
pass.

- [ ] **Step 6: Commit live-flow integration**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(race): learn from pinned application flows"
```

### Task 5: Persist and Restore Only Fresh Target-Scoped Metrics

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:535-690`

- [ ] **Step 1: Write failing state restore tests**

Write state containing fresh metrics for target A and assert a new runtime for the same target and
candidate identities restores them. Assert target B, changed candidate identity, expired timestamp,
malformed JSON, and non-finite numeric values restore nothing.

- [ ] **Step 2: Run restore tests and verify RED**

Expected: every new selector starts without throughput samples.

- [ ] **Step 3: Add a nonsecret state scope key and strict restore**

Persist `target_host`, `target_port`, mode, line/combination/route identity, bounded finite metrics,
and timestamps. Restore only an exact config identity within TTL. On SIGHUP, transfer compatible
fresh metrics directly from the old selector before writing the new generation.

- [ ] **Step 4: Run restore and reload suites and verify GREEN**

Expected: compatible state survives restart/reload and incompatible state is ignored without startup
failure.

- [ ] **Step 5: Commit persistence**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(race): restore fresh target-scoped throughput state"
```

### Task 6: Publish the Opt-In Race Policy Through Panel and Slave Agent

**Files:**
- Modify: `master/tests/publish_dedicated_leigod_candidates_test.php`
- Modify: `master/src/Support/ProvisioningService.php:7820-7840`
- Modify: `slave-agent/tests/test_slave_leigod_forward_publish.py`
- Modify: `slave-agent/slave_agent.py:3930-3952`

- [ ] **Step 1: Add failing panel default and opt-in tests**

Assert no configuration keeps `selection_signal=connect_latency`. Create a service with:

```text
LEIGOD_RACE_SELECTION_SIGNAL=throughput_ewma
LEIGOD_RACE_THROUGHPUT_MIN_BYTES=1048576
LEIGOD_RACE_THROUGHPUT_MIN_SECONDS=2
LEIGOD_RACE_THROUGHPUT_EWMA_ALPHA=0.25
LEIGOD_RACE_THROUGHPUT_EXPLORE_EVERY_CONNECTIONS=20
LEIGOD_RACE_THROUGHPUT_SAMPLE_TTL_SECONDS=1800
```

and assert the exact bounded fields appear only on race payloads, never single or bond.

- [ ] **Step 2: Add failing slave pass-through and bound tests**

Assert valid policy reaches relay runtime config unchanged and invalid task values are clamped to the
same panel bounds or rejected for an unknown signal.

- [ ] **Step 3: Run PHP and Python tests and verify RED**

Expected: policy fields are absent.

- [ ] **Step 4: Implement explicit publication and validation**

Publish the default explicitly for observability. Only attach throughput parameters when signal is
`throughput_ewma`. The slave allowlists each field and emits it under relay `policy`; it does not
accept arbitrary policy keys from the task.

- [ ] **Step 5: Run focused tests and verify GREEN**

Expected: panel/slave values match and single/bond contracts remain unchanged.

- [ ] **Step 6: Commit policy propagation**

```powershell
git add master/src/Support/ProvisioningService.php master/tests/publish_dedicated_leigod_candidates_test.php slave-agent/slave_agent.py slave-agent/tests/test_slave_leigod_forward_publish.py
git commit -m "feat(panel): publish opt-in throughput-aware race policy"
```

### Task 7: Synchronize Artifacts and Run the Race Release Gates

**Files:**
- Modify: `artifacts/slave-agent.py`
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Bump relay/slave source versions and synchronize mirrors**

Use the next date patch after the fixed-bond release. Copy `slave-agent/slave_agent.py` to
`artifacts/slave-agent.py`; hash `slave-agent/leigod_forward_relay.py` directly for its routed
manifest entry. Update only matching version/hash/size fields.

- [ ] **Step 2: Run all relay, slave publish, artifact, and panel tests**

Run:

```powershell
python -m unittest slave-agent/tests/test_leigod_forward_relay.py slave-agent/tests/test_slave_leigod_forward_publish.py slave-agent/tests/test_slave_artifact_consistency.py -v
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && php master/tests/publish_dedicated_leigod_candidates_test.php && php master/tests/leigod_forward_helper_artifact_distribution_test.php"
git diff --check
```

Expected: all pass.

- [ ] **Step 3: Commit the race artifact set**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/slave_agent.py artifacts/slave-agent.py artifacts/versions.json
git commit -m "release: publish throughput-aware leigod race"
```

### Task 8: Controlled Local and Server Acceptance

**Files:**
- Modify after evidence: `docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`

- [ ] **Step 1: Run five individual-line controls before changing policy**

For each configured line run five P4 and five reverse tests through the same fixed public endpoint and
target. Record receiver Mbps, retransmits, zero intervals, route state, and timestamps. Do not use
these results unless all commands complete normally.

- [ ] **Step 2: Enable throughput policy only on the canary**

Deploy committed artifacts, set the opt-in panel configuration, republish the same pinned endpoint,
and verify the public tuple and endpoint ID did not change.

- [ ] **Step 3: Warm every candidate with qualifying flows**

Verify state shows at least one qualifying sample per candidate and no duplicate
`sampling_in_flight` assignment during P4 concurrency.

- [ ] **Step 4: Run the formal post-warmup race comparison**

Run five P4 and five reverse tests. Accept race only if it does not select a materially degraded
latency winner and its receiver median reaches at least 80% of the best current per-line control for
the corresponding direction.

- [ ] **Step 5: Run the fixed-bond acceptance set**

Require five preferred routes normally ready before the first stream, P4 median at least 150 Mbps,
all five reverse results above 100 Mbps, zero zero-throughput intervals, and no transport reconnect,
unit restart, or cooldown in the clean set.

- [ ] **Step 6: Clean all transient state and synchronize the accepted release**

Disable/delete canary forwards through `ProvisioningService`, wait for destroy tasks, remove only
known transient units/listeners/target services, verify agents remain active, then synchronize the
accepted committed artifacts to the panel/server store.

- [ ] **Step 7: Update and commit the handoff**

Record exact commit, artifact hashes, result directory/hash, per-line controls, formal summaries,
runtime evidence, rollback paths, and final zero-residue checks. Keep credentials and raw secrets out
of the document.
