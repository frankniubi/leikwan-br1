# Linux Hybrid LeiGod Aggregation Design

Date: 2026-08-06

Status: approved for implementation

Baseline: `codex/sidecar-shared-wg-peers` at `e8ce5cc`

Related evidence:
`docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`
section 13.

## 1. Decision

Keep the control-panel and user-facing mode unchanged as `bond`/aggregation. On capable Linux
clients, the existing `client-agent` manages one product-owned system daemon, `leikwan-pathd`,
which combines client-local LeiGod paths and slave-hosted LeiGod paths into one flat bond session.

The selected topology is deliberately independent of `hk-bgp-b` and its sidecar domain. A
LeiGod-specific egress aggregator terminates bond legs and opens the one real target connection.
It is not the P4 benchmark shape and it is not selected through a sidecar point.

The user approved these operating constraints:

1. Linux is implemented first. Windows and Android behavior remains unchanged in this release.
2. A physical LeiGod line is leased exclusively to one execution location at a time: one Linux
   client device or one slave node. Multiple lanes and forwards may share it at that same location.
3. Client-local and slave paths may participate in the same bond concurrently.
4. The panel dynamically grants revocable, device-scoped leases instead of bundling a permanent
   shared client configuration.
5. Root can inspect a customer-owned Linux machine. Provider-neutral normal UX is required, but
   the design does not claim root-proof concealment and does not masquerade as a system process.

## 2. Problem

Release 0.1.34 proved that internal P2 can materially improve one user Reverse P1 stream. Two
physical lines exceeded `200 Mbps` in individual P2 controls, and the product-shaped single-slave
canary reached a `198.372 Mbps` Reverse P1 receiver median. It also proved that connection quality
varies within one physical line, so the second lane improves the probability of obtaining a good
provider allocation but cannot manufacture guaranteed capacity.

Three product gaps remain:

1. One slave currently behaves as one approximately 200 Mbps superleg. The product has not proved
   a transparent 400 Mbps or faster single stream assembled from more than one execution location.
2. Client-local LeiGod paths cannot join the same runtime as slave paths. The Linux renderer
   accepts only legacy client bond or `aggregation_placement=slave_sidecar`, and dedicated LeiGod
   contracts are canonicalized to `single`.
3. Every eligible slave currently receives and starts the desired LeiGod line catalog. That model
   conflicts with exclusive client-local leases and can create concurrent provider sessions whose
   capacity and account effects contaminate each other.

## 3. Goals

1. Preserve the existing control-panel wording, forward mode, user listen address, public slave
   tuples, and target address.
2. Present one ordinary TCP listener to the user and preserve arbitrary TCP stream semantics.
3. Make one Linux daemon aggregate local and slave legs without a second authoritative control
   loop or one daemon per forward.
4. Support Reverse `iperf3 -P 1`; the user must not need `-P 2` or `-P 4` to receive aggregate
   throughput.
5. Reuse the 0.1.34 route-delivery attribution, connection calibration, latency-first selection,
   and adaptive bulk admission rather than adding a competing scheduler.
6. Keep sub-10 Mbps paths out of bulk scheduling while allowing a healthy low-RTT path to carry
   bounded interactive/control traffic and recovery probes.
7. Assign every physical line to exactly one current execution location with TTL, renewal,
   revocation, and a monotonically increasing fencing generation.
8. Detect new tasks promptly through authenticated HTTPS long-poll while retaining ordinary
   periodic pull as the correctness and recovery path.
9. Modify existing LeiGod `single`, `race`, and `bond` publication so all three consume the same
   lease and health authority.
10. Validate locally with serial, bounded-resource commands, then on isolated servers, then deploy
    only committed artifacts and update the durable handoff.

## 4. Non-Goals

- No Windows or Android runtime implementation in this release.
- No user-facing provider name, line credential, lease editor, placement selector, or new mode.
- No direct dependency on `hk-bgp-b`, its DNS name, its sidecar configuration, or Point 10's
  `fixed_endpoint_leigod_bond` and `server_side_leigod_bond` flags.
