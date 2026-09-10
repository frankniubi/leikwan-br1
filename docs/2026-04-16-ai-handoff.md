# AI Handoff: Forward 23 / Forward 24

## Goal

Continue repairing:

- `forward 23` (`bond`, listen `11021`)
- `forward 24` (`mptcp`, listen `11022`)

Primary goal is data-plane recovery.
Throughput tuning is secondary, but `forward 24` is already in the "partially working but degraded" state.

## Critical note

- the latest local fix in `sidecar-agent/leikwan_mptcp_sidecar.py` is **not confirmed imported/deployed on the real sidecar host**
- this is important because the current best root-cause candidate for degraded `forward 24` is stale / incomplete MPTCP endpoint state on sidecar point `3`
- if a new AI continues this work, it should treat "deploy latest `leikwan_mptcp_sidecar.py` to sidecar point `3`" as one of the first actions

## Hosts

- panel: see `infoandcredentials.txt`
- client host: see `infoandcredentials.txt`
- sidecar host: see `infoandcredentials.txt`

Do not duplicate credentials here.

## Port constraint

- treat slave usable port range as `7000-9000` only
- when checking slave listener / backend / relay behavior, do not assume ports outside `7000-9000` are available for normal service exposure on the Guangzhou slave set
- if a future change appears to require slave-side service ports outside `7000-9000`, treat that as suspicious and verify before using it

## Current verified state

### Forward 24

- mode: `mptcp`
- listen port: `11022`
- sidecar overlay target: `10.203.103.115:14969`
- all Guangzhou slave nodes `4/5/6/7/8` are now on `slave-agent 2026.04.15.2`
- client localhost UoT endpoints are stable:
  - `lw-mptcp-24-0 -> 127.0.0.1:58149`
  - `lw-mptcp-24-1 -> 127.0.0.1:58150`
  - `lw-mptcp-24-2 -> 127.0.0.1:58152`
  - `lw-mptcp-24-3 -> 127.0.0.1:58148`
  - `lw-mptcp-24-4 -> 127.0.0.1:58151`
- reverse test currently works:
  - `iperf3 -c 127.0.0.1 -p 11022 -R`
  - observed receive throughput roughly `27-31 Mbps`
  - test can complete 10 seconds
- forward test still degrades:
  - `iperf3 -c 127.0.0.1 -p 11022 -t 10`
  - first burst can be large, then traffic stalls
  - often ends with reset / abnormal close
- traffic distribution is still wrong:
  - most RX bytes land on `lw-mptcp-24-0`
  - other legs contribute little or almost nothing

### Forward 23

- mode: `bond`
- listen port: `11021`
- panel truth is now fully WSS/TCP-only
- `buildClientConfig(23)` shows all endpoints `supports_quic_leg=false`
- client runtime now renders only `tcp://127.0.0.1:*` legs
- client bond runtime now uses `--join-timeout-ms 400`
- point `2` sidecar runtime now uses:
  - `/usr/local/bin/leikwan-bond server --listen 0.0.0.0:14961 --token-secret ...`
- client `/usr/local/bin/leikwan-bond` now matches sidecar build SHA:
  - `77754e74ca398192cc7587dc29015415768928588a356f42a6dd57573d59a516`
- `11021` local listener is up
- slave `readyz` probes succeed
- still broken:
  - client logs `timed out waiting for bond hello-ack`
  - then `no bond legs acknowledged the TCP transport`
  - `iperf3 -c 127.0.0.1 -p 11021 -t 10` still does not complete

## Confirmed fixes already done

### Shared / panel

- bond QUIC capability gating tightened:
  - TCP outer-transport nodes no longer count as QUIC-capable bond legs
- sidecar heartbeat management now emits runtime `mode`:
  - `bond` for bond-only points
  - `mptcp` for active MPTCP points

### Client

- `leikwan_uot.py` client mode fixed:
  - return traffic now uses the same bound UDP listen socket
  - prevents WG peer endpoint drifting to random localhost ports
- `install_forward_runtime.py` fixed:
  - WSS-only bond path now respects panel-provided join timeout

### Sidecar

- bond-only point now launches `leikwan-bond server`, not `leikwan-mptcp-relay server`
- sidecar UoT runtime was updated once on host for point `3`
- local code fix exists in `sidecar-agent/leikwan_mptcp_sidecar.py`:
  - stale point-local MPTCP endpoint cleanup
  - intended to remove stale endpoint IDs in the same point slot window and allow missing current endpoints to be added
- this last sidecar runtime fix was **not confirmed deployed / imported on the real sidecar host** because sidecar SSH access became unstable during that phase

### Slave auto-update

