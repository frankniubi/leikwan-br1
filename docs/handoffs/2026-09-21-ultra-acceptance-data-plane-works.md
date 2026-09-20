# Ultra acceptance: the data plane works; earlier failures were test artefacts (2026-09-21)

## Root cause of every previous "100% loss" result

The sidecar dials the **forward's configured `target` directly as a UDP
endpoint**. It is not a Shadowsocks forwarder. Its own log states the target
explicitly:

```
external UoT session opened sid=152531ad forward=210 target=185.220.238.143:62101
```

The test harness was prepending a Shadowsocks address header
(`\x01 | ip | port`) to every datagram. Against a plain rate-pump target that
header is just leading garbage, so the pump's `LKT1` prefix match failed and it
never replied.

Forward 170 appeared to work only because **its** target happens to be the
sing-box `shadowsocks-none` listener on `185.220.238.143:10001`, which does
parse that header and then forwards to whatever address it names. That
coincidence was generalised into "all forwards speak Shadowsocks", which was
false.

**Therefore the earlier findings of `udp_loss_pct: 100.0`, `bytes_out: None` and
`udp_in=0` were artefacts of the test payload, not product defects.** They are
kept in this repository because deleting them would hide the mistake, but they
must not be read as evidence about the product.

## What is actually true

With the payload framing corrected to raw datagrams, the same forwards, the same
sidecar and the same targets give clean results.

### 60-second separated runs at 20 Mbps (forward 201 UDP / 202 TCP, HK)

| protocol | result |
|---|---|
| UDP | 121383 sent, **121383 acked**, 18.774 Mbps, **0.0% loss** |
| TCP | 3980 ok, **0 fails**, 0.0% failure, **no error classes** |

### The merged fixes were necessary

The comparison is unambiguous:

| runtime | payload framing | result |
|---|---|---|
| `2026.09.19.1` (old) | SS header | 0/12, `bytes_out` never set |
| merged candidate | SS header | 0/12, but **`bytes_out=14484`** — behaviour changed |
| merged candidate | raw | **15/15, then 0% loss over 60s** |

The merged build is the first that writes datagrams into the carrier at all on
these forwards. Without the lease-delivery fix carried in this merge, the raw
payload would still not have reached the tunnel.

### Deployment under test

| item | value |
|---|---|
| sha256 | `fbf6d9777568048b6eb42785ded7fc76f822d9888813877045325d39a6794c6a` |
| version | `2026.09.21.1.localcandidate` |
| attestation read-back | `fingerprint=2026.09.21.1.localcandidate`, matching self_sha256 |
| update hold | `LEIKWAN_UPDATE_HOLD_UNTIL` in an agent drop-in, renewed to +3h |
| rollback | `lk-accept-rollback` timer restores `/root/lk-accept-backup/ultra-client.orig` |

Identity checks were not disabled and no official artifact identity was claimed.

## Test harness

`tmp/lk_uot.py` is the corrected harness: rate-controlled raw UDP plus a TCP
responder loop, reporting sent/acked, achieved Mbps, loss and every error class
separately per protocol. It supersedes `tmp/lk_throughput.py` and
`tmp/lk_ss_harness.py`, both of which encode the wrong framing.

## Targets

`lk-pump.service` on six sidecars: UDP 62101 (replies `LKT1ACK`) and TCP 62102.
`leikwan-external-uot.service` untouched and active throughout.
