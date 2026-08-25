# Ultra Panel Policy and Ephemeral Line Lease Design

Date: 2026-08-25

Status: Implemented on `codex/ultra-policy-v2`; production canary and signing are deployment-time gates.

## Decision

Ultra remains a direct-client data path. The existing Panel-integrated Ultra
broker remains the only control and policy authority. This design does not add
or involve a slave, sidecar, independent data gateway, new public data port,
or a second traffic relay.

The target path is:

```text
source 171 --authenticated HTTPS material upload--> Panel
Panel --policy and short-lived session authorization--> Linux/Windows Client
Client --direct provider connection--> approved provider line
Client --fixed target forward--> destination IP:PORT
```

The provider IP and temporary account credential may exist in Client memory
while a direct provider connection is being established or maintained. They
must not be persisted in Client configuration, access files, state files,
status files, logs, command lines, registry values, or heartbeat payloads.

If the requirement is that provider information must never be present in Client
memory or visible in a direct packet capture, direct-client Ultra is not the
correct architecture; that requirement needs a server-side traffic relay and
is outside this design.

## Goals

1. Carry Ultra on both Linux and Windows using the same behavioral contract.
2. Keep the existing Panel-integrated broker and direct provider path.
3. Do not expose a general-purpose local or public SOCKS5 listener.
4. Make Panel policy the hard upper bound and hard-stop authority for:
   - rate limit;
   - traffic quota;
   - service and forward state;
   - package expiry;
   - session and device revocation.
5. Keep line information ephemeral and memory-only during an active session.
6. Close all Client-side traffic, processes, sockets, and temporary session
   state when a forward is stopped, revoked, expired, or suspended.
7. Preserve credential-free status and accounting telemetry.
8. Raise reverse-engineering cost without claiming impossible protection against
   a local administrator who controls the running machine.
9. Remove `cf_mode` support from both Linux and Windows Clients. A received
   `cf_mode` forward is unsupported, is never rendered, and causes any
   previously applied Client runtime for that forward to be stopped and
   cleaned during reconciliation.

## Non-goals

- No slave or sidecar in the Ultra topology.
- No new standalone Gateway process or listener.
- No `18443` or other hidden data/configuration port.
- No client-facing SOCKS5 server.
- No attempt to hide a direct provider IP from a packet capture on the Client.
- No guarantee that a local administrator cannot inspect process memory.
- No change to supported `current_mode`, `leigod_mode`, or their existing
  topology.
- No removal of Panel, provisioning, sidecar, migration, or historical
  compatibility logic for `cf_mode`; the removal boundary is Client runtime
  support only.

## Existing Implementation Gaps

The current Panel-integrated contract is functionally usable but violates the
new persistence and policy requirements in several places:

| Area | Current behavior | Required behavior |
| --- | --- | --- |
| Normal grant | `ultra_access` includes `line_ips` | Grant contains policy and opaque lease handles only |
| Material pull | `/api/client/ultra/materials` returns sealed material containing provider IP, port, user, and credential | Versioned one-time line lease; no persistent material file |
| Linux access file | `/run/ultra/forward-N.access.json` persists the sealed material | Store only policy/session metadata; provider material stays in memory |
| Runtime status | Status includes `relay_ip` and line-derived selection data | Status contains only opaque selection reference, zone, RTT, and state |
| Linux rate policy | Go limiter applies policy in an unmodified process | Add a signed policy generation and hard quota bucket; lower policy replaces immediately |
| Traffic quota | nft counters are reported periodically; suspension follows a later Panel update | Use bounded quota leases and stop when the local budget is exhausted or revoked |
| Linux expiry | An expired runtime can exit under a unit with `Restart=always` | Expiry is terminal for that session; no restart without a fresh valid policy |
| Windows usage | Heartbeat/state types exist, but Ultra counter production is incomplete | Shared runtime emits monotonic Ultra counters consumed by heartbeat |
| Windows stop | Process-tree kill is best effort | Job Object/session ownership plus socket and child-process verification |

The implementation baseline is documented in:

- `docs/2026-08-21-leigod-token-broker-contract.md`
- `master/src/Support/UltraMaterialService.php`
- `master/src/Support/LeigodTokenBroker.php`
- `client-agent/install_forward_runtime.py`
- `tools/ultra-client/main.go`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`

### Client `cf_mode` retirement

The Linux and Windows Clients currently contain `cf_mode`-specific runtime,
selection, and health behavior. That behavior must not survive the v2 Client
work:

- Client configuration filtering rejects `backend_mode=cf_mode` before any
  endpoint, sidecar, candidate, or transport builder runs;
- a previously applied `cf_mode` forward is stopped and its listener, process,
  rules, state, and generated files are cleaned on the next successful sync;
- `cf_mode` cannot fall through to `single`, `bond`, `race`, `mptcp`, Ultra, or
  any generic fallback;
- generic plain-TCP bond or CFWG/self-leg helpers may remain only when a
  supported non-`cf_mode` contract still uses them, and must no longer use a
  `cf_mode` dispatch condition;
- Panel and server-side historical `cf_mode` compatibility is not changed by
  this Client-only retirement.

## Control-Plane Contract

### Policy grant

The normal configuration pull returns a versioned policy grant. It contains no
line IP, provider port, line ID, username, password, or relay address:

```json
{
  "protocol": "leikwan-ultra-policy-v2",
  "forward_id": 42,
  "policy_generation": "opaque-generation",
  "session_ticket": "opaque-short-lived-ticket",
  "issued_at": "2026-08-25T00:00:00Z",
  "renew_after": "2026-08-25T00:04:00Z",
  "expires_at": "2026-08-25T00:05:00Z",
  "service_state": "active",
  "transport": "tcp+udp",
  "rate_limit_mbps": 20,
  "quota_remaining_bytes": 1073741824,
  "target_digest": "sha256(...)"
}
```

The signed policy is authoritative over all local defaults and user-editable
settings. A local value may reduce a limit, but may never raise a Panel limit.

### One-time line lease

The existing material-resolution path is replaced or versioned into an
ephemeral lease flow:

```text
POST /api/client/ultra/lease
```

The request is authenticated and binds:

- client ID;
- device ID/session;
- forward ID;
- policy generation;
- one-time nonce;
- detected ingress zone;
- target digest;
- requested transport.

The response contains one currently approved provider candidate and its
short-lived temporary credential. It is delivered over the authenticated HTTPS
control channel and is never written to a persistent Client file. A failed
candidate is discarded before requesting the next candidate. The Client never
receives an unbounded line catalog.

The Panel validates every lease request against current service state, forward
state, enabled line membership, package expiry, policy generation, and device
session. A stale, replayed, cross-device, or cross-forward lease is rejected.

### Revocation and policy updates

Every policy update increments `policy_generation`. The Client must reject a
lease from an older generation. A heartbeat or control response may carry a
revocation instruction. The runtime must act on it before accepting another
local connection.

Panel policy changes are fail-closed:

- inactive service or forward: stop the forward;
- expired package: stop the forward;
- traffic suspension: stop the forward;
- exhausted quota lease: stop accepting bytes and close active connections;
- revoked device/session: stop all associated Ultra forwards;
- failed renewal after lease expiry: stop the forward.

## Client Runtime Contract

### Local interface

Ultra remains a fixed-target forward, not a SOCKS5 server:

- default bind is loopback;
- public bind requires an explicit allowlisted policy;
- the listener accepts raw target traffic only;
- SOCKS5 greeting, dynamic CONNECT, arbitrary destination selection, and
  unknown protocol negotiation are rejected;
- the target is the Panel-signed forward target digest.

### Session memory

Provider line information may be held only in process memory for the lifetime
of the current lease/connection. It must not appear in:

- command-line arguments;
- environment variables;
- JSON/YAML/TOML runtime files;
- Windows registry;
- logs or crash diagnostics;
- status files;
- heartbeat or admin HTML;
- persistent Client state.

The implementation should pass the lease through an inherited pipe, Unix
domain socket, Linux `memfd`, or Windows named pipe. It must not create a
credential-bearing temporary file.

Provider passwords must be cleared from temporary buffers immediately after
the provider handshake where the protocol permits. Provider IP/port data may
remain only as long as the active socket requires it. Stop, expiry, revoke, and
connection teardown must clear the session object and close all sockets.

### Policy enforcement

The runtime has two independent bounded controls:

1. A byte-rate bucket with the Panel-provided hard ceiling.
2. A byte-quota bucket backed by the Panel-provided remaining quota lease.

Both inbound and outbound application data pass through these controls. A
provider-side limit may reduce throughput but may never raise the Panel cap.
Policy generation changes replace the buckets atomically; active connections
are drained only when the policy allows it, and are closed immediately for
expiry, revocation, suspension, or quota exhaustion.

### TCP and UDP lifecycle

- Each TCP connection owns a cancellable provider stream.
- Each UDP association has an explicit association ID and owner mapping; no
  global `lastClient` pointer is allowed.
- Reload and policy changes coalesce; they must not create an unbounded task
  queue.
- A runtime-wide cancellation context closes listeners, provider streams,
  UDP sessions, probe operations, and status writers together.
- A failed lease does not start a fallback provider path or legacy mode.

## Linux Design

Files in scope:

- `tools/ultra-client/main.go`
- `tools/ultra-client/status.go`
- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `client-agent/tests/test_ultra_*.py`
- `tools/ultra-client/*_test.go`

Required changes:

1. Remove persistent `sealed_material` consumption from the access-file path.
2. Add the one-time lease client and an in-memory session store.
3. Add policy generation, quota budget, and lease-expiry enforcement.
4. Replace the global UDP client association with bounded per-association
   state.
5. Add SIGTERM/SIGINT cancellation and wait for all runtime goroutines.
6. Make expiry/revocation terminal until a fresh policy is received.
7. Render Ultra units with `KillMode=control-group`, `Restart=on-failure`,
   `LimitCORE=0`, a dedicated non-root user, and an `ExecStopPost` cleanup.
8. Delete credential-bearing session state and line-bearing status fields on
   stop.
9. Keep nft counters as an independent Linux accounting signal, but do not
   double-count runtime counters in the Panel billing delta.
10. Build a PIE, stripped, reproducible ELF with no source paths or debug
    artifacts.

The Linux implementation must treat a valid Panel policy as necessary but not
sufficient: the lease, generation, service state, and quota must all be valid
before a provider connection is opened.

## Windows Design

Files in scope:

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeartbeatService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/HeartbeatPayload.cs`
- `scripts/build-windows-client-setup.ps1`
- `scripts/windows-client-install-helper.ps1`

Required changes:

1. Add the Ultra policy contract to the existing forward model.
2. Use the shared Go Ultra runtime rather than duplicating provider protocol
   logic in C#.
3. Pass one-time leases through a protected pipe/handle, never arguments or
   persistent JSON.
4. Add a Windows Job Object per forward so stopping or revoking the forward
   terminates every child and helper process.
5. Add runtime byte counters and monotonic heartbeat deltas for Ultra.
6. Apply policy changes before the normal health-check path; do not wait for a
   20-30 second health cycle to stop an expired forward.
7. Clear lease/session buffers on stop and delete any temporary IPC endpoint.
8. Remove PDBs from release packages and sign the app, helper, and installer.
9. Add a release gate that compares source commit, package contents, hashes,
   and Authenticode signatures.

## Accounting Contract

The Panel remains the billing authority. Client reports are observational input
that must be bounded and idempotent, not an authorization to continue service.

Each heartbeat carries, per client session:

- monotonic sequence number;
- cumulative Ultra bytes in/out or a strictly ordered delta;
- policy generation;
- running/stopped state;
- credential-free selection reference;
- no provider IP, port, username, password, line ID, or raw material.

The Panel rejects duplicate and regressing sequences. The existing service
traffic tables remain the accounting sink. Once the Panel marks a service
`traffic_suspended`, the next policy response must revoke the active generation
and the Client must stop; a heartbeat-only suspension is not sufficient.

For exact quota enforcement, the Client cannot receive an unlimited offline
budget. A bounded quota lease is renewed before exhaustion. Failure to renew
causes a stop when the lease expires or the local budget reaches zero.

## Stop and Cleanup Invariant

For every stop reason, the following must be true before the runtime is reported
stopped:

```text
local listener closed
all provider TCP sockets closed
all provider UDP sockets closed
all probe/reload goroutines stopped
all child processes stopped
all lease/session memory released or zeroed where possible
no credential-bearing temporary file exists
no line-bearing status file exists
nft/accounting rules removed or marked inactive
```

Linux verification uses `systemctl`, `ps`, `ss`, `/proc`, and bounded filesystem
inspection. Windows verification uses Job Object membership, process snapshots,
socket enumeration, and install/state directory inspection.

The system must not claim stopped while a provider socket or child helper is
still alive.

## Reverse-Engineering Boundary

The design provides practical resistance:

- no provider material in the package or persistent configuration;
- no provider material in command lines or logs;
- short-lived device/forward-bound tickets;
- one-time lease nonces and policy generations;
- Linux PIE/RELRO/strip/trimpath and disabled core dumps;
- Windows signed binaries, no PDB distribution, and controlled release
  metadata;
- fail-closed expiry and revocation.

It does not provide cryptographic secrecy from a local administrator. A local
administrator can inspect a live process, intercept a direct provider
handshake, or modify the Client. Any stronger requirement requires moving the
provider connection behind a server-side data relay, which is explicitly out
of scope here.

## Migration Plan

1. Add the v2 policy and lease schema/contract without changing legacy modes.
2. Keep v1 material grants readable only for a bounded migration window; do not
   issue new v1 grants to upgraded Clients.
3. Ship Linux runtime and agent changes with the v2 lease flow disabled by
   default behind an Ultra capability flag.
4. Ship the shared Windows helper and runtime lifecycle changes.
5. Run a single-forward Linux canary, then a Windows canary, covering TCP and
   UDP.
6. Enable v2 per client/forward after policy, accounting, and cleanup evidence
   passes.
7. Revoke v1 grants, remove old access files, and remove line-bearing status
   fields from persisted state.
8. Roll back by disabling v2 issuance and stopping affected Ultra forwards;
   never restore long-lived provider material to Client as an automatic
   fallback.
9. Remove Linux and Windows `cf_mode` Client dispatch before enabling the final
   Ultra v2 rollout. Rollback must not re-enable `cf_mode` on either Client.

## Acceptance Matrix

### Contract and storage

- Config pull contains no line IP, line ID, provider port, username, password,
  or sealed material.
- No credential-bearing file is created during lease acquisition.
- Disk, registry, command-line, environment, log, status, and heartbeat scans
  contain no provider credential or raw line info.
- Status contains only opaque selection references and bounded operational data.

### Policy precedence

- A Panel rate limit lower than the provider limit is enforced in both
  directions.
- A local attempt to raise the rate is ignored or rejected.
- Quota exhaustion closes active connections and prevents new ones.
- Expiry, service suspension, forward disable, and device revocation stop the
  runtime without waiting for a normal health restart.
- A provider-side lower limit may reduce throughput but cannot raise the Panel
  limit.

### Lifecycle

- Manual stop leaves no listener, provider socket, helper process, or credential
  file.
- Policy revoke produces the same cleanup result as manual stop.
- Restart after expiry fails until a fresh valid policy and lease exist.
- UDP responses cannot be delivered to a different local association.

### Accounting

- Linux and Windows Ultra byte counters reach the existing Panel heartbeat.
- Replayed heartbeat sequences do not double-count traffic.
- Traffic suspension from the Panel stops the Client on the next policy action.
- Reported status never contains raw line information.

### Direct-path boundary

- No local SOCKS5 listener is present.
- A controlled packet capture may show the direct provider IP and provider
  handshake; this is documented behavior, not a secrecy failure under this
  design.
- Reverse engineering can recover live session material only by inspecting the
  running Client; this residual risk is documented and accepted.

### Client backend retirement

- Linux rejects a received `cf_mode` forward before runtime rendering.
- Windows filters a received `cf_mode` forward before runtime dispatch.
- A previously running `cf_mode` forward is stopped and fully cleaned on the
  next reconciliation cycle.
- No Client-side `cf_mode` fallback, endpoint selection, command generation,
  health probe, or status path remains executable.
- Supported `current_mode`, `leigod_mode`, Ultra, and generic supported
  single/race/bond/MPTCP contracts continue to pass their regression suites.

## Rollback and Operational Safety

The implementation must preserve supported `current_mode`/`leigod_mode` paths
while deliberately retiring `cf_mode` on Linux and Windows Clients. Before
each deployment:

- snapshot Panel routes, migrations, artifact hashes, and Client state paths;
- verify no slave or sidecar component is included in the Ultra diff;
- deploy Panel contract changes before enabling Client v2 issuance;
- use one forward/client canary before expanding rollout;
- retain a credential-free stop and listener audit artifact;
- preserve unrelated dirty worktree changes and stage only owned files.

Rollback stops v2 Ultra forwards and disables v2 lease issuance. It does not
re-enable persistent provider material or silently fall back to a legacy
provider path.
