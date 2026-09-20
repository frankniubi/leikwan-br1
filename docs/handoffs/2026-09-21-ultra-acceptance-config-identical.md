# Ultra acceptance: the failing forward is config-identical to the working one (2026-09-21)

## Exact config diff, production 170 (works) vs new 205 (fails)

Every field matches. Only identity and ownership differ:

```
forward_id         170            205
listen_port        10001          60509
target             185.220.238.143:10001   185.220.238.143:62101
target_digest      0f877086...    f03685ef...
policy_generation  1e85f016...    296c9fc0...
control_socket     ...170...      ...205...
status_path        ...170...      ...205...
transport          tcp+udp        udp
```

Identical: `external_uot` in full including `token` (both 48 chars) and
`sidecar_target=185.220.238.143:7443`, `delivery_mode=reliable_datagram_v1`,
`queue_packets=4096`, `frame_payload_max=65507`, `auto_select=True`,
`udp_prewarm=True`, both probe settings, both location paths, both socket limits.

## Behaviour

```
production fwd170 -> 185.220.238.143:62101   12/12
production fwd170 -> 70.36.96.183:62101      12/12
production fwd170 -> 208.122.17.50:62101     12/12
production fwd170 -> 157.228.138.23:62101    12/12

new fwd201 (combo51, udp)    -> 185.220.238.143:62101    0/12
new fwd209 (combo51, tcp+udp)-> 185.220.238.143:62101    0/12
new fwd205 (combo54, udp)    -> 185.220.238.143:62101    0/12
new fwd210 (combo54, tcp+udp)-> 185.220.238.143:62101    0/12
```

Note 170 also reaches **every** sidecar, so sidecar identity is excluded.

## Hypotheses eliminated with evidence

| hypothesis | verdict | evidence |
|---|---|---|
| `transport_mode=udp` breaks UDP | **rejected** | tcp+udp control forwards 209/210 fail identically |
| the target is dead | **rejected** | same target answers 12/12 through fwd170 |
| the sidecar is wrong | **rejected** | fwd170 works to all four sidecars |
| service 30 is broken | **rejected** | its TCP forwards 202/204/206/208 bind and connect |
| the combination is misconfigured | **rejected** | combos 51/54 have the same UoT settings as 57 |
| the panel withholds the UoT grant | **rejected** | `external_uot` including a 48-char token is present |
| the sidecar rejects the forward id | **rejected** | ids were added to the forwards map; carrier attaches |

## What the evidence does say

1. The client **does** attach the carrier: `external UoT carrier attached
   forward_id=205 logical_protocol=udp carrier=tcp`, repeatedly, with a stable
   candidate hash. So the HELLO succeeds.
2. The sidecar then reports `udp_in=0 udp_out=0 queue_drops=0` on every session
   close, so no datagram ever enters the carrier.
3. The failing forward has **no usage counters at all** in its status file, while
   170 carries `bytes_out`. The client receives datagrams on its listener and
   never writes them into the tunnel.

So the break is between "datagram arrives on the forward listener" and "datagram
is handed to the UoT carrier", and it is specific to forwards created during
this session rather than to any configuration value I can observe.

## The caveat that matters for the objective

None of this has yet been tested against the merged code. The Linux host still
runs `2026.09.19.1.f18d01fa`, the build from before this session's fixes, so
the comparisons above are all against the old runtime. Two of the fixes in the
merged tree touch exactly this area:

- the lease delivery fix that carries `external_uot` across `/ultra/lease`
  (`attachExternalUotToLease`), and
- the reliable-carrier admission path.

Fixing or explaining this failure therefore cannot be separated from deploying
the merged candidate to the test host, which needs a bounded
`LEIKWAN_UPDATE_HOLD_UNTIL` plus a rollback timer because the host is
agent-managed.

## Live artefacts (all reversible)

forwards 201..210 on service 30; `lk-pump.service` on six sidecars (UDP 62101,
TCP 62102); `lk-udp-echo-62001.service` on six sidecars; added bindings in
`/etc/leikwan-external-uot.json` on four sidecars with backups at
`/root/lk-accept-backup/`; `leikwan-external-uot.service` active on all six.
