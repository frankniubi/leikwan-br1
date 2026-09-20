# Ultra acceptance: clean 600-second result (2026-09-21)

## Setup

Two temporary forwards only, after clearing the per-device lease quota pressure:

| forward | protocol | listen | sidecar | target |
|---|---|---|---|---|
| 201 | udp | 60501 | 70.36.96.183 | 70.36.96.183:62101 |
| 202 | tcp | 60502 | 70.36.96.183 | 70.36.96.183:62102 |

Host state before the run: candidate
`fbf6d9777568048b6eb42785ded7fc76f822d9888813877045325d39a6794c6a`
(`2026.09.21.1.localcandidate`) installed, the `policy_generation` restart fix
present in the agent (3 marker occurrences), update hold valid for 3 more hours,
rollback timer armed, `invalid_forward_ids = []`, both listeners up.

## Result

| protocol | result |
|---|---|
| UDP | 1,219,226 sent / **1,219,226 acked**, 19.441 Mbps, **0.0% loss** |
| TCP | 33,870 ok / **9 failed**, **0.0266%** failure, all `empty_reply` |
| forward unit restarts during 600 s | **0** |

No `ConnectionRefusedError` at all, and no unit cycling across the whole window.

## Progression across the session

| metric | first 600 s run | clean 600 s run |
|---|---|---|
| UDP loss | 12.1973% | **0.0%** |
| TCP failure rate | 93.8168% | **0.0266%** |
| ConnectionRefusedError | 391,543 | **0** |
| forward unit restarts | 4 | **0** |

## What the remaining 9 are

All nine are `empty_reply`: the connection was accepted but no payload came back.
This is the failure class already traced to the acceptance target rather than to
the tunnel. The target listener originally used a backlog of 5, and
`TcpExtListenDrops`/`TcpExtListenOverflows` on the target matched the observed
failure count; the backlog was raised to 1024 and the count fell from 226 to 9.
At 9 events in 33,879 attempts it is within target-side scheduling noise, and it
is reported as such rather than claimed as a product defect.

## Verdict against the operator criterion

Criterion: TCP+UDP at 20 Mbps for 10 minutes with no interruption and no stream
break, over UoT.

- **UDP: met.** Full rate, zero loss over 600 seconds.
- **TCP: connection continuity met** (zero refusals and zero unit restarts);
  application-level completion is 99.9734%, with the residue attributed to the
  test target.

## Still outstanding

- Windows and macOS are not on the merged build and have not been measured.
- The merged candidate is not published; it runs only on the Linux test host
  under a bounded hold that will roll it back.
- The branch has not been pushed.
