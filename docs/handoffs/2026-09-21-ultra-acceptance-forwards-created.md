# Ultra acceptance: temporary forward rules created and bound (2026-09-21)

## What was created

Eight temporary Ultra forwards on **service 30** ("Ultra Native All Regions
Test", client 3), with **TCP and UDP separated** so each protocol is measured
on its own:

| id | protocol | listen | combination | sidecar | target |
|---|---|---|---|---|---|
| 201 | udp | 60501 | 51 | 70.36.96.183 | 70.36.96.183:62101 |
| 202 | tcp | 60502 | 51 | 70.36.96.183 | 70.36.96.183:62102 |
| 203 | udp | 60507 | 49 | 157.228.138.23 | 157.228.138.23:62101 |
| 204 | tcp | 60508 | 49 | 157.228.138.23 | 157.228.138.23:62102 |
| 205 | udp | 60509 | 54 | 185.220.238.143 | 185.220.238.143:62101 |
| 206 | tcp | 60510 | 54 | 185.220.238.143 | 185.220.238.143:62102 |
| 207 | udp | 60511 | 55 | 208.122.17.50 | 208.122.17.50:62101 |
| 208 | tcp | 60512 | 55 | 208.122.17.50 | 208.122.17.50:62102 |

Created disabled, then enabled with `setForwardEnabled`. Combinations 47 and 48
were refused with `forward_combination_not_allowed`, so Korea and Singapore are
not reachable for this service.

## The binding gap had to be closed by hand

All four TCP forwards bound their listeners immediately. The UDP forwards did
not: they sat at `state=degraded, error_code=awaiting_policy` with repeated
`ultra UDP candidate probe failed ... external UoT sidecar probe rejected`.

Cause: each sidecar admits only the forward ids present in the `forwards` map of
`/etc/leikwan-external-uot.json`. The HK sidecar bound 21 ids (198 among them,
from the previous round) and rejected the new ones with "forward is not allowed".
**TCP did not hit this because the TCP path does not require the sidecar HELLO
admission that the UDP path does.**

Bindings were added with the documented safe procedure: back up the config to
`/root/lk-accept-backup/leikwan-external-uot.json.before`, add the ids cloned
from an existing entry, write to a temp file and `os.replace`, validate with
`--check-config`, restart the unit, re-read the bound ids. All four units came
back `active`.

Note the template semantics differ per host: on 70.36.96.183, 157.228.138.23 and
208.122.17.50 the existing entries use `targets: ["*"]`, while on
185.220.238.143 most entries use `targets: []`. Cloning copied whatever the
first entry happened to be, so the new bindings inherited that host's local
convention rather than a deliberate choice.

## Current listener state

```
tcp *:60502   tcp *:60508   tcp *:60510   tcp *:60512    <- all 4 TCP up
udp *:60501   udp *:60509                              <- 2 of 4 UDP up
```

| forward | protocol | state |
|---|---|---|
| 201 | udp (HK 70.36.96.183) | ready |
| 202 | tcp (HK) | ready |
| 204 | tcp (157.228.138.23) | ready |
| 205 | udp (185.220.238.143) | ready |
| 206 | tcp (185.220.238.143) | ready |
| 208 | tcp (208.122.17.50) | ready |
| 203 | udp (157.228.138.23) | **no listener, no status file** |
| 207 | udp (208.122.17.50) | **no listener, no status file** |

## What is not yet explained

203 and 207 never produced a status file at all, which is different from the
`awaiting_policy` failure. Their sidecars (157.228.138.23, 208.122.17.50) are
also the two whose `lk-udp-echo-62001` was unreachable from peer hosts earlier.
Both facts point at outbound UDP from those two hosts rather than at the tunnel,
but the echo runs on the sidecar itself while the forward target is
`<that same host>:62101`, so the exact failure is not yet isolated.

## Targets

`lk-target-pump` is running on all six sidecars and listening on 62101 (UDP
rate sink, replies `LKT1ACK`) and 62102 (TCP responder, replies `LKT1ACK`).
`leikwan-external-uot.service` is active on all six. Removal is one command per
host: `systemctl stop lk-pump.service`.
