# Sidecar Shared WireGuard Peers Design

**Status:** Approved on 2026-08-04

## 1. Problem

The production data model assigns a WireGuard listen port to the sidecar point,
but the current realization code expands that point-level setting into one
WireGuard interface and one UDP listen port for every
`(point, combination, slave, lane)` tuple.

For `yt-hk-a` this turns the persisted point contract into ports from the
configured range instead of using the assigned `wireguard_listen_port=18502`.
It also creates primary and backup interfaces for the same slave identity.
WireGuard identifies a peer by public key, so two lanes that reuse the same
slave key cannot be represented as independent peers on one shared interface.

A separate forward-creation fallback caused the benchmark forwards to use
public port `443`. The test client has no client port range, so automatic
allocation returned the global edge default. That fallback is not the
production ingress contract for this benchmark and is not part of the
WireGuard port allocation.

## 2. Decisions

1. A sidecar point owns one WireGuard interface and one UDP listen port.
2. The interface listen port is exactly the point's existing
   `wireguard_listen_port`. The implementation must not allocate another port.
3. A slave has one peer on that point-level interface. Peer identity is keyed by
   `(sidecar_point_id, node_id)` and the slave WireGuard public key.
4. The slave owns one matching WireGuard interface for that sidecar point.
5. The current primary/backup duplicate lanes are removed at the WireGuard
   layer. Redundancy and throughput come from multiple slave peers and the
   client race/bond layer.
6. Existing sidecar, client, and node port values are not edited.
7. Client-to-sidecar direct connectivity remains forbidden. The topology stays
   `client -> slave -> sidecar -> target`.
8. The first production rollout is scoped to sidecar point `10` through an
   allowlist. Other points keep the legacy realization until separately
   migrated.

## 3. Port Contract

The canary keeps the existing `yt-hk-a` values:

| Purpose | Existing port | Action |
| --- | ---: | --- |
| Bond service | 18501/TCP | Keep |
| Shared WireGuard | 18502/UDP | Keep and use directly |
| WireGuard TCP fallback | 18503/TCP | Keep |
| Reserved point range | 18501-18510 | Keep |

Benchmark ingress follows the existing production convention and is supplied
explicitly in each persisted forward:

| Client mode | Slave ingress |
| --- | ---: |
| single | 7000 |
| race | 7000 |
| bond (`plain_tcp_mux`) | 7001 |

The benchmark does not modify node-group ingress settings, client port ranges,
or sidecar ports. Before any forward is enabled, the operator check must reject
`public_port=443` and reject any bond ingress outside `7000-9000`.

## 4. Panel Contract

### 4.1 Activation

Add `STATIC_SIDECAR_SHARED_WG_POINT_IDS`, a comma-separated point allowlist.
An empty value preserves the current behavior. The canary value is `10`.
This changes realization mode only; it does not contain or rewrite ports.

### 4.2 Shared uplink identity

For an allowlisted point, the panel builds one uplink per
`(sidecar_point_id, node_id)`:

- sidecar interface name: stable point-level name, for example `lwsp10`;
- slave interface name: stable point-and-node name, for example `lwsp10n2`;
- lane: `shared`;
- listen port: the point's `wireguard_listen_port`;
- one stable `/30` transit subnet per point and slave;
- one slave public key and one peer address;
- relay target: the sidecar address on that subnet plus the existing sidecar
  service port.

Combination IDs may remain as provenance metadata, but they must not influence
the interface, listen port, peer identity, or subnet. Duplicate combinations
that select the same point and slave are deduplicated before publication.

The legacy per-tuple port and subnet allocators remain available only for
points outside the allowlist during the migration period.

## 5. Sidecar Reconciliation

The sidecar agent groups all shared uplink records by point-level interface and
renders one configuration atomically:

```ini
[Interface]
PrivateKey = <point identity>
ListenPort = 18502
Address = <sidecar address for slave A>/32
Address = <sidecar address for slave B>/32

[Peer]
PublicKey = <slave A public key>
AllowedIPs = <slave A address>/32
PersistentKeepalive = 25

[Peer]
PublicKey = <slave B public key>
AllowedIPs = <slave B address>/32
PersistentKeepalive = 25
```

The complete desired peer set is written once, then the point-level
`wg-quick@` unit is started or restarted once. Reconciliation must not restart
the interface once per peer.

Validation is fail-closed:

- every record in a group must have the same point, interface, listen port, and
  outer transport;
- a public key may appear only once;
- AllowedIPs may not overlap between peers;
- missing keys, addresses, or listen ports make the new desired group invalid;
- an invalid group is logged and the last valid running configuration is kept.

After a valid shared interface is active, stale legacy interfaces for that
allowlisted point are removed through the existing authoritative-state cleanup.

## 6. Slave Reconciliation

The panel publishes one deduplicated uplink to each selected slave. The slave
agent renders one interface with one sidecar peer and points it at the existing
shared sidecar endpoint port.

For direct `current_mode`, the peer endpoint is the production sidecar host at
`18502/UDP`. Existing carrier helpers remain responsible for wrapping the same
shared WG endpoint when a later point is migrated from CF, TCP fallback, or
LeiGod transport. This canary does not redesign those carriers.

The slave watchdog observes the single peer. It must not restart or remove
other point interfaces when one point is stale.

## 7. Migration And Rollback

Point `10` currently has no enabled forwards, so it can be migrated without
moving live user traffic.

1. Deploy Panel and agent code with the allowlist empty.
2. Deploy the updated sidecar agent to `yt-hk-a` through a slave hop and update
   the selected `sha-tencent` slave agents.
3. Set `STATIC_SIDECAR_SHARED_WG_POINT_IDS=10` on the Panel.
4. Republish point and node desired state.
5. Verify one sidecar interface on UDP `18502`, one peer per selected slave,
   matching slave interfaces, and fresh bidirectional handshakes.
6. Only then create benchmark forwards.

Rollback removes point `10` from the allowlist and republishes desired state.
Because the persisted port assignments are unchanged, the legacy realization
can be recreated without a database port migration.

## 8. Verification

Automated coverage must prove:

- the Panel uses the existing point listen port for every shared peer;
- four slaves produce one sidecar interface with four peers, not eight lane
  interfaces;
- the same slave selected through multiple combinations is deduplicated;
- the sidecar writes all peers atomically and restarts once;
- invalid duplicate keys or overlapping AllowedIPs preserve the last valid
  config;
- the slave receives one interface per point and node;
- non-allowlisted points retain the legacy payload;
- benchmark forward payloads are rejected if they contain port `443`.

Live verification must record:

- sidecar UDP listener and peer count;
- each slave peer handshake age and RX/TX counters;
- absence of temporary data-plane listeners on `443`;
- successful end-to-end `iperf3` for single, race, and bond;
- forward cleanup and removal of all temporary listeners and units.

## 9. Benchmark Interpretation

The three modes use their production runtime semantics. Single and race use the
normal slave ingress path. Bond uses the sidecar aggregation path over the
shared WireGuard peer set. Results must identify the realized path for each mode
so they are not presented as identical network topologies.
