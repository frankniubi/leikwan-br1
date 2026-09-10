# CFWG Forward 47 Latency Watchdog Live Sync

Date: 2026-05-20  
Branch: `codex/cfwg47-inline-fix-20260520`  
Latest commit at handoff time: `33f8846 fix(client): harden cfwg watchdog wg restart`

## Outcome

Forward `47` / interface `lw-cfwg-47` on `sh36` was left reachable and low-latency to the WG peer `10.204.53.42`.

The CFWG latency watchdog is now installed on the panel artifact tree and directly on sh36. It monitors ICMP RTT through `lw-cfwg-47` and restarts the runtime when latency regresses from the expected ~31 ms baseline.

## What was implemented

### 1. CFWG latency watchdog hardening

Files changed:

- `client-agent/cfwg_latency_watchdog.py`
- `artifacts/cfwg-latency-watchdog.py`
- `artifacts/versions.json`
- `client-agent/tests/test_cfwg_latency_watchdog.py`

Behavior now covered:

- Probes `10.204.53.42` through the rendered CFWG interface, e.g.:
  - `ping -I lw-cfwg-47 -c 1 -W 1 10.204.53.42`
- Uses configured forward baseline:
  - `baseline_rtt_ms: {"47": 31}`
- Detects sustained regression using:
  - absolute threshold (`critical_rtt_ms`)
  - delta threshold (`critical_rtt_delta_ms`)
  - relative degradation (`relative_degradation_factor` and `min_relative_degradation_ms`)
  - packet loss threshold (`critical_loss_pct`)
- Restarts the CFWG runtime path using configured `restart_actions`:
  - `wstunnel` action -> `systemctl restart leikwan-client-47.service`
  - `wireguard` action -> `systemctl restart wg-quick@lw-cfwg-47.service`
- New hardening added in this pass:
  - if `systemctl restart wg-quick@lw-cfwg-47.service` fails, fallback to:
    - `wg-quick down lw-cfwg-47`
    - `wg-quick up /etc/wireguard/lw-cfwg-47.conf`
    - `systemctl reset-failed wg-quick@lw-cfwg-47.service`
- New compatibility hardening:
  - loads `best_rtt_ms` from legacy watchdog state files that only had `samples` and no explicit `best_rtt_ms` field.

### 2. Panel MySQL tmpdir issue checked/fixed

Reported panel error:

```text
SQLSTATE[HY000]: General error: 1 Can't create/write to file '/tmp/MYQhlqjY' (OS errno 13 - Permission denied)
```

Live panel checks performed on `10.0.10.196`:

- MySQL process was active.
- `/tmp` was writable/sticky:
  - `1777 root root /tmp`
- MySQL runtime `tmpdir` was verified as:
  - `/www/server/data/tmp`
- MySQL tmpdir permissions were verified/enforced as:
  - `1777 mysql mysql /www/server/data/tmp`
- PHP/PDO temporary table creation succeeded:
  - `TEMP_TABLE_OK`

This confirms the current panel-side MySQL temporary-file path is usable and no longer reproduces the permission error.

### 3. Panel artifact sync

Synced these local files to:

```text
root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/
```

Files synced:

```text
client-agent/cfwg_latency_watchdog.py
artifacts/cfwg-latency-watchdog.py
artifacts/versions.json
client-agent/tests/test_cfwg_latency_watchdog.py
master/tests/agent_udp_uot_sidepath_management_test.php
```

Panel verification included:

```text
sha256sum client-agent/cfwg_latency_watchdog.py artifacts/cfwg-latency-watchdog.py artifacts/versions.json client-agent/tests/test_cfwg_latency_watchdog.py
python3 -m py_compile client-agent/cfwg_latency_watchdog.py
php -l master/src/Controllers/ClientAgentController.php
```

Important synced hash:

```text
5979698d0a29e9e47c0c3215a8b40782327f74f3c6158c8bc7b9f2a862f496bd  client-agent/cfwg_latency_watchdog.py
5979698d0a29e9e47c0c3215a8b40782327f74f3c6158c8bc7b9f2a862f496bd  artifacts/cfwg-latency-watchdog.py
```

### 4. sh36 live sync and verification

Direct sh36 target used:

```text
root@114.80.33.50
```

Installed file:

```text
/usr/local/bin/cfwg_latency_watchdog.py
```

Backup created before overwriting:

```text
/root/codex-backups/cfwg-watchdog-sh36-20260520192025/cfwg_latency_watchdog.py
/root/codex-backups/cfwg-watchdog-sh36-20260520192025/cfwg-latency-watchdog-state.json
```

Installed watchdog hash on sh36:

```text
5979698d0a29e9e47c0c3215a8b40782327f74f3c6158c8bc7b9f2a862f496bd  /usr/local/bin/cfwg_latency_watchdog.py
```

Services after restart:

```text
leikwan-client-agent.service active
leikwan-client-47.service active
```

Final sh36 ping verification:

```text
ping -I lw-cfwg-47 -c 5 -W 1 10.204.53.42
5 packets transmitted, 5 received, 0% packet loss
rtt min/avg/max/mdev = 33.066/35.218/37.075/1.276 ms
```

Watchdog state after sync showed a learned best RTT:

```json
{
  "47": {
    "best_rtt_ms": 32.9,
    "bad_windows": 0,
    "last_event": "restart_client_service"
  }
}
```

Recent log proof that watchdog is operating:

```text
cfwg latency watchdog forward=47 recovered interface=lw-cfwg-47 peer=10.204.53.42 avg=33.4 p95=33.6 loss=0% baseline=31.0
```

## Tests run locally

```text
python -m unittest client-agent.tests.test_cfwg_latency_watchdog client-agent.tests.test_self_leg_cf_wg_runtime client-agent.tests.test_client_agent_installer_helper client-agent.tests.test_artifact_versions_consistency
```

Result:

```text
Ran 50 tests in ~0.25s
OK
```

Targeted PHP test:

```text
php master/tests/agent_udp_uot_sidepath_management_test.php
```

Result:

```text
ok
```

Note: PHP CLI still prints an unrelated local timezone warning for `UTC+8`.

## Current operational state

- Panel artifact tree has the updated watchdog and manifest.
- sh36 has the updated watchdog installed directly.
- sh36 agent and forward 47 services are active.
- `lw-cfwg-47 -> 10.204.53.42` is reachable with ~33-37 ms RTT in the final verification.
- Client id `3` still has `disable_auto_update=true`; this pass did not unpin the older known-good runtime.
- A panel update request token was set to trigger agent-side management refresh:
  - `codex-cfwg-watchdog-fallback-20260520112121`
- sh36 marked that update token as successful because the local agent version already matched.

## Suggested next actions

1. Keep monitoring sh36 for at least one longer interval and confirm watchdog logs stay in recovered state unless RTT truly regresses.
2. Do not unpin full client auto-update until the CFWG runtime contract is fully accepted as safe for forward 47.
3. If RTT regresses again, inspect:
   - `/var/lib/leikwan-client-agent/cfwg-latency-watchdog-state.json`
   - `journalctl -u leikwan-client-agent.service | grep -i 'cfwg latency watchdog'`
   - `wg show lw-cfwg-47`
   - `ps aux | grep 'wstunnel client' | grep 40047`
4. If `wg-quick@lw-cfwg-47.service` fails, the new fallback should bring the interface back with `wg-quick down/up`; verify this in logs before manual intervention.

## GitHub sync

This handoff is committed and pushed on:

```text
codex/cfwg47-inline-fix-20260520
```
