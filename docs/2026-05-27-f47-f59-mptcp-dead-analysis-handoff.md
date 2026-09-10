# 2026-05-27 f47/f59 MPTCP Dead-Leg Analysis Handoff

## Scope

This handoff captures the current read-only analysis for:

- `10.203.201.139` unreachable on forward `47` / interface `lw-mptcp-47-0`.
- `lw-mptcp-59-1`, `lw-mptcp-59-2`, `lw-mptcp-59-3` all dead on forward `59`.

No runtime change is made in this handoff. Credentials are intentionally omitted. Sidecar access must remain through slave hops only.

## Executive conclusion

1. `10.203.201.139` is not a client-to-slave failure.
   - On `sh36`, `lw-mptcp-47-0` handshakes and reaches slave address `10.203.201.138`.
   - The same interface cannot reach sidecar address `10.203.201.139`.
   - Therefore the broken segment is after the slave leg: slave-to-sidecar, sidecar peer AllowedIPs/route, or sidecar reply path.

2. `lw-mptcp-59-1/-2/-3` are client-to-slave failures.
   - All three have no latest handshake and `0 B received`.
   - Pings to both their slave addresses and sidecar addresses are 100% loss.
   - Two of the three selected legs are currently direct public UDP to `:7413`, not local wstunnel relay. That contradicts the desired CF-BOND/CF-MPTCP behavior where WireGuard establishment should be protected by slave-side wstunnel/CF path when supported.

3. Forward `59` also has two independent destabilizers:
   - `lw-cfwg-59` failed earlier with `RTNETLINK answers: Address already in use` on `10.204.7.233`, so the self CF-WG transit leg collided locally.
   - Panel sidecar management still contains duplicate node13 entries for forward `59` (`endpoint_id` 504 and 420) using the same `10.203.2.225/226/227` address tuple, which can create duplicate underlay/address ambiguity on sidecar and slaves.

## Current observed state

### Panel desired state

Forward `47`:

- `client_mode=mptcp`
- `backend_mode=cf_mode`
- `public_port=7000`
- `listen_port=11035`
- sidecar point `5` / host `185.220.238.143`
- selected endpoints include node3/node12/node13/node14.
- relevant sidecar underlay:
  - `lw-sc-5-52`
  - client/slave/sidecar tuple: `10.203.201.137/138/139`
  - endpoint id `499`, node3.

Forward `59`:

- Panel forward says `client_mode=bond`, but combo runtime says `mptcp_preferred_bond_fallback`; the client runtime renders this as MPTCP underlays.
- `backend_mode=cf_mode`
- `transport_mode=tcp+udp`
- `public_port=7000`
- `listen_port=32501`
- sidecar point `5`.
- sidecar underlays:
  - `lw-sc-5-64`: endpoint `502`, node3, tuple `10.203.2.185/186/187`
  - `lw-sc-5-65`: endpoint `503`, node12, tuple `10.203.2.221/222/223`
  - `lw-sc-5-66`: endpoint `504`, node13, tuple `10.203.2.225/226/227`
  - `lw-sc-5-67`: endpoint `420`, node13, duplicate tuple `10.203.2.225/226/227`
  - `lw-sc-5-68`: endpoint `565`, node14, tuple `10.203.2.229/230/231`

Important: panel-assigned client config currently exposes high-level config plus `candidate_pool`; the final `/etc/leikwan/mptcp-forward-*.json` underlays are produced by client-agent/runtime rendering, not directly by the raw assigned payload.

### sh36 service state

At the read-only probe time:

- `leikwan-client-agent.service`: active
- `leikwan-client-47.service`: active
- `leikwan-client-59.service`: active

Live WireGuard interfaces seen on sh36:

```text
sha
lw-cfwg-47
lw-mptcp-47-0
laoliu
lw-mptcp-59-1
lw-mptcp-59-2
lw-mptcp-59-3
```

Missing but expected from forward `59` config:

