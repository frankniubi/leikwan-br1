# Ultra probe-failure evidence, and what "always UoT" actually costs (2026-09-13)

## 1. Probe failures are now evidence

### The blind spot

On 2026-09-13 the client log showed, repeatedly and on the hour:

    ultra UDP candidate probe failed endpoint=106.75.227.147:5438 selection_ref=...
      error=probe_udp_failed: datagram handshake failed after greeting fallback:
             initial=datagram handshake: EOF; legacy=datagram handshake: EOF

The relay had **accepted the TCP control connection and then closed it without a
reply**, for both greeting variants - it answers TCP but will not do the UDP
datagram handshake. That is ~20% of the candidate pool (539 failures across 661
selections today).

Nothing learned from it. `selectBestLeaseWithProbe` rejected the candidate
locally, the runtime picked another one, and the **same relay was probed again on
the next batch, forever**: per-candidate probe results never reached the
candidate feedback channel, and the Panel's reason allowlist had no entry for
them anyway.

### Fix

* Runtime (`tools/ultra-client`): `selectBestLeaseWithProbeReporting` and
  `selectBestLeaseReporting` take a `probeFailureSink`; both production
  selection call sites (`control.go`, `control_windows.go`) pass
  `StatusWriter.RecordProbeFailure`, which emits
  `probe_udp_failed` / `probe_tcp_failed` feedback bound to the rejected
  candidate's `candidate_key_hash` and `lease_hash` - both halves when both
  protocols failed.
* Panel: both reasons joined `TRANSPORT_REASONS`, so they are **counted and
  logged, never a failure strike** (`ultra_candidate_transport_feedback
  client=... device=... probe_udp_failed=N`), exactly like `udp_arm_failed`.

That last decision is the important one. A pre-arm probe result says "this
endpoint did not answer from this host", not "the line is broken". Counting it as
a failure strike is precisely how healthy lines were quarantined before.

### Tests

* Go: `probe_feedback_test.go` - the rejected candidate reaches the sink and the
  healthy one does not; the feedback carries the relay identity, `outcome =
  failure`, `reason = probe_udp_failed`; both halves are reported when both
  fail; a healthy probe produces no feedback at all. Full runtime suite green
  (`go test ./...`, 157 s).
* PHP: `ultra_candidate_feedback_contract_test.php` now asserts both reasons are
  transport-classified, that they never reach reputation, and that they do not
  move the failure counter or become `last_reason`.

### Released

| | |
|---|---|
| runtime | **2026.09.13.2**, `226cc3aac2c786df5abe2874848af772d1449b48bfe98f17e43ffafb61e260e7`, 7,101,952 B |
| fingerprint | `2026.09.13.2.452c8fe8` |
| build | go1.26.5, cgo-disabled, stripped, **unobfuscated** (Defender), reproducible, Defender scan clean |
| pin | `windows_client_app.runtime_sha256.ultra_client_runtime_windows` moved `ecbee8f7` -> `226cc3aa` |

The attestation block was updated with the build, not just the hash: leaving the
old fingerprint next to a new binary would have made the published fingerprint
map inconsistent. Verified served: runtime `2026.09.13.2 / 226cc3aa /
2026.09.13.2.452c8fe8`, app pin `226cc3aa`, artifact bytes hash to the
published value.

The Linux, macOS and Android runtimes carry the same Go change but were not
rebuilt in this pass; they need their own builds to pick it up.

## 2. "Always UoT" is a protocol feature, not a toggle

The request: let a user choose UoT so it does not matter whether a relay answers
UDP.

**What exists today.** The Ultra relay protocol has exactly two handshake
commands (`buildHandshakeWithGreeting`): `1` = byte stream, `3` = datagram
(UDP) association, where the payloads travel as **real UDP datagrams** and the
TCP connection is only the control channel. There is no UDP-over-TCP framing on
either end. `transport = "tcp"` is not UoT either - it makes the runtime serve
**TCP only**, so a UDP game would simply stop working.

**What "always UoT" therefore needs:**

1. **Protocol** - a new command (or frame type) meaning "this stream carries
   length-prefixed datagrams to target X", plus relay-side socket handling.
2. **Relay** - every deployed relay must implement it. This is the fleet-wide
   part of the work; a client-only change would talk to relays that do not
   understand it.
3. **Runtime** - a UoT data plane that accepts local UDP and frames it over the
   TCP stream, and selection that requires only the **TCP** probe, so a relay
   that fails the UDP probe stays usable.
4. **Panel** - a per-client (and probably per-forward) option
   `ultra_udp_mode = native | uot | auto`, carried in the lease/policy, with an
   admin/tenant control. `auto` - "fall back to UoT when no candidate in the
   batch is UDP-capable" - is the setting that actually removes this class of
   failure without anyone choosing it.
5. Honest trade-off: UoT adds head-of-line blocking and TCP retransmits. For
   games that is measurably worse than native UDP; it is a fallback, not an
   upgrade.

**Recommendation:** do `auto` plus an explicit per-client override, in that
order, and only after the protocol and relay work lands.

## 3. Still open from the previous report

Grant writes are still ~8/min/forward: forwards 158/159/170/171 are not
native-outbound (`native_outbound_enabled = 0`), so they renew through
`RefreshUltraForwardAsync`, whose only "is it due?" guard needs
`UltraAccess.RenewAfter > now`. With an unusable renewal point the guard never
passes and it re-leases on every config poll (~14 s). The same fallback used for
the native path - trust the expiry of the lease the client is already running -
applies here and is not yet implemented.
