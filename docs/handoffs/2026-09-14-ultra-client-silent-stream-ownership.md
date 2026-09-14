# Who owns the silent-stream decision in the ultra client (traced 2026-09-14 ~18:40 UTC)

Traced with `.codegraph/codegraph.db` (nodes/edges), then read the code. Recorded because
any change to greeting/fallback behaviour has to land in the right layer, and this trace
shows the wrong layer is the obvious one.

## The owner

**`noteRelayOutcome` — `tools/ultra-client/ha_tcp.go:132-161`.** Called from exactly one
place in production: `relayTCP` (`ha_tcp.go:163`), at `:223`, after BOTH copy goroutines
have finished. Every other incoming edge is a unit test. `no_downstream_bytes` appears
nowhere in `tools/ultra-client` except here (the log line at `:158-160`) and in a test name.

## The call path

```text
serveTCP (main.go:645)
  -> dialStream (main.go:517)          # establishes the stream; knows nothing about bytes
  -> relayTCP  (ha_tcp.go:163)         # owns the stream lifecycle
       two copy goroutines, counts upstreamBytes/downstreamBytes,
       stamps each direction's end time (:199-208)
       -> noteRelayOutcome(...) at :223
```

## What it does today when a handshake succeeds and the stream carries nothing

Decision order (`ha_tcp.go:137-157`):

1. `downstreamBytes > 0` -> `ClearPathFailure` and RETURN. Any delivered byte means healthy;
   both the isolation and the escalation streak are forgotten.
2. `lifetime > haDeadStreamMaxAge` (**3 s**) OR `upstreamBytes == 0` -> RETURN, no strike.
   Nothing was asked of the relay, or the stream lived long enough that silence is not yet
   evidence.
3. If ordering was measured and `!remoteStoppedFirst()` -> RETURN, no strike. **A local
   abort is explicitly not evidence about the relay** — the 2026-09-14 fix that had
   quarantined healthy paths; `haRelayOrderEpsilon = 25 ms` (`:81`).
4. Otherwise `FailSilentPath(...)`, which quarantines the path only after
   `pathIsolationMinStrikes` = **2** consecutive silent streams
   (`ha_candidates.go:89`, `:103-107`). **One strike never isolates a path.**
5. On a strike: `RecordCandidateFailure(..., "tcp_no_response", ...)`, `RecordHAEvent`, and
   the log line `reason=no_downstream_bytes`.

## Why a dial-time greeting fallback cannot be driven by "no downstream bytes"

`dialStream` runs **before** `relayTCP`, and `relayTCP` is what owns the byte counters. When
`dialStreamWithGreetingOrder` returns, no payload has flowed in either direction and no copy
goroutine exists. The bytes-based decision is structurally downstream of the dial, so a test
asserting that "a normal return with zero downstream payload triggers the next greeting"
cannot be written at that layer — there is no payload to observe. An unfalsifiable test
would be worse than none.

## Consequence for any fix

The HA layer **already implements** the principle "judge by bytes arriving, not by the
handshake succeeding", and it already refuses to be fooled by local aborts. Two options:

* **Leave it.** Silent paths are already quarantined after 2 strikes, which is the same
  outcome a greeting fallback would be trying to reach.
* **Redesign**, so that a path quarantined here is retried with the OTHER greeting on the
  next dial. That is a policy change in `ha_candidates.go` (a greeting hint on the path
  record), **not** a fallback in `main.go`. Two owners for one policy is the thing to avoid.

A greeting-level fallback driven by silent streams would be a second owner for a policy the
HA layer already owns.

## Pre-existing test failure (not attributable to this work)

`TestServeNativeSocks5ConnectRoundTrip` fails with *"native SOCKS TCP port 62670 did not
become ready"*. It failed on the first baseline run of `go test ./...` before any change was
made to that package, and no file in `tools/ultra-client` was modified by this work.
