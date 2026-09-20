# Ultra acceptance: host state & deployment constraints (2026-09-21)

Read-only survey of the three acceptance hosts in `test-env.txt`.
Credentials are not reproduced here. No host was modified.

## Current versions

| Host | Runtime version | Management | Ultra forwards |
|---|---|---|---|
| Linux 172.21.255.189 (Debian 12) | `2026.09.19.1.f18d01fb` (enterprise) | agent-managed | 158, 159, 170, 171, 180 active |
| macOS 100.103.253.21 (macOS 26.6.2) | `2026.09.20.6.52d81766` (.6 logging-fix) | headless app, pid 87951 | app-level |
| Windows 172.21.255.4 (OpenSSH 9.5) | not yet determined | headless + 3x ultra-client | unknown |

**Neither Linux nor macOS currently runs the merged tree.** Linux runs the
enterprise reliable-datagram line without this session's safety fixes
(business isolation, protocol-isolated outage collapse, cancellation-aware
rescue dial, strict end-ordering, sidecar-pool gaps, pause fix).

## The ready-made test forward

Forward 170 on Linux is the acceptance forward:

```
listen   0.0.0.0:10001        (TCP + UDP both confirmed listening)
target   185.220.238.143:10001
transport tcp+udp
uot      enabled, delivery_mode=reliable_datagram_v1
sidecar  185.220.238.143:7443
```

So the required route is already provisioned:
`client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`.

## Constraint 1: the agent will revert a manually installed binary

`/var/lib/leikwan-client-agent/agent_update_state.json` shows the agent owns
`/usr/local/bin/ultra-client` through the `install_forward_runtime` artifact
channel (installed_version 2026.09.19.1). Any binary copied in by hand is
replaced on the next pull, which forces the supervised forward services to
restart.

This is exactly what aborted the previous upstream long run: the running inode
stopped matching `/usr/local/bin/ultra-client`, every service restart-looped
about every 30 seconds, and installing at the canonical path let the updater
restore the published hash.

**The supported escape hatch exists on the host:** `LEIKWAN_UPDATE_HOLD_UNTIL`
is implemented inside `/usr/local/bin/leikwan-client-agent.py`. The handoff
records that "a bounded root-local hold plus an independent rollback timer keeps
the canonical canary binary installed without disabling runtime identity
checks".

So a candidate run needs, in order:
1. install the candidate at `/usr/local/bin/ultra-client`;
2. set a bounded `LEIKWAN_UPDATE_HOLD_UNTIL` plus an independent rollback timer;
3. re-read the installed hash before and after the run;
4. never disable runtime identity checks and never invent an artifact identity.

This mechanism has not been exercised in this session.

## Constraint 2: the UDP target is co-located with a shared sidecar

Forward 170's target is `185.220.238.143:10001`, and its sidecar is
`185.220.238.143:7443`. That host is one of the six production external-UoT
sidecars, and 185.220.238.143 is also the sidecar that was missing binding 200
before the fleet repair.

Running the acceptance test therefore requires a listening UDP endpoint on that
host. The handoff is explicit that fault injection must only touch a test
forward or an owned target, and **must never stop a shared production sidecar**.
Any target work on 185.220.238.143 must be additive: a private test listener on
a spare port plus a matching forward, with cleanup, and no change to
`leikwan-external-uot.service`.

## Constraint 3: no TUN mode

`test-env.txt` requires that tests are not run in TUN mode unless explicitly
ordered, and if TUN is used, only for routes bypassing Mainland China.

## Verified capabilities for acceptance

- ultra-client cross-compiles cleanly for linux/amd64, darwin/arm64 and
  windows/amd64 from this workstation.
- All three hosts authenticate over SSH; paramiko 4.0.0 is available.
- A prior harness exists at `tmp/hk-sg-client3-20260920/ops.py` and the
  read-only route checker at `tools/uot-acceptance/check_uot_route.py`.

## What is still needed from the operator

1. Permission to install the merged candidate on the Linux acceptance host
   under a bounded update hold with a rollback timer.
2. Confirmation that an additive test listener may be placed on the
   185.220.238.143 target host (a spare UDP port, no sidecar change), or an
   alternative owned target and sidecar binding.
3. Whether macOS and Windows should be brought to the same revision before the
   first acceptance run, or Linux acceptance first.