- `lw-mptcp-59-0`
- `lw-mptcp-59-4`

### Forward 47 evidence

`lw-mptcp-47-0`:

```text
peer endpoint: 127.0.0.1:40130
allowed ips: 10.203.201.138/32, 10.203.201.139/32
latest handshake: recent during probe
transfer: non-zero both directions
```

Ping result from sh36 through `lw-mptcp-47-0`:

```text
10.203.201.138: reachable, avg about 6.8 ms
10.203.201.139: 100% loss
```

Interpretation:

- Client local wstunnel/WireGuard to slave is alive.
- Slave address `.138` answers.
- Sidecar address `.139` does not answer.
- The fault is not the first client-to-slave hop for f47; it is sidecar-leg routing/AllowedIPs/return path.

### Forward 59 evidence

Current `/etc/leikwan/mptcp-forward-59.json` renders selected underlays as:

| Runtime interface | Node | Endpoint id | WireGuard endpoint | Intended tuple | CF local relay? | Current result |
|---|---:|---:|---|---|---|---|
| `lw-mptcp-59-0` | node3 | 502 | `127.0.0.1:43214` | `10.203.2.185/186/187` | yes | interface missing |
| `lw-mptcp-59-1` | node13 | 504 | `47.116.104.15:7413` | `10.203.2.225/226/227` | no | dead |
| `lw-mptcp-59-2` | node14 | 565 | `8.133.170.123:7413` | `10.203.2.229/230/231` | no | dead |
| `lw-mptcp-59-3` | node12 | 503 | `127.0.0.1:43367` | `10.203.2.221/222/223` | yes | dead |

`wg show` symptoms:

```text
lw-mptcp-59-1: no latest handshake, 0 B received
lw-mptcp-59-2: no latest handshake, 0 B received
lw-mptcp-59-3: no latest handshake, 0 B received
```

Ping result:

```text
lw-mptcp-59-1 -> 10.203.2.226 and 10.203.2.227: 100% loss
lw-mptcp-59-2 -> 10.203.2.230 and 10.203.2.231: 100% loss
lw-mptcp-59-3 -> 10.203.2.222 and 10.203.2.223: 100% loss
```

Interpretation:

- For f59, the failed point is before reaching each slave WG peer for the listed interfaces.
- `lw-mptcp-59-1` and `lw-mptcp-59-2` are not using local wstunnel relay at all; they dial public UDP `:7413` directly.
- `lw-mptcp-59-3` uses local wstunnel relay, but the WG handshake still does not come back, so node12 needs slave-side relay/peer/path verification.

### sh36 recent journal evidence

Forward `59` had a local self-leg collision:

```text
transit interface lw-cfwg-59 failed after wg-quick up
RTNETLINK answers: Address already in use
ip link delete dev lw-cfwg-59
failed to add MPTCP endpoint 10.204.7.233: device does not exist
```

Forward `47` also had a previous address conflict event for `lw-mptcp-47-0`, but it recovered enough that current client-to-slave handshake and ping to `.138` work.

## Program runtime logic

### Control plane to client

1. Panel stores forward definitions and endpoint/candidate state.
2. Client pulls assigned forward configs for `line_client_id=3`.
3. Client-agent applies runtime health policy:
   - reads the panel config and candidate pool;
   - selects healthy/preferred endpoints;
   - filters `mptcp.underlays` to selected endpoints;
   - renames selected interfaces sequentially to `lw-mptcp-<forward_id>-<index>`.
4. Therefore `lw-mptcp-59-1/-2/-3` are runtime-selected indexes, not stable endpoint IDs.
5. Client writes `/etc/leikwan/mptcp-forward-<id>.json`.
6. `leikwan-client-<id>.service` / install runtime brings up:
   - WireGuard interfaces;
   - optional local wstunnel client listeners when `client_cf_relay_enabled=true`;
   - MPTCP relay and endpoint registration.

### Client CF-WG / wstunnel decision

