# Ultra UoT (UDP over TCP) - implementation plan

Status: **plan only, nothing implemented.** Written 2026-09-13 after the
probe-failure work, to answer "let a user choose UoT so it does not matter
whether a relay answers UDP".

## Why

Roughly a fifth of the candidate relays accept the TCP control connection and
then close the datagram handshake (`datagram handshake: EOF`). Today that makes
the relay unusable for anything UDP. With UoT the UDP payloads ride the relay's
**TCP stream**, so those relays become usable again.

## What exists today

* Handshake commands: `1` = byte stream, `3` = datagram (UDP) association.
  Command 3 carries **real UDP datagrams**; the TCP connection is only the
  control channel. There is no length-framed datagram transport on either end.
* `transport = "tcp"` is **not** UoT: it makes the runtime serve TCP only, so a
  UDP game stops working.
* `usableFor(transport)` already decides pass/fail from the probe results:
  `tcp` needs `tcpUsable`, `tcp+udp` needs both. A UoT mode mostly means
  `tcpUsable` is enough **and** UDP payloads have somewhere to go.

## Phase 0 - protocol spec (0.5d)

* New command `4` = "UoT association": same envelope as command 1, but the
  stream carries framed datagrams: `[u16 length][payload]`, and a length-0
  frame with a reason code for errors.
* One association = one target `host:port`; the relay binds a UDP socket to the
  target and pumps both directions. Single-peer only in v1 (matches the native
  datagram model); multi-peer is a separate decision (P2P games).
* Idle timeout, half-close, max payload (65507 minus envelope - reuse
  `datagramEnvelopeSize = 16` if we want one parser).
* **Version skew**: an old relay must fail command 4 cleanly; the client treats a
  failed arm as a candidate failure and falls back. This is what makes the
  fleet rollout safe.
* Deliverable: `docs/superpowers/specs/2026-09-14-ultra-uot-protocol.md`, reviewed
  before any code.

## Phase 1 - relay (1-2d, the long pole)

* **First task: locate the relay source and its deploy path.** The client repo
  only has `tools/ultra-client/tokyo_sim/relay`; the production relay binary and
  its rollout are outside this repo as far as I can see. This blocks sizing.
* Implement command 4 + the framing + the UDP pump.
* Tests: framing round trip; truncated/oversized frames rejected; idle timeout;
  unknown command still rejected exactly as before (no regression for old
  clients).
* Parity: extend `tokyo_sim/relay` so the runtime's UoT data plane can be tested
  end to end without a real relay.

## Phase 2 - runtime, `tools/ultra-client` (1-2d)

* New data plane `serveUoT` accepting local UDP (SOCKS5 UDP ASSOCIATE and the
  native facade) and framing it onto a command-4 stream.
* `Policy` gains `udp_mode` (`native` | `uot` | `auto`), validated like
  `transport`.
* Selection: in `uot` mode a candidate is usable when `tcpUsable` alone; in
  `auto` prefer a candidate that passes both and fall back to UoT only when no
  candidate is UDP-capable.
* Status/evidence: `active_udp.transport = "uot"`, and `uot_arm_failed` /
  `uot_stream_failed` reported through the new probe/failure sink as transport
  reasons (never failure strikes - same rule as this week's change).
* Tests: selection matrix (UDP-dead candidate + `uot` -> selected; `auto`
  prefers native; `native` never picks a UDP-dead candidate), framing round trip,
  fallback behaviour when command 4 is refused.

## Phase 3 - panel (1d)

* Setting: `clients.ultra_udp_mode` plus a plan default
  (`service_plans.default_ultra_udp_mode`), surfaced on the tenant client page
  and the admin client detail.
* Lease: `UltraLeaseService::issue` carries `udp_mode` in the lease frame;
  `transport` semantics stay as they are.
* Candidate resolution: never hand a UoT-only candidate to a `native` client; in
  `auto`, prefer UDP-capable candidates.
* **Capability gate**: never send `udp_mode = uot` to a runtime whose
  fingerprint predates UoT support. This mirrors the `reviews_capacity`
  handshake that keeps old source agents from being handed work they cannot do.
* Refusal code `uot_unsupported` instead of a silent downgrade when the client
  explicitly asked for UoT and the line cannot do it.
* Usage accounting: `ultra_usage_events` must count UoT bytes; a UoT stream is
  no longer distinguishable from a TCP stream at the relay, so the framing has to
  carry the protocol tag.
* Tests: lease frame carries the mode; `auto` preference; capability gate;
  refusal code.

## Phase 4 - rollout and verification (1d+)

* Capability discovery per line/relay (source-agent material probe) so `auto`
  only picks relays that actually speak command 4.
* Canary on one client + one line: measure RTT/jitter/loss and confirm the
  `datagram handshake: EOF` class disappears **for that client**; compare against
  native on the same line.
* Then the relay fleet, then flip the default to `auto` (keep `native` as the
  default until the canary is clean).
* Rollback: the panel setting alone moves a client back to `native`.

## Trade-offs to state plainly

* UoT means head-of-line blocking and TCP retransmits. **For games it is worse
  than native UDP** - it is a fallback for relays that cannot do datagrams, not
  an upgrade.
* Two-end change, so version skew is normal and must degrade cleanly.
* Quota/abuse accounting gets harder: UDP stops being visible as UDP at the
  relay unless the framing says so.

## Effort

~5-8 focused days end to end: spec 0.5, relay 1-2, runtime 1-2, panel 1,
canary+rollout 1 - **plus** the time to upgrade the relay fleet, which depends on
the answer to question 1 below. Windows/macOS/Android runtimes each need their
own build and release, exactly like this week's runtime.

## Open questions

1. **Where is the relay source and how is it deployed?** Phase 1 cannot be
   estimated properly without it.
2. Default: `auto` for everyone once the fleet is ready, or opt-in per client
   first?
3. Is single-peer UDP per association enough, or is multi-peer needed?
4. Should UoT be per-client, per-forward, or both?
