# Ultra UoT phase 1 source inventory (2026-09-16)

> Scope correction after operator topology decision: the missing provider relay
> source blocks a provider-native command-4 extension only. It does NOT block
> end-to-end UoT over the existing command-1 TCP carrier to an operator-owned
> sidecar. The operator selected client -> LeiGod relay -> Japan sidecar, with
> no slave on the data path. See the Japan external-UoT pilot report for live
> evidence. Statements below about unavailable implementation refer to the
> original command-4 proposal, not to all UoT approaches.

## Decision gate

UoT here means Ultra provider UDP-over-TCP. It is not `lguot`, the existing
LeiGod slave-to-sidecar WireGuard path. The 2026-09-16 design is still a spec
and explicitly gates implementation on locating the production Ultra relay
source or obtaining a command-4-capable relay endpoint.

## What is present

### Current Ultra protocol

`tools/ultra-client` currently builds and accepts:

* command `1`: TCP byte stream;
* command `3`: datagram association, with TCP as the control channel and real
  UDP datagrams carrying the data plane.

`tools/ultra-client/main.go` rejects other handshake commands. The existing
datagram envelope and native SOCKS5 multiplexer are reusable for a future UoT
data plane, but they do not provide UoT by themselves.

### Local simulators and other UoT-like code

| Path | Role | Reusable for Ultra provider UoT? |
| --- | --- | --- |
| `tools/ultra-client/tokyo_sim/relay` | local Ultra relay simulator | Yes, as a test fixture after command-4 support is deliberately added |
| `tools/lguot` | LeiGod slave to sidecar WG hop | No; different hop and protocol |
| `client-agent/leikwan_uot.py` and `leikwan_uot_bond.py` | Linux LeiGod path | No; not the Ultra provider protocol |
| `wstunnel-main/leikwan-uot` | bond-sidecar scheduler/runtime | No; not the Ultra provider protocol |
| `tools/uot-feedback` | `UOT1` feedback experiments | No; no command-3/command-4 compatibility |

## What is missing

The repository and its git history do not contain a production Ultra relay
implementation or deploy tree. The source host investigation found the
closed `/usr/local/bin/leigod-linux-amd64` client and external `:5438` relay
endpoints, but no owned Ultra relay listener/source that can be extended.
`C:\Users\frankniubi\Downloads\leigod-forward\ultra_gateway` is a broker that
uses the existing command-3 client path; it is not the production provider
relay and does not implement command 4.

Existing field evidence also shows command-3-EOF relays: the TCP control
connection is accepted and then both greeting variants receive EOF. That proves
there is a real UDP capability split, but it does not reveal a compatible
framed-TCP dialect.

## Safe phase-1 work

The next executable work is evidence collection, not a production client
change:

1. Select one healthy and one command-3-EOF endpoint from the source host.
2. Run a rate-limited command-3 probe with the same lease shape, recording the
   control reply, decoded UDP endpoint, SID/keepalive behavior, and timing.
3. Remove any temporary packet capture after extracting non-secret metadata.
4. Extend only the local `tokyo_sim/relay` once the wire contract is frozen;
   use it for command-4 framing, multi-destination native SOCKS5, and failure
   tests.

## Implementation gate

Production UoT must remain unimplemented and disabled until all of the
following exist:

* relay owner/source and deploy path, or an explicitly provided command-4 test
  endpoint;
* frozen command-4 handshake and length-prefixed frame contract;
* relay-side UDP `sendto` pump, return framing, limits, and idle behavior;
* capability discovery/version gate so old relays reject cleanly;
* usage accounting and transport feedback semantics;
* a multi-destination test for native SOCKS5/TUN, not just a single fixed
  forward target.

The current panel/runtime defaults therefore stay native. Implementing command
4 only in `ultra-client`, or enabling `auto` before relay capability is
verified, would create a client that fails against the fleet it is supposed to
repair.
