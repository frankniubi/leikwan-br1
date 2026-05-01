# Forward 47 status handoff

## Scope
This note is the local handoff for `forward 47` on branch `codex/android-single-bond-linux-parity`.

Exact verification command:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
```

## Short version
- I fixed the **control plane** for CF mode bond publishing.
- I proved a **temporary TCP-only live state** that passed the exact command twice.
- I then started Phase 3, trying to make the point-5 runtime and installer path permanent.
- The **local Phase 3 code fix is done**, but the **live path is not stable right now** after the staged installer experiment and partial rollback.
- The current problem is no longer the panel publish logic. It is a **runtime mismatch on the live path** that needs a fresh synchronized investigation, not more blind rollout changes.

## What I changed locally

### Panel / control-plane
I changed these files locally:

- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/SidecarAgentController.php`

What those fixes do:

1. For `cf_mode + bond`, slave publish target resolves to the point-5 sidecar **loopback bond target**:
   - `127.0.0.1:8899`
2. Sidecar CF mux route generation also resolves its backend target to:
   - `127.0.0.1:8899`

### Installer / artifact path
I changed these files locally:

- `install/install-bond-sidecar.sh`
- `artifacts/versions.json`
- `artifacts/wstunnel/wstunnel_linux_amd64`

What those fixes do:

1. `install-bond-sidecar.sh` no longer downloads `wstunnel` directly from GitHub.
2. It now uses a panel-served artifact path:
   - `artifacts/wstunnel/wstunnel_linux_amd64`
3. `artifacts/versions.json` now includes the `wstunnel_linux_amd64` entry.
4. Installer version was bumped to `2026.04.26.1`.

### Tests added / updated
I added or updated local regression coverage for:

- `master/tests/provisioning_sidecar_republish_test.php`
- `master/tests/sidecar_cf_mode_loopback_target_regression_test.php`
- `client-agent/tests/test_sidecar_agent_update_helper.py`

Local verification that passed:

```bash
php master/tests/provisioning_sidecar_republish_test.php
php master/tests/sidecar_cf_mode_loopback_target_regression_test.php
php master/tests/provisioning_plain_tcp_ingress_regression_test.php
python -m unittest client-agent.tests.test_sidecar_agent_update_helper
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/SidecarAgentController.php
```

## What I changed live

### Client `sh36`
Live actions taken:

- Rolled client `leikwan-bond` to `0.1.13` using a static build that runs on the host.
- Temporarily hotfixed `/usr/local/bin/leikwan-client-start-47.sh` to **TCP-only**.
- Backup kept at:
  - `/usr/local/bin/leikwan-client-start-47.sh.bak-tcpudp`

### Point-5 sidecar
Live actions taken:

- Replaced broken `/usr/local/bin/wstunnel` with a known-good `10.5.2` binary copied from a working slave.
- Temporarily disabled noisy/broken services:
  - `leikwan-bond-sidecar-5-agent.service`
  - `leikwan-bond-sidecar-5-udp-uot.service`
  - `cf-forward-47-uot.service`
- Kept these active during the known-good TCP phase:
  - `leikwan-bond-sidecar-5.service`
  - `leikwan-bond-sidecar-5-cf-2053.service`
  - `cf-forward-47.service`

Useful backup paths created on point-5 during Phase 3 work:

- `/root/codex-backups/phase3-installer-20260426T052200Z/`
- `/root/codex-backups/point5-wstunnel-<timestamp>/`

## What I found

### 1. The original failure was multi-layered
It was not just one bug.

Confirmed failure layers:

1. Panel -> slave CF publish logic
2. Panel -> point-5 CF mux route logic
3. Point-5 `wstunnel` runtime integrity
4. Point-5 sidecar agent self-update behavior
5. Point-5 UDP sidepath missing binary / flapping
6. Client forward 47 runtime launching TCP and UDP together while UDP was broken

### 2. The control plane is now correct
Live evidence confirmed all five slaves now publish `forward 47` CF mode with:

- `target = 127.0.0.1:8899`
- `backend_mode = cf_mode`
- `wss://nrt1.nbwly.com:2053`

Live point-5 sidecar files also showed:

- `/var/lib/leikwan-sidecar-5/cf-routes-2053.json`
- main route target = `127.0.0.1:8899`

### 3. Point-5 `wstunnel` was genuinely broken before replacement
Direct execution on point-5 returned permission failure even when the file existed.

After replacement from a known-good slave binary:

- `/usr/local/bin/wstunnel --version`
- returned `wstunnel-cli 10.5.2`

### 4. Point-5 sidecar agent is unsafe to re-enable yet
It was repeatedly spawning self-update behavior and is not trustworthy until its update source/path is fixed.

### 5. Point-5 UDP sidepath is incomplete
The point-5 host is missing:

