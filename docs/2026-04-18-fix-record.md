# 2026-04-18 Fix Record

## Summary

This record captures the production-facing fixes completed on 2026-04-18 for:

- `forward 26` bond transport stability
- panel artifact / installer issuance
- client and sidecar self-update behavior

The work split into three layers:

1. fix the bond transport runtime so it behaves better under real `forward 26` traffic
2. fix the panel issuance path so new installs and update helpers actually receive the right binaries
3. fix control-plane mistakes that caused accidental service interruptions

## Completed Fixes

### 1. Sidecar no longer sends `Fin` before outbound backlog is drained

Confirmed from live lifecycle logs:

- sidecar could observe `local_eof`
- immediately send `Fin`
- while `backlog`, `pending_chunks`, and `pending_bytes` were still large

Implemented in:

- `wstunnel-main/leikwan-bond/src/session.rs`

Behavior change:

- `send_fin` now requires:
  - `local_eof == true`
  - `local_backlog.is_empty()`
  - `pending_chunks.is_empty()`

Regression coverage:

- `local_eof_waits_for_buffered_data_before_sending_fin`

Result:

- the earlier “premature fin with large pending backlog” bug was removed
- this narrowed the remaining `forward 26` issue further toward client-side stream failure

### 2. Client-side non-benign stream failure now aborts the persistent transport session

Problem:

- the client listener reused one persistent TCP transport session
- a bad stream could fail
- but the overall transport session could stay alive
- new connections then reused a dirty session
- this looked like “need manual restart before it recovers”

Implemented in:

- `wstunnel-main/leikwan-bond/src/session.rs`

Behavior change:

- for `SessionRole::Client`
- if a stream hits non-benign:
  - `local_read_failed`
  - `local_write_failed`
- the persistent transport session is now forced to exit
- the client listener will reconnect and create a fresh transport session on the next local connection

Regression coverage:

- `client_stream_failure_aborts_persistent_transport`
- `aggregator_stream_failure_does_not_abort_transport`
- `benign_close_does_not_abort_persistent_transport`

Result:

- `forward 26` no longer depends on manual `systemctl restart leikwan-client-26`
- when a transport stream breaks, the persistent session is torn down and recreated automatically

### 3. Client no longer spins forever on unopened streams that already hit local EOF

Live noon investigation on 2026-04-18 showed:

- around `12:04-12:07 CST`
- client created streams `8-15`
- each stream hit `local_eof` before `StreamOpenAck`
- the runtime then kept resending `StreamOpen` forever
- sidecar did not show corresponding valid stream-open activity in the same window

This meant short-lived local connections could become zombie “open-ack pending” streams.

Implemented in:

- `wstunnel-main/leikwan-bond/src/session.rs`

Behavior change:

- if a client-side stream:
  - is still unopened
  - has already reached `local_eof`
  - has no backlog
  - has no pending chunks
  - has waited past `connect_timeout`
- the runtime now abandons that stream instead of endlessly resending `StreamOpen`

Regression coverage:

- `unopened_local_eof_stream_times_out_after_connect_timeout`
- `unopened_local_eof_stream_does_not_timeout_while_data_is_pending`

Result:

- the noon “resend_stream_open forever” pattern is now bounded
- dead local connections should no longer accumulate in the client transport session

### 4. Panel client installer now actually deploys `leikwan-bond`

Problem:

- panel was serving the correct `leikwan-bond` artifact
- but `install-client-agent.sh` did not download it
- so a new client install could still miss the bond runtime entirely

Implemented in:

- `install/install-client-agent.sh`

Behavior change:

- installer now downloads:
  - `/artifacts/wstunnel/leikwan-bond_linux_amd64`
- installer now persists:
  - `installer_version`

Regression coverage:

- `client-agent/tests/test_client_agent_installer_helper.py`

Result:

- a fresh client install can now receive the production bond binary directly from panel

### 5. Client update helper now stops active forward services before reinstall

Problem:

- client update helper stopped `leikwan-client-agent.service`
- but did not explicitly stop active `leikwan-client-*.service` units first

Implemented in:

- `install/client-agent-update.sh`

Behavior change:

- helper now enumerates active `leikwan-client-*.service` units
- stops them before running installer