For MPTCP underlays in `cf_mode`, `ProvisioningService::mptcpUnderlayLegs()` decides per endpoint:

- If node/slave supports CF-WG relay and the slave listen port is within the allowed slave ingress range (`7000-9000`), it emits:
  - `outer_transport=wstunnel_udp_via_slave`
  - local endpoint `127.0.0.1:<stableLocalCfwgUdpPort>`
  - `client_cf_relay_enabled=true`
  - `slave_dial_host`, `slave_dial_port`, `slave_path`
- Otherwise it leaves direct WireGuard transport, usually:
  - endpoint `<slave_public_ip>:7413`
  - no local wstunnel relay.

This explains the mixed f59 state:

- node3/node12 are configured as local wstunnel relay paths.
- node13/node14 are configured as direct public UDP paths.

### Control plane to slave

For each selected endpoint, the slave-agent should receive a slave task that sets up the slave-side WireGuard peer and, for CF mode, the wstunnel-facing relay path to the sidecar. Slave MPTCP/bond ingress must stay in ports `7000-9000`.

Expected slave responsibility per f59 leg:

- accept the client WireGuard handshake, either directly on UDP `7413` or via local wstunnel/CF relay;
- route/forward to the sidecar underlay address;
- keep peer keys and AllowedIPs aligned with panel management state.

### Control plane to sidecar

Panel sidecar management for point `5` generates `lw-sc-5-*` sidecar WG interfaces and CF runtime route material.

Correct sidecar peer logic for slave-mediated client traffic must allow replies to the client address, not only the slave address. For a tuple like:

```text
client/slave/sidecar = 10.203.201.137/138/139
```

sidecar peer AllowedIPs must include enough route information for both:

- slave endpoint address, e.g. `10.203.201.138/32`
- client source address, e.g. `10.203.201.137/32`

If sidecar only knows the slave /32, traffic from client to sidecar can arrive but replies to the client /32 can fail. That exactly matches f47: `.138` reachable, `.139` dead.

## Most likely root causes by symptom

### A. `10.203.201.139` dead on forward 47

Most likely:

- sidecar `lw-sc-5-52` peer route/AllowedIPs does not include client address `10.203.201.137/32`, or the sidecar route for that tuple has not consumed the updated management payload.

Why this is the leading cause:

- `lw-mptcp-47-0` handshakes.
- client can ping slave `.138`.
- only sidecar `.139` fails.
- this is a classic one-hop-good / next-hop-or-return-route-bad pattern.

Next proof needed:

- through slave hop only, verify sidecar `wg show lw-sc-5-52` peer AllowedIPs and routes; or verify via sidecar agent diagnostic/heartbeat if available.
- confirm sidecar-agent version actually consumed the patched `client_address` sidecar-uplink payload.

### B. `lw-mptcp-59-1` dead

Most likely:

- client is dialing node13 direct UDP `47.116.104.15:7413`, not local wstunnel relay.
- if node13 requires CF/wstunnel path or UDP `7413` is blocked/not bound/not configured for this peer, handshake cannot complete.

Additional risk:

- forward 59 has duplicate node13 endpoint/address tuple (`504` and `420`), which can confuse or conflict with slave/sidecar route generation.

### C. `lw-mptcp-59-2` dead

Most likely:

- client is dialing node14 direct UDP `8.133.170.123:7413`, not local wstunnel relay.
- same direct-UDP failure pattern as `lw-mptcp-59-1`.

### D. `lw-mptcp-59-3` dead

Most likely:

- client has local wstunnel relay enabled for node12 (`127.0.0.1:43367`), but slave-side node12 path is not completing the WG handshake.

Possible specific causes:

- local wstunnel client listener exists but cannot reach/validate node12 wstunnel server path;
- node12 slave task has stale peer key/AllowedIPs;
- node12 slave-side wstunnel path differs from client `slave_path`;
- node12 slave WG interface is down or bound to another address/port.