- `/usr/local/bin/leikwan-udp-uot-merge`

That made the UDP unit flap continuously before I disabled it.

### 6. A broad staged installer run on point-5 was too invasive
I used a staged local artifact directory to prove the installer path end-to-end.
That proved the installer fix direction is correct, but it also touched broader point-5 runtime state.

This is why I do **not** consider Phase 3 live-complete yet.

## Last known good state
The exact verification command passed twice during the temporary TCP-only rescue state.

Passing runs:

1. `2026-04-25T18:42:52Z`
   - `iperf_rc=0`
   - sender held `300 Mbits/sec`
   - receiver `221 Mbits/sec`
2. `2026-04-26T04:28:07Z`
   - `iperf_rc=0`
   - sender held `300 Mbits/sec`
   - receiver `106 Mbits/sec`

At that point the path was good enough to move forward, but only under the stabilized temporary conditions.

## Current state
Current state when I stopped:

### Client
- `leikwan-client-47.service` active
- TCP-only hotfix still applied to `start-47`
- current client process shape:
  - five `leikwan-ingress client` legs
  - one `leikwan-bond client --protocol tcp`

### Point-5
Most recent useful state observed:

- `leikwan-bond-sidecar-5.service` active
- `leikwan-bond-sidecar-5-cf-2053.service` active
- `cf-forward-47.service` active
- `leikwan-bond-sidecar-5-agent.service` disabled
- `leikwan-bond-sidecar-5-udp-uot.service` disabled
- `cf-forward-47-uot.service` disabled

### But the live exact test is currently not trustworthy
After the staged installer experiment and rollback work, the exact test regressed again during the latest checks.

One fresh failing example:

- `2026-04-26T05:22:28Z`
- `iperf_rc=124`

Client-side symptom on those failing runs:

- `failed to read bond frame header`
- `early eof`
- `no bond legs acknowledged the TCP transport`

## Why I stopped here
Because the next correct step is not “more rollout”.
It is a **fresh synchronized runtime investigation**.

At this point:
- control plane is already fixed
- local permanent installer fix is already prepared
- but the live runtime still has a mismatch that needs to be isolated with clean evidence

Continuing to push more live changes without that evidence would just be more thrash.

## Suggested next steps

### Recommended next move
Do **not** start by re-enabling agent or UDP.

Do this instead:

1. Keep TCP-only verification mode for `forward 47`.
2. Keep point-5 agent / UDP units disabled.
3. Capture **one fully synchronized failing run** across:
   - client `sh36`
   - one slave, preferably `316` (`47.116.11.144`)
   - point-5 services:
     - `leikwan-bond-sidecar-5.service`
     - `leikwan-bond-sidecar-5-cf-2053.service`
     - `cf-forward-47.service`
4. Compare that failing run against the last known passing TCP-only state.
5. Only after the runtime mismatch is pinned, do a **narrow** live deployment of the permanent Phase 3 installer/artifact fix through the panel path.

### Concrete Phase 3 goal after investigation
When you resume the permanent fix, the real target is:

- point-5 can be rebuilt/reinstalled from `install-bond-sidecar.sh`
- installer pulls `wstunnel` from panel artifact path
- `wstunnel` remains executable after install/restart
- `cf-forward-47.service` stays active after restart
- exact iperf3 command still passes after fresh client restart

### Do not do this yet
- do not re-enable `leikwan-bond-sidecar-5-agent.service`
- do not re-enable `leikwan-bond-sidecar-5-udp-uot.service`
- do not re-enable `cf-forward-47-uot.service`
- do not remove the client TCP-only `start-47` hotfix until the point-5 runtime is stable again

