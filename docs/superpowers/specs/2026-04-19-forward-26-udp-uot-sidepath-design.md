# Forward 26 UDP UoT Sidepath Hotfix Design

Date: 2026-04-19

Status: Proposed, approved in conversation, not yet implemented

Scope: `forward 26` only

## Goal

Restore usable high-throughput UDP forwarding for `forward 26` without reducing the current TCP bond speed or stability.

The hotfix must preserve the production topology:

- `client -> slave -> sidecar`
- multiple Guangzhou slaves remain in use
- TCP bond path stays on the current `best-v6` path

## Problem Summary

The current native UDP-on-bond path is not good enough for production use.

Observed state from the 2026-04-19 ops handoff:

- TCP can currently deliver user-accepted throughput on `forward 26`
- UDP listener now comes up, but end-to-end UDP is still not correct
- native UDP bond work reduced some out-of-order behavior, but did not solve session lifecycle failures
- the shared `wait -n` supervision model can propagate UDP child-process failures into whole-forward restart churn

The current failure signatures point at native UDP bond runtime behavior, not panel contract or slave publication mismatch:

- `no active bond legs remaining for udp session`
- `failed to read bond frame header`
- `early eof`

## Decision

For `forward 26`, do not continue trying to ship UDP on the current native `leikwan-bond --protocol udp` path.

Instead:

- keep TCP on the existing bond runtime exactly as it is
- replace only the UDP path with a separate UoT-based sidepath
- keep multiple slave legs active for UDP throughput
- keep the slave simple by reusing the existing `plain_tcp_mux` transport shape

This is a targeted production hotfix, not a new general panel feature.

## Non-Goals

- no forward-schema migration
- no panel UI change
- no change to the TCP bond scheduler or TCP tuning
- no change to the production topology away from `client -> slave -> sidecar`
- no attempt to make UDP reliable beyond bounded reordering
- no reuse of the current minimal `leikwan_uot.py` framing for striped UDP merge

## Topology

The hotfix keeps the current TCP path and adds a second, isolated UDP path.

### TCP

TCP remains unchanged:

- client still runs the current TCP bond runtime
- slaves still accept the current plain TCP mux ingress on `7001`
- sidecar still accepts the current bond runtime on `9000`

### UDP

UDP becomes a separate sidepath:

- client runs a new UDP-only service for `forward 26`
- client opens one persistent UoT TCP leg per published slave through `plain_tcp_mux`
- slaves relay those TCP legs to a new sidecar UDP merge listener
- sidecar merges and reorders the striped UDP datagrams, then emits them to the real target

Recommended listener separation:

- sidecar existing bond server: `9000`
- sidecar new UDP merge service: `8999`

Recommended route-key separation:

- existing bond route key: current `bonding.route_key`
- new UDP shadow route key: `<route_key>.uot`

This keeps TCP and UDP isolated at both process and routing levels.

## Why UoT

UoT is chosen because TCP gives in-order delivery and retransmission inside each individual leg.

That helps, but it is not enough by itself. Once UDP traffic is striped across multiple slave legs, packets can still arrive out of order across those legs. Because of that, the hotfix must add its own sequence and merge layer above the per-leg UoT TCP streams.

The design therefore uses:

- UoT framing on each leg
- one persistent TCP stream per slave
- striping at the datagram level
- bounded reorder-and-release on the sidecar and client

## UDP Sidepath Protocol

Each TCP leg carries length-prefixed framed messages.

Outer framing:

- `frame_len:u32`, big-endian
- followed by one complete inner message

Inner message header:

- `version:u8`
- `msg_type:u8`
- `flags:u16`
- `session_id:u32`
- `seq:u64`
- `payload_len:u32`
- `payload:bytes`

Initial message types:

- `OPEN=1`
- `DATA=2`
- `CLOSE=3`
- `KEEPALIVE=4`

### Session Semantics

- `session_id` identifies one local UDP peer session
- `seq` is monotonic per session per direction
- one UDP datagram is always sent whole on one leg
- no cross-leg datagram fragmentation is allowed in `v1`

This keeps implementation complexity down and avoids reintroducing the same class of frag/reassembly bugs currently seen in native bond UDP.

## Striping Policy

The client and sidecar each maintain one persistent UoT TCP leg per live slave.

Datagrams are striped across live legs using weighted round robin.

Rules:

- every datagram is assigned to exactly one live leg
- larger flows can therefore use all live slaves over time
- if one leg fails, it is removed from the active set immediately
- reconnect happens in the background and does not block sending on surviving legs

This is the minimum policy that still lets a single large UDP flow benefit from multiple slaves.

## Merge Policy

Both client and sidecar implement the same bounded reorder logic.

Per `session_id`, maintain:

- `next_expected_seq`
- `reorder_buffer`
- `reorder_window_packets`
- `reorder_window_bytes`
- `reorder_timeout_ms`

Rules:

- if `seq == next_expected_seq`, deliver immediately and drain any contiguous buffered packets
- if `seq > next_expected_seq`, buffer it
- if `seq < next_expected_seq`, treat as duplicate and drop
- if the missing `next_expected_seq` does not arrive before `reorder_timeout_ms`, skip it and release later packets

Recommended starting values:

- `reorder_window_packets = 4096`
- `reorder_window_bytes = 8-16 MB`
- `reorder_timeout_ms = 15-40`

This is intentionally UDP-shaped behavior:

- bounded reordering
- bounded memory
- no indefinite stalls waiting for one missing packet

## Reliability Model

This hotfix does not implement app-level ACKs or retransmit.

Reason:

- TCP already repairs loss within each leg
- cross-leg repair would turn this back into a second reliable transport layer
- the production goal is throughput-preserving UDP forward, not a new pseudo-reliable protocol

