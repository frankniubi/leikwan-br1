# 2026-05-23 sh36 `lw-cfwg-47` and slave resource exhaustion fix

## Outcome

- `sh36` forward 47 is running again on the `lw-cfwg-47` primary path.
- The slave resource-exhaustion issue was traced to UOT memory growth plus slave self-update retry/install churn.
- The resource fix was synced to the panel artifact set and rolled out to the checked slave nodes.
- Post-rollout checks showed current slave agent/UOT hashes, active slave agents, no new OOM events after the rollout window, and materially lower UOT RSS.

## Scope and topology

- Kept the required topology: `client -> slave -> sidecar`.
- Did not use or propose direct client-to-sidecar connectivity.
- Kept slave MPTCP/bond ingress within the expected `7000-9000` range for managed ingress. Some observed non-managed or historical listeners outside that range were recorded as evidence but not treated as the desired slave ingress contract.
- Kept sensitive credentials local-only; this handoff intentionally does not include passwords/tokens/private keys.

## Root cause

### 1. `lw-cfwg-47` startup/runtime issue on sh36

Observed on sh36 during the investigation:

- `leikwan-client-47.service` was eventually restored to `active/running`.
- `lw-cfwg-47` had a fresh WireGuard handshake.
- `127.0.0.1:11035` local connect worked.
- `10.204.53.42:8898` over `lw-cfwg-47` worked at about `33.8ms`.

A prior failure mode in the logs was `wg-quick: 'lw-cfwg-47' already exists`, caused by the runtime trying to start the systemd `wg-quick@lw-cfwg-47.service` path while the interface already existed. The later runtime path uses direct `wg-quick down/up` style recovery and keeps the active interface healthy.

### 2. Slave nodes became unresponsive after SSH login

The decisive resource evidence was on small-memory slave nodes:

- UOT process memory growth:
  - node 14 had `leikwan-uot.py` around `143MB` RSS before the fix.
  - node 17 kernel logs showed an OOM kill of a UOT process at roughly `270MB` anon RSS.
- A later OOM killed a `systemctl` process under `ssh.service`, matching the symptom where SSH logs in but commands stop responding.
- Reboot fixed the symptom because it reset bloated UOT processes and transient failed update state.

A second contributing factor was slave self-update churn:

- Some slaves had stale/broken updater state:
  - old missing update helper
  - `install-slave exited with status 127`
  - CRLF installer execution failure: `/usr/bin/env: 'bash\r': No such file or directory`
- The slave agent kept requesting update units, creating failed transient systemd units and avoidable resource churn.

## Code changes made

### `client-agent/leikwan_uot.py`

Added bounded datagram queues:

- `MAX_QUEUE_DATAGRAMS = 512`
- `enqueue_datagram(...)` drops new datagrams when the queue is full instead of allowing unbounded memory growth.
- Client and server runtime queues now use `asyncio.Queue(maxsize=MAX_QUEUE_DATAGRAMS)`.

### `slave-agent/slave_agent.py`

Added resource and restart guards:

- UOT client/server units:
  - `MemoryMax=96M`
  - `RestartSec=10`
  - `StartLimitIntervalSec=120`
  - `StartLimitBurst=10`
- Preconnect relay units:
  - `MemoryMax=128M`
  - `RestartSec=10`
  - `StartLimitIntervalSec=120`
  - `StartLimitBurst=10`
- Plain mux / listener units now also reduce restart churn with `RestartSec=10` and start-limit guards.

Hardened self-update scheduling:

- Added `UPDATE_ERROR_RETRY_SECONDS = 1800`.
- Recent failed updates are throttled longer than normal update retries.
- `request_self_update(...)` writes `running` state before launching `systemd-run`, preventing repeated scheduling while a helper launch is pending.
- `systemd-run` now uses `--collect`.
- Update helper is invoked via explicit `bash` so CRLF shebang failures do not break execution.
- Transient `systemd-run` creation failure leaves the request throttled until stale instead of immediately retrying in a tight loop.

### `install/install-slave.sh`

- Added `normalize_script_line_endings(...)` for downloaded shell artifacts.
- `download_atomic(...)` normalizes scripts to LF before installing them.
- Added `ensure_self_signed_tls_assets(...)` so self-signed TLS cert/key material exists before listener/mux startup when `TLS_MODE=self-signed`.

### `install/leikwan-slave-update`

- Normalizes downloaded `/tmp/leikwan-install-slave.sh` to LF before execution.
- Runs the downloaded installer through `bash` instead of relying on the shebang.

### `master/src/Controllers/ClientAgentController.php`

- Publishes desired UOT runtime metadata to clients:
  - `desired_uot_runtime_version`
  - `desired_uot_runtime_sha256`
- Accepts/report-preserves `uot_runtime_sha256`.
- Preserves `disable_auto_update` in managed client config snapshots.

### `master/src/Support/ProvisioningService.php`

- Added MPTCP slave exclusion helpers via config:
  - `MPTCP_SLAVE_EXCLUDED_NODE_IDS`
  - `MPTCP_SLAVE_EXCLUDED_PUBLIC_HOSTS`
- Excluded nodes are skipped in MPTCP helper candidate/endpoint generation and stale excluded endpoints can be marked deleted.
- MPTCP runtime config now includes all `slave_relays` for preconnect mode rather than only a single relay, allowing the client runtime to know the available relay set.

### Tests added/updated

- `client-agent/tests/test_leikwan_uot_runtime.py`
  - Verifies full UOT queue drops safely without crashing.
  - Verifies runtime queues are bounded.
