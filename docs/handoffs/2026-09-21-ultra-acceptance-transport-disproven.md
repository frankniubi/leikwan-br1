# Ultra acceptance: transport mode disproven, fault narrowed (2026-09-21)

## Hypothesis tested and rejected

Hypothesis: `transport_mode=udp` (UDP-only forwards) is what breaks the UoT
data plane, since the working production forward 170 is `tcp+udp`.

Control: forwards **209** (combo 51, HK) and **210** (combo 54, Tokyo) were
created with `transport_mode=tcp+udp`, the **same sidecar and the same target**
as the failing UDP-only forwards 201 and 205. Both bound TCP and UDP listeners.

Result, 8-second 5 Mbps UDP runs:

| forward | transport | result |
|---|---|---|
| 201 (HK) | udp | 4143 sent, **0 acked**, 100% loss |
| 209 (HK) | tcp+udp | 4141 sent, **0 acked**, 100% loss |
| 205 (TYO) | udp | 4142 sent, **0 acked**, 100% loss |
| 210 (TYO) | tcp+udp | 4142 sent, **0 acked**, 100% loss |

**Rejected.** Transport mode makes no difference. The hypothesis is dead and the
control pair is a clean negative result, not a failed experiment.

## The tunnel is not the problem, and the target is not the problem

The same payloads, sent from the same test host to the same overseas targets,
through the **production** forward 170, all succeed:

```
fwd170 -> 185.220.238.143:60000 (tokyo-echo)     20/20
fwd170 -> 185.220.238.143:62101 (LKT1 pump)      20/20
fwd170 -> 185.220.238.143:62001 (private LKU1)   20/20
fwd205 -> 185.220.238.143:62101 (LKT1 pump)       0/20
fwd210 -> 185.220.238.143:62101 (LKT1 pump)       0/20
```

So: the tunnel carries UDP correctly, the targets answer correctly, and the
only variable left is the **forward itself**.

## The sharpest clue: the failing forward never emits a byte

Configurations are identical in every field that should matter:

```
170  transport=tcp+udp  mode=reliable_datagram_v1  sidecar=185.220.238.143:7443  token_len=48
205  transport=udp      mode=reliable_datagram_v1  sidecar=185.220.238.143:7443  token_len=48
```

But their status files differ in a decisive way:

```
170  state=ready  bytes_out=316722   selection_ref=axfl6ZRC...
205  state=ready  bytes_out=None     selection_ref=iq21Ky1A...
```

`bytes_out` is absent for 205, meaning **the runtime never wrote anything into
the tunnel**, even though the harness sent thousands of datagrams to
`127.0.0.1:60509` and the listener was confirmed bound. The client therefore
received the datagrams at the socket and dropped them before the carrier, which
is consistent with the sidecar observing `udp_in=0` on every session.

This is the thing to chase next: why a freshly created forward with a fresh
lease and an attached carrier never forwards a datagram, while a long-lived
production forward on the same build and the same sidecar forwards normally.
Candidate explanations, none yet tested: per-forward UDP association creation
keyed on something the new leases lack, the new leases being shorter-lived than
the association setup, or a policy/route-scope difference between the combos.

## Note on the sidecar binding step

Forwards 209 and 210 did **not** bind their listeners until their ids were added
to the sidecar forwards map, even though they are `tcp+udp`. So the binding
requirement is not UDP-specific, which contradicts the earlier reading that TCP
forwards did not need it. Every newly created forward needs the sidecar binding,
and this is exactly the manual step the sidecar pool is meant to remove.

## Live artefacts (unchanged, all reversible)

- forwards 201..210 on service 30
- `lk-pump.service` on six sidecars (UDP 62101, TCP 62102)
- `lk-udp-echo-62001.service` on six sidecars
- added bindings in `/etc/leikwan-external-uot.json` on four sidecars, backups at
  `/root/lk-accept-backup/`
- `leikwan-external-uot.service` active on all six