- No nested client bond over slave bond in the selected path.
- No per-line public listener and no Linux client inbound management port.
- No promise that software can exceed the sum of current provider, slave, egress, and target
  capacities.
- No attempt to prevent a root administrator from identifying binaries, loopback sockets,
  configuration content, or traffic destinations.
- No process-name spoofing, random name rotation, or impersonation of an operating-system service.

## 5. Considered Topologies

### 5.1 Flat Linux hybrid bond (selected)

```text
application
  -> existing local listen host:port
  -> one shared leikwan-pathd
       -> local lease A lane 1 -> local provider SOCKS --------+
       -> local lease A lane 2 -> local provider SOCKS --------|
       -> slave X route B -> authenticated slave ingress ------+-> egress bond aggregator
       -> slave Y route C -> authenticated slave ingress ------|      -> one target TCP stream
       -> additional admitted lanes ---------------------------+
```

Every top-level leg carries a deterministic route identity and terminates in the same remote bond
session. The egress aggregator reassembles upload traffic and splits Reverse traffic. There is one
scheduler and one reassembly layer in each direction.

This gives client-local and slave paths equal scheduling semantics, preserves transparent TCP,
and lets the Reverse sender make per-leg delivery decisions where the bytes originate.

### 5.2 Hierarchical slave superlegs plus client bond

Each slave would retain its internal bond and expose an approximately 200 Mbps superleg. A client
bond would then combine local paths and multiple superlegs. This reuses more of release 0.1.34 but
adds two scheduling layers, two reassembly queues, compounded retransmission, and head-of-line
risk. It remains a diagnostic fallback, not a production placement.

### 5.3 Connection race without bond

A local dispatcher could choose the fastest local or slave location for each new application
connection. This needs no egress aggregator and remains useful for `race`, but one arbitrary TCP
stream stays limited by the chosen path. It cannot satisfy the 400 Mbps single-stream objective.

## 6. Components And Ownership

### 6.1 Panel lease broker

A new `LeigodExecutionLeaseService` is the only allocator for physical LeiGod lines. The catalog
continues to own configuration, combination membership, and probe metadata. The lease service owns
where a ready configuration is allowed to execute now.

One row per catalog line is stored in `leigod_execution_leases`:

```text
leigod_line_id          primary key and foreign key
lease_id                opaque random identifier
owner_type              client_device | node
owner_id                client id or node id
owner_device_id         required for client_device, empty for node
generation              monotonically increasing fencing value
max_parallel_lanes      bounded 1..4, default 2
state                   pending | active | revoking
issued_at
ready_deadline_at
expires_at
last_renewed_at
updated_at
```

The single row is the exclusivity constraint. Allocation and reassignment use a transaction with
`SELECT ... FOR UPDATE`. An expired or revoked row may be overwritten only after incrementing
`generation`. Agents include `lease_id` and `generation` in health and readiness reports; stale
reports cannot reactivate or renew a newer owner.

The lease owner is an execution location, not one forward. Multiple forwards at the same owner may
reuse its one loopback SOCKS runtime, and a bond may create up to `max_parallel_lanes` connections
through it. The same physical line cannot run concurrently on a client and a slave or on two
different slaves.

Allocation is sticky and non-preemptive for a healthy active owner. A client-local request uses
only an unleased/expired line or a line explicitly released from its previous location; it does
not steal a live slave lease serving other forwards. Per-line admin policy controls whether a line
is eligible for `client_device`, `node`, or both, but no new tenant-facing control is added.

The catalog adds backward-compatible operator fields `allow_node_execution` (default true),
`allow_client_execution` (default false), and `max_parallel_lanes` (bounded 1..4). Migration alone
does not change desired line management. Lease enforcement is activated separately only after an
audit/seed command has created matching node leases for every currently managed production line.
An incomplete or conflicting seed aborts without enabling the gate.

### 6.2 Readiness and health