Regression coverage:

- `client-agent/tests/test_client_agent_installer_helper.py`

Result:

- client updates no longer risk leaving old forward runtimes alive while files are replaced

### 6. Sidecar now has a real update helper and artifact route

Problem:

- sidecar installer downloaded `leikwan-bond`
- but there was no dedicated sidecar update helper path
- sidecar agent had no self-update implementation

Implemented in:

- `install/install-bond-sidecar.sh`
- `install/sidecar-agent-update.sh`
- `sidecar-agent/sidecar_agent.py`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Bootstrap.php`
- `artifacts/versions.json`

Behavior change:

- panel now serves:
  - `/artifacts/sidecar-agent-update.sh`
- sidecar installer now downloads the update helper
- sidecar agent now compares:
  - desired sidecar agent version / sha256
  - desired bond sha256
  - desired installer version
- on mismatch it can request self-update through the helper

Regression coverage:

- `client-agent/tests/test_sidecar_agent_update_helper.py`
- `master/tests/sidecar_agent_update_artifact_regression_test.php`

Result:

- the sidecar update path is no longer manual-only
- current sidecar host was bootstrapped once and now has a functioning update helper

### 7. Panel no longer mis-generates sidecar bond auto-update tokens

Problem:

- `SidecarAgentController.php` used `sidecar_points.agent_version`
- to derive an automatic bond SHA update token
- but `agent_version` stores the sidecar agent version, not the bond binary SHA
- this could trigger repeated sidecar self-update loops and runtime interruptions

Observed on the live sidecar:

- repeated alternating requests like:
  - `auto-sidecar-bond:sha256=...`
  - `auto-release:agent=2026.04.18.1`
- with repeated agent stop/start cycles

Implemented in:

- `master/src/Controllers/SidecarAgentController.php`

Behavior change:

- panel no longer auto-generates sidecar bond update tokens from `point.agent_version`
- explicit `agent_update_request_token` is still honored
- `desired_bond_sha256` is still published to the sidecar
- actual bond mismatch decisions are now left to the sidecar's own local SHA check

Regression coverage:

- `master/tests/sidecar_agent_update_token_regression_test.php`

Result:

- the accidental sidecar auto-update loop trigger was removed from panel logic

## Verification

Local verification completed:

```bash
cargo test -p leikwan-bond
python -m unittest client-agent.tests.test_client_agent_installer_helper
python -m unittest client-agent.tests.test_sidecar_agent_update_helper
php master/tests/client_agent_update_artifact_regression_test.php
php master/tests/sidecar_agent_update_artifact_regression_test.php
php master/tests/sidecar_agent_update_token_regression_test.php
python -m py_compile sidecar-agent/sidecar_agent.py
```

Observed result:

- all targeted regressions passed

## Deployment State

Latest deployed panel/client/sidecar bond binary SHA:

- `d9eedc2a3df4680a59465994288b00869e896b77ee004691044e656d015013e0`

Panel issuance state after this pass:

- panel artifact `leikwan-bond_linux_amd64` matches that SHA
- panel now serves `/artifacts/sidecar-agent-update.sh`
- panel client installer now downloads `leikwan-bond`
- panel sidecar installer now downloads the sidecar update helper

Current live hosts:

- client `/usr/local/bin/leikwan-bond` SHA matches panel
- sidecar `/usr/local/bin/leikwan-bond` SHA matches panel

## Remaining Concerns

These fixes improve stability and issuance, but they do not solve the whole performance story.

Still open:

- `forward 26` steady-state throughput is still well below stable `500 Mbit/s+`
- 5 legs are real and active, but the current pool still behaves like a `300-400 Mbit/s` steady-state bundle
- more work is still needed on:
  - path selection / better slave pools
  - or deeper stream-level reset behavior when bulk traffic is sustained

## Recommended Next Step

The next session should not spend time rediscovering installer/update chain behavior.

The most valuable next step is:

1. keep the new issuance and self-update chain as the stable baseline
2. continue forward-26 performance work on top of `d9eed...`
3. test stronger slave mixes rather than assuming current Guangzhou 5-leg pool can be tuned to stable `500+ Mbit/s`
