# Enterprise UoT candidate and acceptance harness

The transport is included in the 2026.09.19.1 desktop/Linux release.
Acceptance is run-specific, not a universal zero-loss claim. See
`docs/handoffs/2026-09-19-linux-windows-release-deployment.md` and the
macOS release report for native installation and traffic evidence.

**Required route for every UDP test:**
`client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`.
Never substitute a direct UDP baseline or native-provider UDP fallback. In this
document `raw` is a delivery-semantics label *within UoT*, not a route choice.
Before acceptance, verify `external_uot.enabled=true`, the expected sidecar
target, the active runtime identity, and matching sidecar session counters.
Fail the acceptance gate if any of those facts is unavailable.

Run the secret-free local preflight against the actual generated runtime config:

```sh
python check_uot_route.py --config RUNTIME_CONFIG.json --expected-sidecar SIDECAR:PORT
```

`CONFIG_PASS` is configuration evidence only, not data-plane acceptance.

## Modes

| `external_uot.delivery_mode` | Meaning |
| --- | --- |
| absent / `raw` | Legacy best-effort UDP; explicit queue timeout/drop counters, no delivery guarantee |
| `reliable_datagram_v1` | Negotiated in-memory sequence/ACK/replay between client and sidecar |
| `guaranteed_delivery` | Rejected: requires an owned destination application-ACK adapter, not implemented here |

Do not send ACK extensions to an unnegotiated legacy peer. A client requesting
reliable mode fails closed if the peer does not advertise the same mode. A raw
reattach cannot downgrade an existing reliable session.

Desktop hosts read sidecar grants both from leases and from the exact
`configs/pull` policy scope. Policy-only grants are held in memory, cloned per
lease, checked against forward/generation/target/expiry, and zeroed on
replacement/disposal. An enabled malformed lease grant fails closed. No grant
is persisted with the forward. Raw bootstrap omits the new `delivery_mode`
key for legacy strict JSON decoders; every non-raw mode remains explicit.
Deploy the matching runtime before enabling reliable mode.

Lease renewal uses the deadline of the last successful runtime delivery,
not just the newest desired policy. Targeted recovery of a broken sibling
must not indefinitely skip another forward whose installed lease is due.
The desktop regression suite covers that starvation case with a controlled
clock, plus policy-grant inheritance across an HTTP 304 response.

## Forward-local operator quarantine

An operator can temporarily exclude measured failing candidates using
`candidate_quarantine_hashes` (up to 64 lowercase SHA-256 identifiers) and
`candidate_quarantine_until` (RFC3339, at most 24 hours ahead) in the private
runtime bootstrap. `candidate_quarantine_protocol` is `tcp+udp` by default,
or explicitly `tcp` for user TCP only. Unset fields preserve existing behavior.
The expiry is authoritative for new stream selection and lease delivery;
status flags describe the last lease-admission snapshot.

Full-protocol quarantine narrows validated grants before probing and fallback
admission, wipes excluded credentials, and fails control delivery explicitly
with `operator_quarantined` if no candidates remain. TCP-only quarantine
retains those grants for the independent UoT pool while excluding them from
both TCP dialing and active-path rescue. It never selects an unapproved relay
or native UDP. Status reports the protocol, active flag, expiry and excluded
candidate count.

UoT uses **TCP on the wire but UDP health/selection state**. Its HELLO success
does not clear user-TCP failure state or overwrite the user-TCP path display.
The test environment demonstrated why this distinction matters: excluding a
TCP-failing candidate from both protocols also removed a working UoT path.
The failed full-protocol experiment is retained, not reported as a pass.

This is a startup configuration, not a seamless hot-reload/failover API.
Observe it in local status and keep an independent rollback. A benchmark with
quarantine enabled is **conditional on that restricted candidate pool**:
retain unrestricted failures and disclose the excluded hash and expiry.
Do not report it as proof that the excluded upstream, arbitrary candidate
selection, or simultaneous fleet capacity has been repaired.

Reliability stops at a **successful local UDP socket write**. It does not prove
that the local application or the remote UDP target consumed the datagram.
State is not durable across process exits. Lost peer session state fails the
reliable association rather than silently starting a new sequence epoch.

The send window is limited to 4,096 packets / 16 MiB, with cumulative ACK and a
64-bit selective ACK bitmap. Selective ACK suppresses retries but does not free
the retained payload; only cumulative delivery ACK does. Reconnect replays
retained packets, and the receiver deduplicates before UDP delivery. Retry
limits are 12 attempts, a 1-second retransmission interval, and a 30-second
oldest-frame budget. Exhaustion is explicit failure.

Raw queue admission waits at most 250 ms; reliable send-window admission waits
at most 5 seconds. Both respect an earlier caller cancellation/deadline. Raw queue
defaults are 4,096 packets with a 16 MiB byte budget. Existing explicit packet
limits remain explicit. These limits are per association, **not a process-wide
memory admission controller**.