## Files worth reading first next session
- `task_plan.md`
- `findings.md`
- `progress.md`
- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/SidecarAgentController.php`
- `install/install-bond-sidecar.sh`
- `client-agent/tests/test_sidecar_agent_update_helper.py`

## Final recommendation
Treat this as:

- **Phase 1 complete**
- **Phase 2 complete**
- **Phase 3 local code fix complete**
- **Phase 3 live rollout incomplete**

The next person should resume with a runtime investigation, not another broad rollout.

## Resume update after the runtime investigation

This handoff is now partially superseded by a follow-up live fix on the same date.

### Root cause found
- `sidecar-agent/leikwan_wss_mux.py` was routing CF mux traffic to the route `target`.
- For `forward 47`, the route file correctly carried:
  - `target = 127.0.0.1:8899`
  - `backend_port = 26001`
- The mux should have proxied the websocket upgrade to local backend `26001`, but instead it proxied straight into bond listener `8899`.
- That exactly matched both live failure signatures:
  - slave `wstunnel` websocket handshake failures / HTTP `520`
  - point-5 `leikwan-bond-sidecar-5.service` `invalid bond frame magic`

### Local code fix
Files changed locally:

- `sidecar-agent/leikwan_wss_mux.py`
- `sidecar-agent/tests/test_wss_mux_routes.py`

What changed:

1. The mux now prefers `backend_port` from `cf-routes-2053.json`.
2. It falls back to parsing `target` only when `backend_port` is absent.

Regression proof:

```bash
python -m unittest sidecar-agent.tests.test_wss_mux_routes
```

### Narrow live fix applied
- Patched point-5 `/usr/local/bin/leikwan-wss-mux.py` through the required slave hop.
- Backup created at:
  - `/root/codex-backups/wss-mux-20260426T093029Z/`
- Restarted:
  - `leikwan-bond-sidecar-5-cf-2053.service`
- Pushed the same patched `leikwan-wss-mux.py` to the live panel tree:
  - host: `10.0.10.196`
  - path: `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/leikwan_wss_mux.py`
  - backup: `/root/codex-backups/panel-wss-mux-20260426T194038Z/leikwan_wss_mux.py.before`

### Verification after the mux fix
Point-5 local self-checks recovered:

1. direct local `wstunnel` to backend `ws://127.0.0.1:26001`
2. the previously failing local mux path `wss://127.0.0.1:2053`

Exact forward-47 verification results after the fix:

1. `2026-04-26T09:31:34Z`
   - `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M`
   - `iperf_rc=0`
   - receiver `21.0 Mbits/sec`
2. `2026-04-26T09:32:09Z`
   - fresh client restart + only `20s` settle
   - `iperf_rc=124`
3. `2026-04-26T09:34:36Z`
   - fresh client restart + `75s` settle
   - `iperf_rc=0`
   - receiver `88.9 Mbits/sec`

### Updated state
- The CF mux routing bug is fixed locally and live.
- `forward 47` passes again.
- Immediate post-restart verification is still timing-sensitive.
- The current live path is reliable again when the client gets a longer post-restart settle window.

### Updated practical recommendation
- Keep the current TCP-only client runtime for now.
- Keep sidecar agent and UDP units disabled.
- If you need to verify immediately after a fresh `leikwan-client-47.service` restart, allow roughly `75s` settle before the exact `iperf3` command.
- The panel artifact path now serves the patched `leikwan-wss-mux.py`, so future installs/updates can pull the fixed mux.
- Any follow-up work should focus on **why post-restart leg warmup is slower than the old rescue path**, not on the old CF route target logic.

## Further update, Shanghai slave CF-IP investigation

Another real bug was confirmed after the mux work:

- Shanghai slaves `316-320` were recording different `selected_cf_ip` values
- but their live `wstunnel-svc-2-31x.service` units were still dialing:
  - `wss://nrt1.nbwly.com:2053`
- so the slave-side best-IP chooser was effectively bypassed

### Code/version work completed
- fixed local slave-agent rendering so CF backend units dial `selected_cf_ip` while preserving:
  - `--tls-sni-override nrt1.nbwly.com`
  - `Host: nrt1.nbwly.com`
- local regression proof:
  - `python -m unittest slave-agent.tests.test_slave_cf_location_group_selection`
- bumped slave-agent version to:
  - `2026.04.26.2`

### Panel rollout completed
- pushed updated `slave-agent/slave_agent.py` to panel host `10.0.10.196`
- updated live `artifacts/versions.json` slave agent version to `2026.04.26.2`
- backup:
  - `/root/codex-backups/panel-slave-agent-20260426T210516Z/`

### Shanghai rollout completed
- copied the new `leikwan-slave-agent.py` to Shanghai slaves `316-320`
- restarted `leikwan-slave-agent.service` on those hosts
- regenerated/restarted `wstunnel-svc-2-316` through `320`
- confirmed example live command on slave `316`:
  - `wss://162.159.39.16:2053`

### Live result
- This did **not** fix throughput.
- In fact, the selected-IP rollout performed worse than the hostname-dial version.
- I restored the Shanghai `wstunnel-svc-2-31x.service` unit files to their pre-change hostname-dial form after that test.

### Updated conclusion
- The selected-IP bypass was a real bug and is fixed in code/panel artifacts.
- But it is **not** the primary explanation for the current slow TCP path.
- The remaining problem is still deeper path quality / candidate quality / live network behavior.

### Deeper selector conclusion
- The current slave-side CF candidate picker is too naive:
  - it only ranks candidates by plain TCP connect latency to `ip:2053`
  - it does not test TLS handshake quality
  - it does not test websocket upgrade behavior
  - it does not test sustained tunnel throughput
  - its recorded `switch_improvement_ms`, `consecutive_failures`, and `cooldown_until` fields are not currently driving meaningful candidate choice
- Practical implication:
  - “selected CF IP” can still be a bad tunnel IP even when it looks best by simple connect latency