Consequence:

- if one leg dies, some datagrams in flight on that leg may be lost
- the flow should degrade, not freeze

That trade matches normal UDP expectations and keeps the hotfix tractable.

## Process Model

The core safety requirement is complete crash isolation between TCP and UDP.

### Client

Keep:

- existing `leikwan-client-26.service` for TCP

Add:

- new `leikwan-client-26-udp-uot.service` for UDP only

Client UDP service responsibilities:

- bind `11024/udp`
- allocate `session_id` for local UDP peers
- maintain one persistent UoT TCP leg per published slave
- stripe outbound datagrams
- merge inbound datagrams
- reconnect failed legs in the background

Important:

- TCP runtime must no longer supervise UDP children under shared `wait -n`
- UDP sidepath has its own restart policy and logs

### Slave

Slave stays intentionally dumb.

It should continue to use:

- existing `plain_tcp_mux` listener on `7001`
- shadow route `<route_key>.uot`
- raw TCP relay to sidecar UDP merge service on `8999`

The slave does not need to understand striped UDP semantics.

### Sidecar

Add a new sidecar UDP merge service, isolated from the current bond runtime.

Responsibilities:

- accept one TCP leg per slave
- verify the scoped UDP token
- merge and reorder outbound datagrams toward the real target
- maintain upstream UDP sockets per `session_id`
- stripe reverse datagrams back toward the client

Existing sidecar bond service on `9000` remains unchanged.

## Control Plane Strategy

This hotfix should stay local to `forward 26`.

Recommended approach:

- no schema change
- no new panel fields
- derive the shadow UDP route and sidepath settings locally from the existing forward payload
- gate behavior through local host overrides on client, slaves, and sidecar

Existing control-plane fields that should be reused:

- `bonding.route_key`
- published slave endpoint list
- `bonding.udp_session_token`

The UDP token should be reused as the scoped credential for the new sidepath.

## File Surface

Minimal intended file surface:

- modify `client-agent/install_forward_runtime.py`
- modify `client-agent/client_agent.py`
- add `client-agent/leikwan_uot_bond.py`
- modify `slave-agent/slave_agent.py`
- add a new sidecar UDP merge runtime in `sidecar-agent`

Explicitly out of scope for the hotfix:

- `wstunnel-main/leikwan-bond/src/session.rs`

That file is the current native UDP failure zone and should not be part of this targeted rollout.

## Failure Handling

Rules:

- UDP sidepath can degrade independently
- TCP bond path must never restart because of UDP sidepath churn

Detailed handling:

- if one slave leg dies, remove it from the live UDP striping set
- keep sending on the surviving legs
- reconnect the failed leg in the background with capped backoff
- if all legs are down, keep the UDP service alive and keep retrying
- if sidecar restarts, rebuild UDP sessions lazily on next traffic
- if one slave shadow route is missing, only that UDP leg is unavailable

Suggested client reconnect backoff:

- `300ms -> 1s -> 3s`, capped

## Observability

The UDP sidepath needs its own logs and stats files.

### Client logs

- `udp-uot leg up`
- `udp-uot leg down`
- `udp-uot session opened`
- `udp-uot session closed`
- `udp-uot reorder skip`

### Sidecar logs

- `udp-uot session opened`
- `udp-uot merge buffered`
- `udp-uot gap timeout`
- `udp-uot leg disconnected`
- `udp-uot upstream send failed`

### Stats

Each side writes a small JSON stats file with at least:

- `live_legs`
- `legs_total`
- `sessions_active`
- `datagrams_in`
- `datagrams_out`
- `datagrams_dropped_gap`
- `datagrams_dropped_duplicate`
- `reorder_buffer_packets`
- `reorder_buffer_bytes`

## Verification Plan

Verification order matters.

### Before rollout

- confirm TCP path is still the current known-good `best-v6`
- confirm `forward 26` published slave set is the expected 5 Guangzhou nodes
- confirm slave `7001` plain TCP mux is healthy on all 5 nodes
- confirm sidecar UDP merge listener on `8999` is up

### Runtime-shape checks

- confirm TCP start script no longer supervises UDP children under shared `wait -n`
- confirm separate `leikwan-client-26-udp-uot.service` exists
- confirm TCP and UDP runtimes are separate processes

### Connectivity checks

- from client, confirm all 5 slave `.uot` legs connect through `7001`
- on sidecar, confirm all 5 inbound TCP sessions land on `8999`

### Functional checks

- run a small UDP echo or controlled test first
- then run `iperf3 -u -R` through `127.0.0.1:11024`

### Degradation checks

- kill one slave leg
- verify UDP throughput drops but flow continues
- verify TCP path is unchanged

### Recovery checks

- restore the failed slave
- confirm the leg rejoins without restarting the entire UDP sidepath service

## Success Criteria

- TCP throughput and stability remain at current baseline
- one UDP flow can use multiple slaves
- loss of one slave causes degradation, not total failure
- UDP restart or leg churn does not restart TCP service
- reorder memory remains bounded during a 10-second `iperf3 -u` run

## Rollback

Rollback must be simple and topology-safe.

Steps:

- stop and disable `leikwan-client-26-udp-uot.service`
- remove the shadow `.uot` route from slaves
- stop the sidecar UDP merge service on `8999`

After rollback:

- TCP path remains exactly on the pre-hotfix runtime
- no change is required to revert the existing TCP bond service

## Implementation Notes

This hotfix is intentionally narrow.

If it proves out in production, the next stage can convert the local override into a real control-plane feature. That follow-up is deliberately not part of this design, because the immediate need is to restore production UDP behavior for `forward 26` without destabilizing TCP.