Lease activation is two phase:

1. The broker creates a `pending` lease with a bounded ready deadline.
2. The owner receives the configuration, starts the line runtime, and reports lease-scoped health.
3. A matching healthy report moves the lease to `active`.
4. A missed deadline, configuration hash mismatch, explicit stop, or repeated failure moves it to
   `revoking`, removes it from new contracts, and eventually makes it allocatable again.

Existing `node_leigod_line_health` remains the slave observation source but gains lease identity.
Client heartbeat accepts the same bounded health shape under a device-scoped field. Selection uses
only fresh health whose configuration hash, lease ID, generation, and owner all match.

### 6.3 Linux `leikwan-pathd`

The client installs one manifest-pinned Rust binary at `/usr/local/bin/leikwan-pathd` and one
systemd service named `leikwan-pathd.service`. The binary has its own product-owned executable and
process name; it is not an `exec -a` alias for another system process.

The daemon owns all Linux hybrid aggregate listeners and sessions. Its desired state is one
root-only atomic generation file under `/run/leikwan-pathd/`. `client-agent` writes a complete new
generation and sends `SIGHUP`. The daemon validates the whole generation before changing live
state, retains the previous generation on error, adds/replaces listeners before removing obsolete
ones, and publishes bounded status snapshots for heartbeat collection.

Client-local provider configuration is delivered only in an authenticated device-scoped lease
projection. It is written with mode `0600` under `/run`, never copied into the repository, normal
logs, handoffs, or user-facing panel JSON. Provider child processes remain in the path daemon's
systemd cgroup and are stopped before the lease is acknowledged as released. Their loopback SOCKS
ports are collision-checked and never bind a public address.

The daemon reuses the existing bond frame/session/scheduler implementation and authenticated
slave-ingress transport. Shared Rust modules should be extracted from the existing crates where
needed; protocol parsing and scheduling must not be duplicated.

### 6.4 Slave runtime

The slave agent's desired line management changes from the full eligible catalog to its current
node-owned leases. It stops a line after lease revocation and reports the matching lease fence when
the line is healthy.

For `aggregation_placement=linux_hybrid`, the slave runs the existing loopback bond-mode LeiGod
relay but does not start the slave-local `leikwan-bond client`. The authenticated public ingress
forwards route-tagged client legs to that relay. The relay permits only route IDs backed by active
leases owned by this node and dials the shared egress aggregator target through the selected local
SOCKS runtime.

The existing `slave_aggregate` placement remains available for old Linux agents and rollback. It
uses the same leases, catalog, and health authority, so enabling hybrid support does not retain a
second unleased LeiGod execution path.

### 6.5 Egress aggregator

The selected egress is a LeiGod-specific controlled endpoint running the existing authenticated
`leikwan-bond server`. It is configured independently from sidecar points and dials the unchanged
forward target from token claims. The panel selects it from an internal egress profile associated
with the LeiGod route; no sidecar point flag or hostname is reused.

`leigod_egress_profiles` stores a stable profile key, region, host, TCP port, enabled state, and a
secret reference. The raw token secret remains in the panel's existing secret/config boundary and
is not stored in the profile row. A nullable `leigod_egress_profile_id` on `forward_routes` selects
the profile. Hybrid publication requires one enabled profile with a resolvable secret; otherwise
the panel publishes the compatible slave placement or fails closed according to the route gate.

For Reverse traffic, the egress is the bond sender and applies the same calibration, interactive,
bulk, quarantine, and probe policy. This is what turns multiple internal connections into one user
P1 stream without requiring P4 at the application.

## 7. Control-Plane Contract

The tenant-facing forward contract remains `mode=bond`, with the same display text and commands.
An authenticated Linux agent that reports the required capability receives an additive internal
runtime object:

```json
{
  "engine": "linux_pathd",
  "placement": "linux_hybrid",
  "generation": 42,
  "egress": {"host": "<internal>", "port": 0, "session_token": "<redacted>"},
  "local_leases": [{"lease_id": "<opaque>", "generation": 7, "max_lanes": 2}],
  "slave_legs": [{"endpoint_id": 1, "leg_route_id": 1001}],
  "policy": {"adaptive_line_admission": true, "throughput_min_mbps": 10.0}
}
```

The exact secret/config projection is server-to-agent only and is excluded from ordinary panel
presentation. Internal PHP and database names remain technically accurate; white-labeling is a UX
boundary, not a source-code obfuscation requirement.

Capability negotiation is fail closed. A Linux agent without a pinned compatible `pathd` artifact
receives `slave_aggregate`. Windows and Android continue receiving their current supported
contract. An unknown engine, placement, lease state, or fencing generation is rejected before the
user listener changes.

The current sidecar-point-derived `fixed_endpoint_leigod_bond` and
`server_side_leigod_bond` checks are not extended for hybrid mode. New rollout gates live in the
LeiGod route/egress policy, and existing gates remain only for backward compatibility and rollback.

## 8. Long-Poll And Reconciliation

The current full pull remains authoritative. The response adds a monotonic client configuration
revision and an ETag. A new authenticated endpoint supports a bounded watch:

```text
GET /api/client/configs/watch?revision=<n>&wait=25
```

`client_config_revisions` contains one row per client. Forward assignment, desired-state, and lease
projection changes increment its unsigned revision in the same database transaction that makes
the change visible. Artifact metadata and other global maintenance changes remain covered by the
periodic full pull and do not require a fan-out revision update.

- It enforces the same client token, device ID, online-device limit, and platform scope as pull.
- It returns immediately when the revision changes, otherwise returns an unchanged response after
  at most 25 seconds.
- It never returns lease secrets or a partial forward contract; it is only a change notification.
- It uses the existing API rate-limit boundary, permits at most one useful in-flight watch per
  client device, clamps the wait to 25 seconds, and can be disabled independently if PHP worker
  capacity is constrained.
- One background watch thread sets an event in the existing `client-agent`. Only the main agent
  loop performs pull, lease application, forward reconciliation, and cleanup.
- The agent still performs a full pull every 30 seconds with bounded jitter. This renews leases,
  repairs missed notifications, and makes long-poll an optimization rather than correctness state.
- Watch failures use capped exponential backoff and do not stop health reporting or current data
  plane sessions.

No inbound management listener is added to the Linux client.

## 9. Data-Plane Scheduling

One flat bond session contains all active logical lanes, regardless of whether their execution
location is local or a slave. Physical line ID, execution location, lease generation, and logical
lane ID remain distinct identities in snapshots and health reports.

The existing 0.1.34 behavior remains authoritative:

- A new lane calibrates from first real delivered data, not connection age.
- Small/interactive chunks select a healthy low-RTT lane within the configured RTT slack.
- Sustained/backlogged traffic uses measured delivered capacity and normalized in-flight load.
- A lane below `10 Mbps`, or below the configured relative floor, is excluded from normal bulk
  after hysteresis.
- A healthy low-RTT but slow lane may carry bounded control/interactive bytes and recovery probes.
- Quarantined lanes receive bounded probes and re-enter probation only after fresh good windows.
- If all lanes are excluded, the best writable lane remains available to prevent deadlock.
- Upload and Reverse observations remain directional; a line can be eligible in one direction and
  quarantined in the other.

Classification is based on stream backlog, delivered bytes, outstanding bytes, and chunk size. It
does not inspect application payload or infer intent from one IP packet.

## 10. Failure Semantics

- A pending local lease does not enter a user bond until its fenced health report is active.
- Lease expiry removes the affected lanes from new sessions and starts a bounded drain. The local
  provider process is then stopped and the runtime file removed before release acknowledgement.
- Loss of every local lease degrades to healthy slave legs without changing the user listener.
- Loss of one slave removes only its legs; it does not rebuild healthy transports.
- Loss of the egress aggregator fails the affected bond closed. The panel may republish the same
  user endpoint with `slave_aggregate` only after a complete compatible contract is ready.
