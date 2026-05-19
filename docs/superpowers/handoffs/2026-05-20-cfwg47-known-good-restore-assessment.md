# CFWG Forward 47 Known-Good Local Restore / Assessment Handoff

Date: 2026-05-20
Repo: `C:\Users\frankniubi\Downloads\leikwan-br1`
Primary live client inspected: `sh36` / `114.80.33.50`
Forward: `47`
Interface: `lw-cfwg-47`
Peer target: `10.204.53.42`

## Executive summary

Forward `47` CFWG was restored to a working live state by returning the sh36 client runtime to the known-good local build from around `2026-05-19 00:59` / nighttime `2026-05-18` lineage.

After restoration, `lw-cfwg-47` successfully handshook with the sidecar WireGuard peer and `10.204.53.42` became reachable again from the client-side WireGuard interface.

The current system should be treated as **temporarily stabilized, not fully remediated**. I pinned auto-update off for the affected client/sidecar path so the panel/current artifact does not overwrite the working local runtime again before the professional inspection group compares and fixes the panel-published contract.

## User-provided diagnosis confirmed

The user stated that the working build was the local version from around:

- `2026-05-18` nighttime, around `23:00`, or
- `2026-05-19` around `01:00`

This matched the live backup:

```text
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901
```

That backup contained the known-good local client-agent/runtime pair:

```text
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/leikwan-client-agent.py
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py
```

## Pre-fix failure evidence

Before restoration, the client showed:

```text
interface: lw-cfwg-47
  address: 10.204.53.41/32
  peer endpoint: 127.0.0.1:40047
  allowed ips: 10.204.53.42/32
  transfer: 0 B received, sent increasing
```

Ping failed:

```text
ping -I lw-cfwg-47 10.204.53.42
3 packets transmitted, 0 received, 100% packet loss
```

The local wstunnel process existed:

```text
/usr/local/bin/wstunnel client \
  -L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0 \
  --tls-sni-override nrt1.nbwly.com \
  --http-headers Host: nrt1.nbwly.com \
  --http-upgrade-path-prefix 07289d1f5ed25399.cfwg \
  wss://162.159.39.5:2053
```

But the WireGuard peer was not receiving data. During the failing state, wstunnel logs showed repeated handshake/proxy errors such as:

```text
failed to do websocket handshake with the server wss://162.159.39.5:2053
Invalid status code: 400 / 520
```

This indicated that the CFWG path was alive locally but the live client/panel/sidecar-published route contract was mismatched or stale.

## Actions performed

### 1. Restored the known-good local runtime on sh36

Restored/confirmed the local runtime from:

```text
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901
```

Installed live files after restoration:

```text
/usr/local/bin/leikwan-client-agent.py
/usr/local/bin/install_forward_runtime.py
```

Live hashes after restoration:

```text
3abb7ecd4c572d3bcc18915b3ba0bdb8cf598ffcf3baf36b10288dc27c9cbf64  /usr/local/bin/leikwan-client-agent.py
2e4c0c883e12901a56c85c9cca38941a4492703837d6b66a8f309ab2a9715548  /usr/local/bin/install_forward_runtime.py
```

### 2. Forced forward 47/59 to re-render under the restored runtime

The applied-state signatures were cleared/neutralized for the restored runtime so the client would not incorrectly treat the old broken runtime as a no-op.

Affected local state file:

```text
/var/lib/leikwan-client-agent/applied.json
```

The client agent was restarted:

```text
systemctl restart leikwan-client-agent.service
```

### 3. Pinned auto-update off to prevent immediate overwrite

Panel-side `disable_auto_update` was set for:

- client `id=3`
- sidecar point `id=5`

Reason: the current panel artifacts advertise newer/current versions, but the user specifically reported that the local nighttime build worked while the current panel-driven version did not. Pinning prevents the panel updater from overwriting the working runtime before the contract regression is fixed.

Important: this is a stabilization guard, not a permanent fix.

### 4. Restarted forward 47 runtime and verified CFWG reachability

Services verified active:

```text
leikwan-client-agent.service: active
leikwan-client-47.service: active
```

Runtime process after restoration:

```text
/usr/local/bin/wstunnel client \
  -L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0 \
  --tls-sni-override nrt1.nbwly.com \
  --http-headers Host: nrt1.nbwly.com \
  --http-upgrade-path-prefix 07289d1f5ed25399.cfwg \
  wss://162.159.39.5:2053
```

## Post-fix verification evidence

`wg show lw-cfwg-47` after restoration:

```text
interface: lw-cfwg-47
  public key: 5e4gewM1uOYvHWsArD7bV9/Prar2TyaTQ9Qqe/Kmezo=
  listening port: 43003

peer: bBbi/7CsWMWrX/TrM/mRwTWceO3UoOIAL2S1BXLXCw4=
  endpoint: 127.0.0.1:40047
  allowed ips: 10.204.53.42/32
  latest handshake: present
  transfer: received + sent increasing
  persistent keepalive: every 25 seconds
```

Ping verification:

```text
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
3 packets transmitted, 3 received, 0% packet loss
rtt avg approximately 50-52 ms
```

A later re-check still showed the path alive:

```text
10.204.53.42 via lw-cfwg-47: reachable
packet loss: 0%
rtt avg: ~50.7 ms
```

## Backups created / relevant backup paths

Known-good source backup:

```text
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901
```

