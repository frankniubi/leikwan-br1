# 2026-05-17 Linux Client Self-Leg WireGuard Automation Design

## Outcome

Add a Linux-first real client self-leg WireGuard profile so the panel can distribute the same self-leg logic to capable client and sidecar agents automatically. The client should be able to act as its own low-latency helper by bringing up a forward-scoped WireGuard interface to the selected sidecar, then routing MPTCP/bond runtime traffic through the sidecar private tunnel address when that path wins local probes.

## Runtime shape

Current working self-leg CF bond uses client-local wstunnel to the sidecar CF mux. The new private WG self-leg adds a real tunnel layer:

```text
Linux client -> wg lw-self-<forward_id> -> Tokyo sidecar WG peer -> sidecar private tunnel IP -> sidecar MPTCP/bond backend -> target
```

The feature is Linux-only for this pass. Windows and Android clients continue to ignore the private WG profile and can keep using existing CF bond/self-leg or rented helper paths.

## Panel contract

The panel publishes `candidate_kind=self_leg` candidates with `runtime_capabilities` including `wireguard` only when both sides have WireGuard identity material:

- client heartbeat/session config exposes `wireguard_public_key`;
- sidecar heartbeat/config exposes `wireguard_public_key` and listen/fallback ports;
- the forward allows private self-leg.

The self-leg candidate includes a `self_leg_wg` object:

```json
{
  "enabled": true,
  "interface_name": "lw-self-47",
  "client_address": "10.x.x.1",
  "sidecar_address": "10.x.x.2",
  "client_public_key": "...",
  "sidecar_public_key": "...",
  "endpoint_host": "185.220.238.143",
  "endpoint_port": 14969,
  "fallback_tcp_port": 24969,
  "persistent_keepalive": 25
}
```

The sidecar management payload carries matching peers in `self_leg_client_peers`, keyed by forward/client/interface, so sidecar-agent can add or refresh the peer without manual edits.

## Client behavior

The Linux client agent persists a WireGuard keypair, reports the public key in heartbeat payloads, and renders `self_leg_wg` only when tools and runtime support are present. When selected, the installer writes `/etc/wireguard/lw-self-<forward_id>.conf`, enables/restarts `wg-quick@lw-self-<forward_id>`, and points MPTCP runtime at the sidecar private tunnel IP/port. Stale self-leg WG interfaces for the same forward are cleaned up without touching rented helper WG interfaces.

## Selection and fallback

Client selection treats `probe_transport=private_wg` as a stronger private self-leg candidate. It should first validate WG reachability to the sidecar private address and only prefer it when its measured RTT beats rented helpers. If WG fails, the existing CF direct self-leg and rented helpers remain fallback paths.

## Verification

Local tests cover the panel contract, client rendering/cleanup, sidecar peer rendering, and selection preference. Live verification should keep the current forward frozen until local tests pass, then apply to forward 47 and verify `wg show lw-self-47`, ping/TCP reachability to sidecar private IP, and `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M`.
