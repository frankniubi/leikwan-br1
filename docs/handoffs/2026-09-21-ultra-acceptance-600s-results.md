# Ultra acceptance: 600-second results (2026-09-21)

## Verdict

**FAIL against the operator criterion** (TCP+UDP at 20 Mbps for 10 minutes with
no interruption and no stream break).

| protocol | 600 s result |
|---|---|
| UDP | 1,232,328 sent / 1,082,017 acked, 19.652 Mbps, **12.1973% loss** |
| TCP | 25,807 ok / **391,565 failed**, 93.8168% failure |

TCP error classes: `ConnectionRefusedError` 391,543, `ConnectionResetError` 15,
`empty_reply` 7.

## This is load-dependent, not a flat failure

The same forwards, sidecar and targets passed cleanly at 20 Mbps for 60 seconds:

| duration | UDP loss | TCP failures |
|---|---|---|
| 60 s | **0.0%** (121,383/121,383) | **0** (3,980 ok) |
| 600 s | 12.20% | 93.82% |

So the data plane is correct and can carry the load; sustained load then degrades
it. `ConnectionRefusedError` dominating means the local listener stopped accepting
for long stretches, which is consistent with the runtime or supervisor cycling
under sustained pressure rather than with packet loss on the wire. UDP losing 12%
over ten minutes while losing nothing in one minute points at the same kind of
resource exhaustion rather than at the tunnel protocol.

## What is established

- The forward configured `target` is dialled by the sidecar as a plain UDP
  endpoint, with no Shadowsocks framing. The payload must be raw.
- With raw payloads the merged candidate carries UDP at ~19.7 Mbps with zero loss
  over 60 s and TCP with zero failures over 60 s.
- The merged build is the first that writes datagrams into the carrier at all on
  these forwards (`bytes_out` went from unset to populated), so the fixes in this
  merge are necessary for the path to work.
- Earlier `100% loss` results were test-payload artefacts, not product evidence.

## Not yet explained

- `ConnectionRefusedError` at 391,543 occurrences means the forward listener was
  refusing connections for most of the run. Why it stops accepting under
  sustained load is the next thing to find.
- `bytes_out` reads `None` on 201 right after the run although it reached 302 MB
  during it; either the counter resets or the status file was rewritten.
- The 12% UDP loss has no accompanying `queue_drop`, `queue_timeout`,
  `sequence_gap` or `retransmit_count` in the status read, so the runtime own
  counters do not yet attribute it.

## Deployment under test

`fbf6d9777568048b6eb42785ded7fc76f822d9888813877045325d39a6794c6a`,
version `2026.09.21.1.localcandidate`, installed under a renewed
`LEIKWAN_UPDATE_HOLD_UNTIL` with an armed rollback timer. Identity checks were
not disabled.