Socket buffers request 16 MiB and report effective kernel values. Linux often
reports twice the requested value. Linux per-socket receive drops are sampled
from the socket inode in `/proc/net/udp{,6}`. Unsupported measurements are `null`,
not zero; macOS/Windows per-socket receive-drop instrumentation remains open.

## TCP diagnostics

The candidate retains the first and last connection-setup failures, along with
the terminal error class. Logs carry candidate, lease, relay-path, and target
hashes without credentials. Existing candidate isolation and bounded setup
attempts remain in place. No application-payload replay, unlimited retry, or
HTTP-response rewriting was added.

These diagnostics do not locate arbitrary encrypted application HTTP failures.
The owned HTTP control target and per-request acceptance events classify
application failures separately. An auth ACK immediately followed by EOF/reset
is now a failed handshake, not a usable stream: bounded candidate fallback
happens before consuming caller bytes. Candidate retries include 10–50 ms
jitter within the existing setup deadline. Provider pool capacity still needs
separate measurement; this change does not claim a provider concurrency limit.

Runtime `reliable_udp` counters aggregate all live and retired associations.
The sequence fields are therefore sums across associations, not one session's
wire sequence. Window gauges cover live associations only. A low-volume health
probe cannot overwrite a busy association's evidence or erase a past failure.

Desktop grant parsing carries `delivery_mode` into bootstrap and includes it in
the runtime contract signature, so changing the mode requires restart. An
explicit unsupported mode is preserved for fail-closed rejection by the new
runtime rather than silently discarding the entire sidecar grant. Deploy the
host and runtime together; old runtimes cannot enforce new mode semantics.

## Harness

Build the single standard-library Go file for the receiving platform:

```sh
go build -o uot-acceptance ./main.go
```

Create a random test token file containing at least 32 characters, privately copy
it to the owned target and receiver, and protect it with mode `0600`. Do not put
the token on a command line or in a repository.

Owned target (automatically exits at its TTL):

```sh
./uot-acceptance --server --listen :60042 --token-file test-token.secret --ttl 1800
```

Receiver:

```sh
./uot-acceptance --target OWNED_TARGET:60042 \
  --proxy 127.0.0.1:10001 --token-file test-token.secret \
  --duration 720 --workers 128 --bps 20000000 --out summary.json
```

TCP and UDP each request 20 Mbps concurrently (40 Mbps combined). The listener
uses Shadowsocks `none`, address-first framing; that wire format does not use
the configured SS password to encrypt frames. HTTP response bodies are checked
for exact length and SHA-256. UDP uses per-run identifiers and sequence numbers,
an idempotent control refresh every 10 seconds, a sender completion count, and
server-side sent/error receipts. Record the binary hash and harness revision:
the initial baseline harness lacked sender receipts and periodic refreshes, so
its missing-versus-requested count cannot establish the location of loss.

The harness is a test tool, not an exposed public service. TCP has a header
deadline and body write deadline; UDP is capped at 20 Mbps per test, 720 seconds,
eight active streams, 256 remembered run identifiers, and a server lifetime.
The endpoint token is authorization for this bounded test, not a product auth
scheme.

## Verification

```sh
python tools/uot-acceptance/verify_shared.py
cd tools/uot-sidecar
go test -race -count=1 ./...
cd ../ultra-client
go test -race -count=1 ./...
go test -run '^$' -bench 'BenchmarkReliableEnqueueACK|BenchmarkRawBudgetEnqueue' -benchmem
```

The existing game simulation writes to `../../.tmp/game-sim-local.md`; create
that scratch directory in sparse worktrees before running the full suite.

## Deployment gate

Do not manually point an agent-managed service at an unrelated canary binary
path. The Linux agent checks the running inode against the canonical installed
binary. Merely replacing the canonical binary is also insufficient: the updater
restores the published artifact. For a temporary Linux canary, the existing
root-local `LEIKWAN_UPDATE_HOLD_UNTIL` mechanism can defer updates for a bounded
period (at most 24 hours); verify the deployed agent supports it, keep the
canonical path, install an independent timed rollback, and remove the hold
after the test. This does not replace a proper canary artifact channel with
matching desired-state metadata, host configuration delivery, and rollback.
Do not disable attestation or forge a published artifact identity.

Size the sidecar session limit for real application associations **plus health
checks and reconnect grace**. The initial 16-session test cap was exhausted by
periodic probes with distinct UDP source ports; that is an admission failure,
not evidence of reliable-transport packet loss. The later scoped canary uses
128 sessions with a 768 MiB service memory ceiling. The ceiling is a crash
containment boundary, not a substitute for process-wide admission control.

FEC is not implemented or benchmarked. No throughput or CPU advantage is
claimed. First establish a stable ACK/replay baseline; any future FEC comparison
must record useful throughput, repair traffic, latency, CPU and memory at the
same offered load and loss profile.
