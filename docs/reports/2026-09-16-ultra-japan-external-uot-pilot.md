# Ultra Japan external-UoT pilot (2026-09-16)

## 1. Objective and topology decision

The operator selected a no-slave data path for ordinary fixed-target Ultra
forwards whose native UDP is unreliable:

```text
client UDP -> client UoT companion -> Ultra command-1 TCP carrier
-> LeiGod relay (plain TCP forwarding) -> Japan sidecar UoT terminator
-> operator-owned UDP target
```

This requires no LeiGod relay changes, no command 4, and no slave bandwidth.
Phase 0 research also confirmed no provider relay source is available in any
operator repository or host, so a provider-native UoT cannot be implemented
now regardless of merit; see the phase 1 source inventory.

## 2. What was actually run

Four opt-in experiments, each on the existing Linux test host 172.21.255.189,
against forward 170 and the existing Japan sidecar test listener. Test scripts
are in `tools/ultra-uot-lab/`; raw evidence is under `tmp/jp-uot-*20260916/`.

| Round | Duration | Payloads | Result |
|---|---|---|---|
| Smoke | 10s | 4 flows × 100 packets | 400/400 intact; all four sizes carried |
| Slow canary | 600s | 4 flows × 6000 packets | 24,000/24,000; 0 corrupt; 0 reconnects |
| Pipeline (send independent of receive) | 185s | 4 flows × ~11,627 | 46,513/46,513; 0 loss/dup/corrupt/late |
| Real UDP ingress (companion) | 185s | 4 peers × ~11,640 | 46,558/46,558; 0 loss/dup/corrupt |

Payload sizes cycled 256/1400/4096/16000 bytes. Pipeline rounds ran 60pps per
flow with a one-second 200pps burst every 30 seconds. Each round used exactly
one TCP carrier per flow; no round-trip-gated sending except the slow canary.

## 3. Verified properties

**Delivery.** 117,471 of 117,471 datagrams returned byte-identical across all
four rounds; each reply was compared against a deterministic SHA-256-derived
payload, not merely length or magic. Zero duplicates, zero corruption, zero
loss, and zero replies over 500ms in the two pipeline rounds.

**Datagram size.** Native UDP path loses 1400-byte datagrams on this forward;
the UoT framing carried 16000-byte datagrams without loss. The former payload
ceiling problem does not reappear when UDP rides the command-1 TCP carrier.

**Latency.** Per-flow p50 was 45-53ms and p95 57-78ms; p99 reached 94-147ms
during bursts and the single worst observed sample was 247ms. No packet
exceeded 500ms in the pipeline rounds. This is TCP retransmission tail, not
queuing: queues were bounded to 256 datagrams per peer with one-second expiry.

**Lease refresh continuity.** The slow canary spanned four independent
`ultra-client` lease renewals for forward 170 (journal evidence captured in
`tmp/jp-uot-canary-20260916/renewals.txt`); all four application TCP carriers
survived every renewal, `ultra-forward-170.service` kept the same MainPID and
zero restarts, and no `no_healthy_candidate` was logged. Lease renewal without
a candidate change does not disturb the UoT carrier, matching the clean-canary
behavior seen with native UDP.

**UDP semantics.** The sidecar terminator reads and writes its UDP socket
independently; a unit test proves two responses to two requests can arrive in
reverse order and unsolicited, which a request/response bridge would deadlock
on. Framing tests cover TCP segmentation and coalescing, truncation and
oversize rejection, and token rejection.

## 4. Honest limitations

**Carrier failure, not lease refresh, is untested.** No experiment forced a
candidate change or relay disconnect mid-flow. Reconnect after a real carrier
failure creates a new sidecar UDP socket; the target would then see a new
source port. Stable sidecar-socket resumption (session ID reattach) is not
implemented and is the main remaining engineering gap.

**Transport adapter, not final protocol.** The pilot reuses the existing
SS-none test listener on the Japan sidecar as a TCP entry point; the frame
format, shared-token authentication, and loopback-only server are laboratory
provisions. Production needs a dedicated authenticated listener, TLS or an
equivalent protected channel, per-forward credentials, and a target-binding
contract so a compromised client cannot redirect the sidecar to arbitrary
hosts. The token is transport-shared, not per-session derived.

**Single region, single relay pool, one host.** Only the Japan sidecar and one
Linux client were tested. Windows and macOS have not run this pilot. Reverse
throughput (heavy target->client traffic) was exercised only by echo symmetry,
not by asymmetric load. The companion caps at 16 concurrent UDP peers.

**No production code changed.** `ultra-client`, `client-agent`, panel, and
installer were untouched; nothing was committed, and no public endpoint was
opened. The Japan sidecar bound loopback only, and every experimental unit and
token was removed after each round.

## 5. Recommended next steps

1. Implement session-reattach framing (stable session ID + sidecar socket
   reuse) so a forced carrier reconnect preserves the target-visible source
   port; test with an explicit relay disconnect.
2. Replace the SS-none test adapter with a dedicated sidecar listener carrying
   per-forward authentication and an explicit allow-listed target contract.
3. Extend `ultra-client` with a UDP companion mode on the same listen port as
   the TCP listener (or a second unit) so runtime packaging can ship one
   binary; keep the framing identical to this pilot.
4. Run forced-failover, reverse-load, and multi-region (JP plus one more
   sidecar) canaries before any Windows/macOS porting.
5. Keep native UDP as the default transport; expose UoT as an explicit
   per-forward UDP transport option (`udp_transport=external_uot`) once the
   session contract and panel plumbing exist.

Reproducing locally: `python -m unittest discover -s tools/ultra-uot-lab -p
'test_*.py' -v` (4 tests). The lab scripts and their usage are documented in
`tools/ultra-uot-lab/README.md`.
