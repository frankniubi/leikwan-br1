# Ultra acceptance: merged candidate deployed; UoT still fails but now transmits (2026-09-21)

## Deployment (Linux test host 172.21.255.189)

Per operator direction, all testing now uses the merged build.

| item | value |
|---|---|
| artifact sha256 | `fbf6d9777568048b6eb42785ded7fc76f822d9888813877045325d39a6794c6a` |
| runtime version stamp | `2026.09.21.1.localcandidate` |
| deployed path | `/usr/local/bin/ultra-client` |
| reported attestation | `fingerprint=2026.09.21.1.localcandidate`, `self_sha256=fbf6d977...` |
| original backed up | `/root/lk-accept-backup/ultra-client.orig` (`a391e5b9...`) |
| update hold | `LEIKWAN_UPDATE_HOLD_UNTIL=1789934120` in a systemd drop-in; agent restarted, hold confirmed active |
| independent rollback | `lk-accept-rollback.timer`, 2 hours, restores the original binary and drops the hold |

Runtime identity checks were **not** disabled and no official artifact identity was
claimed: the stamp is explicitly a local candidate.

All 13 ultra-forward units restarted onto the new runtime and 6 listeners came
back up.

## What changed and what did not

```
                       BEFORE (2026.09.19.1)   AFTER (merged candidate)
fwd201 -> 185:62101    0/12, bytes_out None     0/12, bytes_out 14484
fwd205 -> 185:62101    0/12, bytes_out None     0/12, bytes_out None
fwd170 -> 185:62101   12/12                    12/12
```

Two facts, both new:

1. Forward 201 now records `bytes_out=14484`, where the old runtime recorded
   nothing at all. The merged build **does** carry datagrams into the tunnel, so
   at least one of the session's fixes changed this path's behaviour.
2. The datagrams still do not come back, so the fix is necessary but not
   sufficient and something downstream of the carrier write still drops them.

`fwd205` still reports no `bytes_out`, so the two new forwards behave
differently from each other under the same build. That difference is itself
evidence and has not been explained.

## Status against the operator criterion

Criterion: TCP+UDP at 20 Mbps for 10 minutes with no interruption and no stream
break, via UoT. **Not met.** The data plane still loses 100% of replies on the
new forwards while the production forward 170 keeps working 12/12 on the same
build, the same sidecar and the same target.

## Live artefacts (all reversible)

- merged candidate installed on `172.21.255.189` under a 2-hour hold with an armed
  rollback timer
- forwards 201..210 on service 30
- `lk-pump.service` on six sidecars (UDP 62101, TCP 62102)
- `lk-udp-echo-62001.service` on six sidecars
- added bindings in `/etc/leikwan-external-uot.json` on four sidecars, backups at
  `/root/lk-accept-backup/`
- `leikwan-external-uot.service` active on all six

## Deliberately not done

The branch was **not** pushed. The operator deferred it while the work is in
progress, so the 400-plus commits of enterprise work remain local-only.
