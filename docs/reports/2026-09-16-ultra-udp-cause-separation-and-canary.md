# Ultra UDP cause separation and clean canary (2026-09-16)

## Scope

This note records the Linux forward-170 experiments run against the deployed
Ultra runtime. It separates three possible causes that had been conflated in
the incident discussion:

1. lease refresh / policy replacement;
2. UDP payload ceiling or per-association failure;
3. provider credential or relay handshake failure.

The test used an operator-owned UDP echo endpoint and a TCP heartbeat. No
credential, token, or packet capture is stored in this repository.

## Evidence

### Clean low-size canary

Window: 120 seconds, 10 UDP packets/second, 256-byte inner payload, without a
service restart.

| Path | Sent | Received | Loss | RTT |
| --- | ---: | ---: | ---: | --- |
| Ultra tunnel UDP | 1200 | 1200 | 0.00% | p50 49.56 ms, p95 50.83 ms |
| Direct UDP baseline | 1200 | 1082 | 9.83% | baseline only |
| Ultra tunnel TCP | 120 | 120 | 0 breaks | p50 46.59 ms, p95 48.24 ms |

The policy generation stayed constant. `lease_hash` and `selection_ref` changed
normally during the window, and the runtime remained `ready` with no restart.
This is direct evidence that a lease refresh did not interrupt an otherwise
healthy association.

### Fresh association sequence

After a controlled service restart, the same path was tested with 256-byte,
1400-byte, and 256-byte payloads.

* 256 bytes: 444/450 (1.33% loss); TCP stayed connected.
* 1400 bytes: 0/450 (100% loss); TCP stayed connected.
* 256 bytes after the large-packet run: 450/450 (0% loss).

The large-packet run increased `udp_oversize_drops` and produced
`udp_payload_ceiling_unusable` / `keepalive_timeout` runtime failures. The
subsequent small-packet recovery selected a usable association again. This is
consistent with a payload-ceiling or per-association failure, not a generic
service or TCP outage.

### Polluted recovery windows

Two longer windows were intentionally run after the association had been
poisoned by oversized traffic:

* 60 seconds, 256 bytes: 0/600 tunnel UDP, 60/60 tunnel TCP.
* 300 seconds, 256 bytes: 0/1500 tunnel UDP, 300/300 tunnel TCP.

These are failure-recovery evidence, not a claim that small UDP is always
broken. The clean canary above is the valid low-size baseline.

### Exact boundary experiment

The rapid-restart 1272/1273/1274/1280-byte experiment is **inconclusive**.
Repeated restarts left the control plane in `awaiting_policy`, so those loss
rates cannot be used to assert an exact MTU threshold.

## Attribution

### Confirmed

* Large UDP payloads can make the active association unusable while TCP remains
  healthy.
* A later `no_healthy_candidate` can therefore be a consequence of
  `udp_payload_ceiling_unusable` or a 15-second UDP keepalive timeout.
* Provider-side candidate failures are also present independently: logs contain
  `probe_udp_failed` with EOF, provider connect timeouts, and
  `udp_endpoint_unreachable` / `udp_arm_failed`.
* The active-candidate forwarding fallback is not a proof that UDP is healthy;
  its check is a real forwarding check and must not be treated as a UDP probe.

### Not supported by current evidence

* Lease refresh is not the root cause on a healthy association. The clean
  canary crossed a lease/selection refresh with zero tunnel loss.
* A global credential escaping bug is not demonstrated. The lease path uses
  JSON decoding and length-prefixed raw credential bytes; it does not pass the
  credential through a shell. Credential/relay rejection remains a separate
  candidate-specific failure and needs a controlled same-credential replay to
  prove or exclude.
* The exact payload threshold is not established by the rapid-restart test.

## Remaining work

1. Run a single-candidate, rate-limited probe against one healthy relay and one
   command-3-EOF relay, recording command-3 control replies and UDP association
   behavior separately.
2. Replay the same lease credential through the local handshake builder and a
   known-good relay; compare raw length/checksum bytes without logging the
   secret.
3. Add per-association counters for valid keepalive replies, payload-ceiling
   rejection, and provider handshake failure before changing selection policy.
4. Keep the current production defaults unchanged until those measurements
   distinguish relay congestion, demux/source-port loss, MTU, and credentials.