### E. `lw-cfwg-59` self-leg collision

Separate but important:

- `lw-cfwg-59` failed because `10.204.7.233` was already in use.
- This prevents the forward 59 relay from registering the MPTCP endpoint for that self leg.
- Before restarting anything, identify the current owner of `10.204.7.233` on sh36 and decide whether it is stale or legitimately shared.

## Next read-only checks

Do these before making changes.

### On sh36 client

```bash
ip -br addr | grep -E '10\.204\.7\.233|lw-cfwg-59|lw-mptcp-59'
wg show lw-mptcp-59-1
wg show lw-mptcp-59-2
wg show lw-mptcp-59-3
ss -lntup | grep -E '43214|43367|40130'
journalctl -u leikwan-client-59.service -n 200 --no-pager
journalctl -u 'wg-quick@lw-cfwg-59.service' -n 120 --no-pager
```

Goal:

- prove who owns `10.204.7.233`;
- prove whether local wstunnel listeners for node3/node12 are alive;
- capture current f59 runtime failure without restart.

### On f59 slaves

Check node12, node13, node14 first because they map to the currently dead visible interfaces.

```bash
hostname
ip -br addr | grep -E '10\.203\.2\.(222|223|226|227|230|231)'
wg show
ss -lntup | grep -E ':7413|:7000|:8[0-9]{3}'
systemctl --type=service --state=running | grep -Ei 'leikwan|wstunnel|wg-quick'
journalctl -u leikwan-slave-agent.service -n 200 --no-pager
```

Goal:

- verify each slave has the expected f59 WG interface and peer for client public key;
- verify whether direct UDP `7413` is actually listening for node13/node14;
- verify node12 wstunnel path/listener matches the client's rendered `slave_path`.

### On sidecar, only through slave hop

```bash
wg show lw-sc-5-52
wg show lw-sc-5-64
wg show lw-sc-5-65
wg show lw-sc-5-66
wg show lw-sc-5-67
wg show lw-sc-5-68
ip route | grep -E '10\.203\.201\.(137|138|139)|10\.203\.2\.(185|186|187|221|222|223|225|226|227|229|230|231)'
```

Goal:

- for f47, confirm whether `10.203.201.137/32` is included in sidecar peer AllowedIPs/route;
- for f59, confirm whether duplicate node13 tuple created duplicate/ambiguous routes;
- confirm sidecar actually consumed updated sidecar-agent/panel management, not only panel desired state.

## Fix direction after proof

1. Fix f47 sidecar return route first.
   - If sidecar AllowedIPs lacks client /32, force sidecar-agent to consume the sidecar-uplink payload that includes `client_address`, then restart only the affected sidecar WG interface/service.

2. Fix f59 client-to-slave establishment.
   - For CF-BOND/CF-MPTCP, force all selected slave legs that support it to use `wstunnel_udp_via_slave` instead of direct public UDP.
   - Add or verify watchdog behavior for high ping/path failure: refresh wstunnel path or rotate preferred CF IP, then re-establish local listener.

3. Clean f59 duplicate endpoint/address mapping.
   - Remove/disable stale duplicate endpoint `420` or ensure it cannot produce the same sidecar tuple as endpoint `504`.

4. Resolve `lw-cfwg-59` address collision.
   - Only after identifying the owner of `10.204.7.233`.
   - If stale, clean stale interface/service state and restart forward `59` narrowly.

5. Republish/sync to panel only after live proof on one slave.
   - Then sample another slave to confirm it consumed the same logic.

## Operational cautions

- Do not direct-connect client to sidecar; keep `client -> slave -> sidecar`.
- Do not move slave ingress outside `7000-9000`.
- Do not restart broad services until the exact owner of the f59 address collision and each dead slave leg is identified.
- Interface indexes for MPTCP legs are runtime-renamed; always map them back to endpoint IDs from `/etc/leikwan/mptcp-forward-59.json` before changing the wrong node.
