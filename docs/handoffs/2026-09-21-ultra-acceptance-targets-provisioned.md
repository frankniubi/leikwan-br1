# Ultra acceptance: test targets provisioned (2026-09-21)

## What was provisioned (additive only)

A private UDP echo target on each sidecar host, as a systemd unit
`lk-udp-echo-62001.service` running `/usr/local/lib/lk-udp-echo-62001.py`.

- Additive: `leikwan-external-uot.service` was never modified and stayed
  `active` on every host before and after.
- Bounded: `RuntimeMaxSec=5400`, `MemoryMax=256M`, `NoNewPrivileges=true`.
- The echo replies only to payloads prefixed `LKU1`, so unrelated traffic on
  the port is ignored and cannot be mistaken for a test reply.
- Removal is one command per host:
  `systemctl disable --now lk-udp-echo-62001.service`.

## Reachability matrix (measured)

| host | own loopback :62001 | reachable from `185` (live forward-170 sidecar) |
|---|---|---|
| 70.36.96.183 | OK | OK |
| 77.93.90.45 | OK | FAIL (timeout) |
| 103.246.219.39 | OK | OK |
| 157.228.138.23 | OK | OK |
| 208.122.17.50 | OK | OK |
| 185.220.238.143 | OK | (self) |

Five of six are usable as concurrent targets from the sidecar vantage point.
`77.93.90.45` answers locally but is firewalled from peer hosts, so it cannot
be used as a target; it can still be used as an originating platform.

## The blocker: no forward targets a live echo

`forward-170.json` on the Linux client is:

```
transport tcp+udp, uot enabled, delivery_mode=reliable_datagram_v1
sidecar   185.220.238.143:7443
target    185.220.238.143:10001
```

The client-side path is live and healthy (TCP connect succeeds on
`127.0.0.1:10001`, `NRestarts=0`, real traffic in the journal). But the
**target at 185.220.238.143:10001 does not echo**:

- UDP: 20 datagrams sent through the tunnel, **0 echoed back**.
- TCP: connection established, **0 bytes returned**.

So the existing forward cannot carry an acceptance run: the user's criterion is
"20Mbps TCP+UDP for 10 minutes with no interruption", which cannot be measured
against a target that returns nothing.

Two candidate ways forward, both needing an operator decision because both touch
panel state:

1. **Point a test forward at an echo target.** Requires creating (or editing)
   a forward whose `target` is one of the `62001` echoes, and a matching
   sidecar binding so HELLO admission accepts the new forward id. The earlier
   rounds used this shape: forward ids 198/199 on client 3 / service 30, with
   local listen ports 60151/60152.
2. **Make `185.220.238.143:10001` echo.** It already has a UDP listener on
   `10001` that the sidecar dials, but it does not reply. Changing it is a
   change to whatever currently owns that port on the live sidecar host, so it
   needs explicit approval.

## Still outstanding

- `uot-sidecars.txt` lists five hosts; `185.220.238.143` is the sixth and is
  not in that file. The operator supplied its password during this session and
  it is used only in-memory by the local scripts, never written to the repo.
- The Linux client host is agent-managed and will revert a hand-installed
  `ultra-client`; a candidate run needs a bounded
  `LEIKWAN_UPDATE_HOLD_UNTIL` plus a rollback timer.
- Three platforms are reachable and cross-compile targets all build, so the
  concurrent Windows + Linux + macOS run is ready to launch the moment a
  forward with a live target exists.