Backups created during this stabilization pass:

```text
/root/codex-backups/cfwg47-before-known-good-restore-20260519-110349
/root/codex-backups/cfwg47-slash-test-20260519-111301
/root/codex-backups/panel-sidecar5-pin-20260519-231939
```

The `cfwg47-slash-test` backup was created before a short test of adding a leading slash to the wstunnel path prefix. That experiment did not fix the failure and the runtime was returned to the non-leading-slash form used by the known-good local version.

## Current risk / important caveat

The live path works now, but the panel/current artifact state is not yet proven safe.

Do **not** blindly re-enable client or sidecar auto-update yet. The next panel push may overwrite the working local runtime with the current broken/current-contract version and regress `lw-cfwg-47` again.

The likely class of root cause is a mismatch among:

1. panel-published CFWG contract,
2. client-rendered CFWG WireGuard peer/runtime,
3. sidecar-published CFWG route/backend runtime,
4. auto-update version selection.

The current live stabilization restored the known-good client-side local runtime and prevented immediate panel overwrite. It did not fully reconcile the newer panel/client/sidecar code path.

## Suggested next work for inspection group

### Priority 1: Preserve current working runtime before touching anything

On sh36, collect and archive:

```bash
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
cp -a /usr/local/bin/leikwan-client-agent.py /root/<new-audit-backup>/
cp -a /usr/local/bin/install_forward_runtime.py /root/<new-audit-backup>/
cp -a /usr/local/bin/leikwan-client-start-47.sh /root/<new-audit-backup>/
cp -a /etc/leikwan/mptcp-forward-47.json /root/<new-audit-backup>/
cp -a /var/lib/leikwan-client-agent/applied.json /root/<new-audit-backup>/
```

Then verify baseline:

```bash
systemctl is-active leikwan-client-agent.service leikwan-client-47.service
wg show lw-cfwg-47
ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42
```

### Priority 2: Diff known-good local runtime against current panel artifacts

Compare:

```text
Known-good client agent:
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/leikwan-client-agent.py

Known-good install runtime:
/root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py

Current panel artifacts:
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/client_agent.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/sidecar_agent.py
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php
/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php
```

Focus the diff on these semantics:

- `self_leg_cf_transport = cf_wg`
- CFWG route path: `<path_prefix>.cfwg`
- WireGuard sidecar peer public key selected for `lw-cfwg-47`
- sidecar target port for CFWG route, expected sidecar WG listen port `14969`
- client local UDP wstunnel target `127.0.0.1:14969`
- selected CF dial IP and SNI host
- no-op apply/signature behavior after runtime render
- agent auto-update request token behavior

### Priority 3: Reproduce the broken current artifact in a controlled clone, not on the working live path

Before unpinning live updates, use a clone/test node or a copied runtime directory to verify:

```bash
python3 -m py_compile client_agent.py install_forward_runtime.py sidecar_agent.py
```

Then render forward `47` from the panel payload and inspect generated files before applying:

```text
/etc/leikwan/mptcp-forward-47.json
/usr/local/bin/leikwan-client-start-47.sh
/etc/systemd/system/leikwan-client-47.service
```

Compare rendered output to the known-good live render.

### Priority 4: Validate sidecar route state

The inspection group should confirm the sidecar point `id=5` runtime has a valid CFWG route for forward `47`:

Expected logical route:

```text
path_prefix: 07289d1f5ed25399.cfwg
target: 127.0.0.1:14969
transport_mode: udp
```

Expected behavior:

- public CF/TLS/WebSocket path accepts the CFWG route,
- backend forwards to the sidecar WireGuard listener,
- client-side WG peer receives replies from `10.204.53.42`,
- `wg show lw-cfwg-47` receive counter increases.

### Priority 5: Only after fixing the artifact contract, unpin updates carefully

After the panel/current artifact renders the same working CFWG contract, re-enable updates in this order:

1. sidecar point `id=5`,
2. client `id=3`,
3. wait one heartbeat/pull interval,
4. verify hashes changed only to the inspected build,
5. verify `ping -I lw-cfwg-47 10.204.53.42` still succeeds.

Rollback immediately if receive counter drops back to zero or ping fails.

## Do not do these things during assessment

- Do not switch topology to direct client-to-sidecar. This environment must preserve `client -> slave -> sidecar` where applicable.
- Do not delete the known-good backup under `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901`.
- Do not re-enable auto-update until the current panel artifact has been proven to render the same working CFWG contract.
- Do not treat service `active` alone as proof; the decisive proof is WireGuard receive traffic and successful reachability to `10.204.53.42`.

## Minimal success criteria for final remediation

The issue should be considered truly fixed only when all are true:

```text
1. Current panel artifacts render forward 47 CFWG correctly.
2. Auto-update can be enabled without overwriting the path into a broken state.
3. /usr/local/bin/leikwan-client-agent.py and install_forward_runtime.py match inspected panel artifact hashes.
4. wg show lw-cfwg-47 shows latest handshake and increasing received bytes.
5. ping -I lw-cfwg-47 10.204.53.42 returns 0% loss.
6. The state remains stable across at least two client config pulls and one sidecar heartbeat cycle.
```

## Credential note

Credentials are intentionally not copied into this handoff. For authorized assessment, use the existing local environment files in this project:

```text
leikwan-v3.txt
cantons.txt
shanghais.txt
```

Keep those files local-only and do not commit them.
