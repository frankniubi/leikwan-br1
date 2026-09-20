# Ultra acceptance: first real measurements and what they show (2026-09-21)

## Setup that produced these numbers

Six of the eight temporary forwards reached `state=ready` and bound their
listeners. Measurements were driven **from the Linux test host** through the
temporary forwards toward overseas targets, with TCP and UDP run separately:

| forward | protocol | listen | sidecar | target |
|---|---|---|---|---|
| 201 | udp | 60501 | 70.36.96.183 | 70.36.96.183:62101 |
| 202 | tcp | 60502 | 70.36.96.183 | 70.36.96.183:62102 |
| 205 | udp | 60509 | 185.220.238.143 | 185.220.238.143:62101 |
| 206 | tcp | 60510 | 185.220.238.143 | 185.220.238.143:62102 |

## Measured results (5 second smoke, 5 Mbps UDP target)

| pair | result |
|---|---|
| udp-HK (201) | sent 2590 packets, **0 acked**, 100% loss |
| udp-TYO (205) | sent 2588 packets, **0 acked**, 100% loss |
| tcp-HK (202) | **8/8 connections timed out** |
| tcp-TYO (206) | 2 ok, **15803 failed**, 99.99% failure, all `TimeoutError` |

So the first honest acceptance numbers are: **UDP 100% loss, TCP ~100% failure**.
This is a FAIL, not a pass, and it is measured end to end from the test host.

## Evidence that locates the fault

The **outbound** direction is alive and healthy on the client:

- `forward-201` status: `state=ready`, `active_udp` selected with `rtt_ms=126`,
  `bytes_out=3126130`, and `queue_blocked=0 queue_timeout=0 queue_drop=0
  socket_receive_drop=0`.
- Socket buffers are real: `socket_rcvbuf=33554432 socket_sndbuf=33554432`,
  `socket_buffer_error=false`.
- The carrier attaches: `external UoT carrier attached forward_id=201
  sid=abe60eef logical_protocol=udp carrier=tcp`, repeated per lease, and the
  canary probes keep succeeding (`reason=canary_probe_ok`).

But on the **sidecar**, every session closes with nothing having traversed it:

```
INFO external UoT session closed sid=324fc6be udp_in=0 udp_out=0
     queue_drops=0 oversize_drops=0 reattaches=0
```

Repeated for every session id. `udp_in=0` with zero queue drops means the
sidecar never received the client's datagrams on the UoT carrier at all. This
is not congestion and not a silent drop inside the sidecar: the frames did not
arrive.

On the target host the rate-pump confirms the same picture:

```
stats {'udp_rx': 3, 'udp_tx': 2, 'udp_bytes': 1225, 'tcp_conns': 19, 'tcp_bytes': 9}
```

Nineteen TCP connections arrived but only 9 payload bytes total, so the TCP
tunnel is reaching the target while carrying essentially no application data.

## Two failures that are definitely not the target

Both failing sidecar hosts answer perfectly when probed locally **and** on
their own public address:

```
157.228.138.23   udp 127.0.0.1:62101 OK   udp 157.228.138.23:62101 OK   tcp 62102 OK
208.122.17.50    udp 127.0.0.1:62101 OK   udp 208.122.17.50:62101 OK    tcp 62102 OK
70.36.96.183     udp 127.0.0.1:62101 OK   udp 70.36.96.183:62101 OK    tcp 62102 OK
```

No nftables or iptables rule references the test ports. So forwards 203 and 207
are not failing because their target is unreachable.

## Forwards 203 and 207: cause identified

Their units were stopped by the client itself:

```
ultra UDP candidate probe failed endpoint=106.75.79.14:5438 ...
  error=probe_tcp_failed: external UoT sidecar probe rejected
systemd: Stopping ultra-forward-203.service ... Deactivated successfully
```

The agent had applied them correctly (`applied_forward_ids` includes 203 and
207, `invalid_forward_ids` is empty), and their unit files exist, but every
candidate in their pool failed the sidecar probe and the runtime then stopped
the forward. Every probed address in those logs is a **Chinese provider relay**
(106.75.x, 119.91.x, 113.31.x, 42.192.x), which is a different candidate pool
from the one forward 201 used successfully.

## The one thing that would explain both symptoms at once

The sidecar now admits the new forward ids, and the carrier attaches, yet
`udp_in=0`. The previous round proved the same tunnel carries UDP correctly for
forward 170 (40/40 echoes to a UDP echo target). The difference between 170 and
201 is the **transport configuration of the forward**: 170 is `tcp+udp`, while
201 and 205 were created as UDP-only (`transport_mode=udp`). That is the leading
hypothesis and the next thing to test: recreate a pair with `transport_mode`
`tcp+udp` and compare against the UDP-only pair on the same sidecar and target.

## Cleanup state

Nothing has been torn down yet. Live test artefacts: forwards 201..208 on
service 30, `lk-pump.service` on all six sidecars (UDP 62101, TCP 62102),
`lk-udp-echo-62001.service` on each sidecar, and added forwarding bindings in
`/etc/leikwan-external-uot.json` on four sidecars with backups at
`/root/lk-accept-backup/`. `leikwan-external-uot.service` is active everywhere.