- A malformed generation, missing binary, artifact hash mismatch, duplicate route ID, duplicate
  physical lease, stale fence, public local-provider bind, or target mismatch prevents activation.
- Established sessions use the generation they started with and drain for a bounded interval;
  new sessions use only the current validated generation.
- Logs and heartbeat state contain IDs, hashes, counters, states, and bounded errors, never tokens
  or provider configuration bytes.

## 11. Resource Boundaries

- One persistent `leikwan-pathd` process serves every hybrid forward on a Linux client.
- No per-forward polling thread, systemd daemon, provider configuration copy, or unbounded route
  history is allowed.
- Queues, reorder windows, snapshots, health history, and reconnect state are explicitly bounded.
- Local automated commands run serially. Only one Cargo, Python test runner, or PHP validation job
  runs at a time on the user's computer.
- The live acceptance run records incremental RSS for `client-agent`, `leikwan-pathd`, and local
  line children. The 30-minute soak gate is no monotonic RSS growth and at most `128 MiB`
  incremental resident memory for the hybrid runtime under the tested two-local-line shape.

## 12. Security Properties

- Pull/watch authentication and device-session enforcement are unchanged and apply before any
  lease projection is returned.
- Lease IDs are random, short-lived capabilities bound to client ID, device ID, owner, line,
  generation, configuration hash, and expiry.
- Lease fencing controls the managed product runtime; it is not DRM and cannot prevent a root
  administrator from copying a configuration and launching an unmanaged process.
- A lease cannot alter its panel-authorized target or egress endpoint.
- Runtime files are root-only and stored on `/run`; cleanup removes them on revocation and reboot.
- Slave ingress remains authenticated. Route IDs select only active, prevalidated node-owned
  leases and cannot choose an arbitrary SOCKS port or target.
- Egress session tokens remain target-scoped, protocol-scoped, forward-scoped, and expiring.
- Ordinary user UI, service descriptions, and support-visible logs use product-owned terminology.
- The implementation does not claim secrecy from root and does not weaken observability needed for
  support, incident response, or rollback.

## 13. Existing LeiGod Mode Changes

All existing modes move behind the lease authority:

- `single` selects one healthy active lease at its chosen execution location and fails over only to
  another active lease owned by that same location.
- `race` compares healthy active leases owned by its selected location and retains its passive
  throughput learner; established connections stay pinned.
- `bond` uses `linux_hybrid` only for a capable Linux device with a complete local/slave/egress
  contract. Otherwise it uses the current slave-side placement.

Catalog configuration, combination membership, and line health remain shared. The implementation
must remove code paths that start or publish a line solely because it appears in the catalog while
no matching execution lease exists. This is the required modification to the current LeiGod usage
logic; it prevents local and slave behavior from diverging into separate allocation systems.

## 14. Test Strategy

### 14.1 Local tests

Panel and migration tests cover exclusive row locking, sticky renewal, non-preemption, expiry,
generation fencing, pending-to-active readiness, stale-report rejection, client/slave projections,
capability fallback, unchanged tenant presentation, and sidecar independence.

Client-agent tests cover watch notification and backoff, periodic full-pull recovery, one
authoritative reconcile path, atomic generation writes, artifact pinning, lease cleanup, no-op
generations, and failure retention of the last good runtime.

Path daemon and Rust tests cover multi-forward ownership, local SOCKS and authenticated slave legs
in one session, route/fence identity, duplicate rejection, Reverse scheduling, adaptive admission,
bounded queues, generation reload/drain, child cleanup, and resource limits.

Slave tests cover lease-only desired lines, fenced health, flat relay publication without a
slave-local bond client, old-client `slave_aggregate` fallback, mode changes, and complete destroy
cleanup.

A real-process integration uses one ordinary application socket, at least one local leased line,
at least one slave leased line, a real egress bond server, and one target connection in normal and
Reverse directions.