- `slave-agent/tests/test_slave_installer_helper.py`
  - Verifies installer LF normalization.
  - Verifies update helper LF normalization and explicit `bash` execution.
  - Verifies listener/UOT/preconnect restart and memory guards exist.
  - Verifies self-signed TLS assets are generated before service start.
- `slave-agent/tests/test_slave_mptcp_cleanup.py`
  - Verifies raw byte hashing consistency.
  - Verifies failed updates are throttled.
  - Verifies running update state prevents auto/explicit ping-pong.
  - Verifies `request_self_update(...)` marks running before `systemd-run`.
  - Verifies CRLF-safe helper invocation.

## Local verification

Commands run:

```powershell
py -3 -m unittest client-agent.tests.test_leikwan_uot_runtime slave-agent.tests.test_slave_mptcp_cleanup slave-agent.tests.test_slave_installer_helper
py -3 -m py_compile client-agent\leikwan_uot.py slave-agent\slave_agent.py
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
```

Results:

- Unit tests: `Ran 25 tests ... OK`
- Python compile: passed
- PHP lint:
  - `No syntax errors detected in master/src/Support/ProvisioningService.php`
  - `No syntax errors detected in master/src/Controllers/ClientAgentController.php`

## Panel sync / artifact verification

Synced the tested files to the panel repo and public artifact paths.

Panel-side verification showed:

- `slave-agent/slave_agent.py` SHA: `d3e92fbdad17dc9d067f5aad442ee13e4bb1d1898f89fb1bc93df807982cbe6b`
- `client-agent/leikwan_uot.py` SHA: `887cbde271bfeaebb8af2b7e6d71631b10ae85ff712c07a5453d4ee22f905b63`
- `install/install-slave.sh` SHA: `88c6322f5f4dc405f6bf14ea249ba4676aa7bdd3f9e57530474db013d348793c`
- `install/leikwan-slave-update` SHA: `b2b27dff6333ee2042657c81bfcc33b566bb6036d1042db76705d0f8842c1f5c`
- Public artifact paths for installer/update scripts were also LF-normalized and hash-matched.

## Live rollout summary

Rolled the fix to the checked slave hosts using a bounded batch script.

Post-rollout sanity evidence:

| Node | Status | Agent SHA | UOT SHA | Memory evidence | Update state |
| --- | --- | --- | --- | --- | --- |
| 12 | active | `d3e92fbd...` | `887cbde2...` | ~208MB/419MB available | success |
| 13 | active | `d3e92fbd...` | `887cbde2...` | ~214MB/419MB available | success |
| 14 | active | `d3e92fbd...` | `887cbde2...` | UOT reduced from ~143MB to ~21MB; ~212MB/419MB available | success |
| 15 | active | `d3e92fbd...` | `887cbde2...` | ~256MB/419MB available | success |
| 16 | active | `d3e92fbd...` | `887cbde2...` | ~258MB/419MB available | success |
| 17 | active | `d3e92fbd...` | `887cbde2...` | UOT around ~21MB; ~391MB/906MB available | success |
| 18 | active | `d3e92fbd...` | `887cbde2...` | UOT around ~20MB; ~230MB/419MB available | success/current |

Additional post-rollout checks:

- No OOM lines after the rollout window on checked hosts.
- No update retry storm after the rollout window.
- All checked slave agents were `active`.
- Node 13 required manual state cleanup because its updater hit `curl` exit `56` after the binaries had already updated; final state was normalized to success after confirming hashes and active service.

## sh36 verification after rollout

Fresh sh36 read-only check:

- `leikwan-client-agent.service`: `active/running`
- `leikwan-client-47.service`: `active/running`
- `lw-cfwg-47` WireGuard interface present with fresh handshake.
- `local_forward`: `127.0.0.1:11035` OK at `0.1ms`
- `cfwg_sidecar`: `10.204.53.42:8898` OK at about `33.8ms`
- Client UOT runtime hash matches the bounded-queue artifact: `887cbde271bfeaebb8af2b7e6d71631b10ae85ff712c07a5453d4ee22f905b63`

The slave preconnect helper probes still timed out from sh36 in the last read-only check, but forward 47 is working on the `lw-cfwg-47` primary path, which is the intended recovered path for this issue.

## Remaining notes / follow-ups

- Some stale failed `wg-quick@...` units remain on a few slaves, for example historical `lwsc*`/`lwf*` failures. These were not the resource-exhaustion root cause and did not show new OOM/update churn after rollout.
- If slave preconnect helper reachability is required again for forward 47, investigate it separately from the `lw-cfwg-47` recovery and UOT resource fix.
- The repo working tree remains dirty with many related and unrelated edits/artifacts. Do not assume everything dirty belongs to this fix without reviewing the diff boundary.
- Temporary evidence files created during this investigation include:
  - `tmp_slave_resource_final_20260523_125205.out`
  - `tmp_slave_postroll_sanity_20260523_125420.out`
  - `tmp_sh36_readonly_20260523_125313.out`
  - `tmp_all_slaves_update_20260523_122151.out`

## Key commands for future re-check

```bash
# Slave fleet resource sanity, from WSL repo root
bash tmp_slave_resource_now_lf.sh

# sh36 forward-47 read-only status, from WSL repo root
bash tmp_sh36_readonly_now.sh

# Focused local verification, from Windows repo root
py -3 -m unittest client-agent.tests.test_leikwan_uot_runtime slave-agent.tests.test_slave_mptcp_cleanup slave-agent.tests.test_slave_installer_helper
py -3 -m py_compile client-agent\leikwan_uot.py slave-agent\slave_agent.py
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
```