- root cause fixed:
  - `install/install-slave.sh` now supports `--check-update`
  - `install/install-slave.sh` now supports `--update`
  - `install/install-slave.sh` now supports `--config`
  - installer now writes back `installer_version`
- `artifacts/versions.json` bumped:
  - `slave_installer.version = 2026.04.16.1`
- verified result:
  - nodes `4/6/7/8` upgraded from `2026.04.07.1` to `2026.04.15.2`

## Current likely blockers

### Forward 24

Most likely next blocker:

- sidecar point `3` still has stale / incomplete MPTCP endpoint state in the kernel

Evidence:

- sidecar `ip mptcp endpoint show` previously missed:
  - `10.203.103.111 id 34 dev lw-sc-3-3 signal`
- stale unrelated `if*` signal endpoints were still present
- during reverse test, one main MPTCP path is clearly active, but multipath fan-out is still poor

Interpretation:

- data plane is no longer fully down
- remaining problem is likely incomplete sidecar endpoint convergence and therefore incomplete subflow advertisement / acceptance

### Forward 23

Most likely next blocker:

- slave WSS ingress to target-side bond handshake path is still not completing

Evidence:

- no QUIC legs remain
- point `2` runs the correct bond server
- client and sidecar `leikwan-bond` binaries are aligned
- client still never receives `hello-ack`
- client logs also show repeated `wstunnel ... failed to get a connection to the server from the pool: TimedOut` toward `161.248.136.183:14961`

Interpretation:

- the failure is no longer in panel truth, not in QUIC gating, and not in the obvious wrong-server-path issue
- likely remaining boundary is:
  - slave-side relay path to sidecar `14961`, or
  - how bond leg traffic is being proxied through the slave WSS path before it reaches the sidecar bond server

## Important local changes not yet fully deployed everywhere

- `sidecar-agent/leikwan_mptcp_sidecar.py`
  - stale endpoint cleanup fix
- this is the main "sidecar not yet imported" item
- if sidecar SSH becomes reachable again, deploy this first and re-test `forward 24`

## Recommended next actions

1. Deploy latest `sidecar-agent/leikwan_mptcp_sidecar.py` to sidecar point `3`.
2. Restart:
   - `leikwan-bond-sidecar-3-agent`
   - `leikwan-bond-sidecar-3`
3. Re-check on sidecar:
   - `ip mptcp endpoint show`
   - `wg show lw-sc-3-*`
   - confirm `10.203.103.111 id 34 dev lw-sc-3-3 signal` exists
   - confirm stale `if*` endpoints are removed if they belong to the same managed point slot range
4. Re-test `forward 24`:
   - `iperf3 -c 127.0.0.1 -p 11022 -R`
   - `iperf3 -c 127.0.0.1 -p 11022 -t 10`
   - `wg show lw-mptcp-24-* transfer`
   - `ss -M -tin`
5. If `forward 24` still stays single-leg, inspect subflow creation/advertisement on both client and sidecar during a live reverse run.
6. For `forward 23`, inspect the slave-side path during an active `11021` test:
   - whether each slave actually connects upstream to `161.248.136.183:14961`
   - whether sidecar point `2` ever sees incoming bond legs
   - whether the WSS path forwards raw bond frames correctly

## Useful commands

### Panel

Build current truth for `forward 23`:

```php
$cfg = $app->provisioning()->buildClientConfig(23);
```

Build current truth for `forward 24`:

```php
$cfg = $app->provisioning()->buildClientConfig(24);
```

### Client

Check listeners:

```bash
ss -tlnp | grep -E '11021|11022'
```

Check bond logs:

```bash
journalctl -u leikwan-client-23 --no-pager -n 120 --since '5 min ago'
```

Check MPTCP logs:

```bash
journalctl -u leikwan-client-24 --no-pager -n 120 --since '5 min ago'
```

Check WG transfers:

```bash
for i in 0 1 2 3 4; do
  echo "### $i"
  wg show lw-mptcp-24-$i transfer
done
```

### Sidecar

Point `2`:

```bash
systemctl status leikwan-bond-sidecar-2 --no-pager -n 40
journalctl -u leikwan-bond-sidecar-2 --no-pager -n 120 --since '10 min ago'
```

Point `3`:

```bash
systemctl status leikwan-bond-sidecar-3 --no-pager -n 40
journalctl -u leikwan-bond-sidecar-3 --no-pager -n 120 --since '10 min ago'
ip mptcp endpoint show
for i in 0 1 2 3 4; do
  echo "### $i"
  wg show lw-sc-3-$i
done
```

## Do not redo

- do not reopen the old QUIC gating bug for `forward 23`
- do not treat `127.0.0.1` in TCP-fallback WG peer config as a bug by itself
- do not assume slave auto-update is still broken; it is now verified working