### 14.2 Live validation order

1. Back up and inspect the current panel, selected slaves, egress/target, and Linux test client.
2. Measure every leased line at its actual execution location with Reverse P1 and P2 controls.
3. Measure raw client-to-slave, slave-to-egress, client-local-to-egress, and egress-to-target
   ceilings before interpreting bond results.
4. Run one-slave P1 through internal P2 to preserve the approximately 200 Mbps control.
5. Run flat local-only, slave-only, and local-plus-slave Reverse P1 canaries through the unchanged
   user endpoint and `v4-hkt-b.mcbar.cc:5201` target.
6. Inject or select a sub-10 Mbps lane and prove it leaves bulk while the session continues.
7. Run small-request latency tests before and during bulk load.
8. Run the 30-minute process, socket, restart, queue, and RSS soak.
9. Remove every canary and prove no temporary listener, lease, unit, process, or target helper
   remains before production synchronization.

## 15. Acceptance Gates

Correctness requires:

- one unchanged user listener and one application TCP stream;
- `iperf3 -R -P 1`, never P4, for the primary throughput result;
- at least one active client-local lease and two independently located execution groups in the
  formal hybrid run;
- no duplicate physical lease across locations and no accepted stale fence;
- no unit restart, uncontrolled full-session rebuild, zero-throughput interval, or unbounded queue;
- automatic bulk exclusion for a measured sub-10 Mbps lane while interactive/control traffic and
  recovery remain functional;
- Windows, Android, current-mode, and sidecar-independent regression suites unchanged.

The formal performance target is five normal 20-second Reverse P1 runs with receiver median at
least `400 Mbps`, every run at least `350 Mbps`, and zero zero-throughput intervals. The release is
not enabled broadly if the 400 Mbps median gate fails. The handoff must separately report measured
per-location raw capacity so a provider or egress ceiling is not mislabeled as an architecture
failure.

The primary command connects to the unchanged forwarded endpoint and keeps the target behind that
endpoint unchanged:

```bash
iperf3 -4 -c <forward-endpoint> -p <public-port> -R -P 1 -t 20 --json
```

During simultaneous bulk load, interactive p95 latency must remain within `15 ms` of the best
currently admitted idle-path baseline, unless the raw path itself degrades by more than that amount.
The resource gate is the bounded RSS requirement in section 11 and zero persistent canary residue.

## 16. Rollout And Rollback

1. Add tests before behavior changes and run focused suites serially.
2. Add schema and services behind disabled LeiGod-specific rollout gates.
   `LEIGOD_EXECUTION_LEASES_ENABLED` and `LEIGOD_LINUX_HYBRID_ENABLED` default off and are activated
   independently.
3. Build and pin the `leikwan-pathd` artifact; do not activate an unpinned or hash-mismatched
   binary.
4. Deploy committed source and artifacts to isolated panel/client/slave/egress canaries only.
5. Run the capacity controls, correctness gates, Reverse P1 performance gate, latency test, and
   resource soak.
6. Back up application files, database, runtime configs, units, and current artifacts before any
   production activation.
7. Activate one canary assignment, verify endpoints and runtime state, then expand only after the
   acceptance window passes.
8. Roll back by disabling the hybrid gate, revoking client-device leases, republishing the same
   forward through `slave_aggregate`, stopping `leikwan-pathd`, and verifying that endpoint tuples
   did not change.
9. Synchronize only committed `HEAD`, report migration counts and artifact hashes, verify live HTTP
   endpoints and agents, then update the handoff with commands, measurements, cleanup, and rollback
   material.

## 17. Completion Definition

The feature is complete only when the design's local suites pass, an isolated live run proves the
lease and scheduling behavior, the Reverse P1 gate is measured without P4, cleanup is proven, the
accepted committed release is synchronized to production with backups and migration verification,
and the durable handoff records both the achieved throughput and any external ceiling. A failed
performance gate leaves the rollout disabled even if the code is deployed for future canaries.
