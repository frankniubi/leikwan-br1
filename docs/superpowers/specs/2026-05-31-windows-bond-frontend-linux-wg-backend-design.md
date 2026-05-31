# Windows Bond Frontend / Linux WG Backend Design

**Status:** Ready for implementation planning. Phase-1 implementation is allowed, but Phase-1 release remains gated by the explicit contract, validation, and rollout conditions in §§15–16.
**Scope:** Windows desktop client (`LeikwanBridge.Desktop`) runtime + the control-plane payload it consumes. Linux backend behavior is explicitly out of scope (see §13).

---

## 0. Locked decisions (read first)

These were confirmed with the product owner and are not open for re-litigation during implementation:

1. **Windows runs only two runtime shapes: `single` and `bond_frontend`.** No `mptcp` runtime, no `race` runtime on Windows.
2. **Windows is a pure frontend.** It dials a slave's ingress and relays the application flow. It never:
   - runs WireGuard or manages any underlay,
   - runs an MPTCP socket / endpoint policy,
   - acts as "client-as-a-slave" (no self-leg, no `lw-cfwg-*`, no `lw-cfsr-*`, no CF-WG self transit),
   - consumes inner overlay (`10.203.x.x`) / relay / preconnect / sidecar topology.
3. **Client → slave is always a direct domestic dial.** All slaves are in mainland China and the Windows client is in mainland China, so there is **no GFW between client and slave**. Windows therefore needs **no Cloudflare edge, no wstunnel, no TLS-fronting** for the client→slave hop. The slave ingress is plain TCP mux (`plain_tcp_mux`) on the slave's public domestic IP, ports `7000–9000`.
4. **`bond_frontend` aggregation is app-layer**, performed by the existing `leikwan-bond` client across multiple direct slave ingress legs. The slave re-aggregates onward to the sidecar. Aggregation does **not** happen on the Windows client via MPTCP.
5. **`slave → sidecar` is always WireGuard** (carried over CF/CNIX/etc. as a Linux implementation detail). This is invisible to Windows.

The remainder of this document specifies how to implement these decisions.

---

## 1. Summary

Today the Windows client tries to honor `mptcp` forwards via `MptcpRuntimeBuilder`. That builder makes Windows depend on Linux backend detail it cannot satisfy (inner `slave_relay_host`, underlays, MPTCP endpoint policy). The result is the observed symptom: **for an `mptcp`-tagged forward, Windows exposes no usable entry port / the forward is torn down at readiness**, even though the local listener concept is fine.

This design removes the Windows MPTCP runtime path entirely and makes Windows consume a **frontend-only ingress contract**: a set of directly-dialable slave ingress legs. Windows maps that to `single` (one leg) or `bond_frontend` (≥2 legs), both implemented by the **existing** bond/`plain_tcp_mux` runtime that already dials slave ingress directly. All transport complexity (slave→sidecar WireGuard, CF/CNIX presentation, sidecar target delivery) stays on Linux.

The key realization that makes this low-risk: **`bond_frontend` is the bond `plain_tcp_mux` runtime that already exists** (`BondRuntimeBuilder.Build`, lines 86–104 dial `ep.Host:ep.Port` directly with `--route-key`/`--auth-token`). We are mostly *re-routing* and *deleting*, not building new transport.

---

## 2. Problem statement (grounded in current code)

`LeikwanBridge.Core/Services/MptcpRuntimeBuilder.cs`:
- Prefers `mptcp.slave_relay_host : slave_relay_port` as the connect target (lines 27–41). In the rendered contract for an MPTCP forward this is the **inner overlay address** (e.g. `10.203.201.178`), which Windows has **no route to** (Windows brought up no WireGuard).
- Throws if no sidecar host/port or session token is present (lines 42–51).

`LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`:
- If `Build()` throws, it logs and returns with **no processes started** (lines 76–80) → no listener.
- Even when processes start, it waits up to 15 s for the leg/listen ports and calls `StopAll` if they are not ready (lines 100–105) → the forward is torn down.

Net effect for an `mptcp` forward on Windows: **"no entry port exposed on system."** The cause is not "Windows can't listen"; it is "Windows is forced to participate in a backend transport contract it cannot fulfill, so the runtime never reaches a healthy listening state."

Field confirmation: in the `forward 47` investigation (2026-05-31), the analogous Linux relay logic connected to the dead slave-preconnect relay and the listener served resets. Windows shares the same `prefer slave_relay_host` selection logic, but with an *unroutable* inner address, so it fails one step earlier (no working listener at all).

---

## 3. Goals

1. Remove the Windows `mptcp` runtime execution path.
2. Make a Windows `mptcp`-tagged forward come up as a working `single`/`bond_frontend` forward with a real entry port.
3. Windows consumes only directly-dialable slave ingress information.
4. Preserve frontend multi-leg throughput aggregation via `bond_frontend`.
5. Keep all backend transport (slave→sidecar WG, CF/CNIX, sidecar delivery) on Linux.
6. Preserve the existing orchestration discipline (desired-state, effective signature, no-op skip, health gating, supervision) — applied to the ingress contract.
7. Make end-to-end verification mandatory (a listening port is not "done").

## 4. Non-goals

This design does **not**:
- implement native Windows kernel MPTCP;
- run WireGuard on Windows;
- make Windows a slave / self-leg / "client-as-a-slave" (no `lw-cfwg-*`, no `lw-cfsr-*`, no CF-WG self transit);
- run wstunnel/Cloudflare on Windows for the client→slave hop (unnecessary: domestic direct);
- expose or consume inner overlay addressing, underlays, or sidecar topology on Windows;
- change the Linux meaning of bond / MPTCP / sidecar WireGuard;
- fix the `forward 47` **backend** failure (separate work — see §13).

---

## 5. Architecture

### 5.1 Windows runtime model

Exactly two shapes. For `plain_tcp_mux`, they are the **same Windows transport family** and differ only by valid ingress-leg count:

| Windows mode | When | Normative Phase-1 TCP process graph |
|---|---|---|
| `single` | exactly one valid ingress leg | stage 1: one `bond-ingress-leg-0` process binds a deterministic loopback back-port and dials the slave ingress directly with `--remote <host:port> --route-key <routeKey> --auth-token <ingressAuthToken>`; stage 2: one `bond-runtime` process listens on `listen_port`, consumes a one-element `--legs` list, and receives the TCP `--session-token` |
| `bond_frontend` | ≥2 valid ingress legs | the same two-stage stack, but with one `bond-ingress` process per leg and a single `bond-runtime` listener on `listen_port` over a multi-element `--legs` list |

- The application-facing listener is **`bond-runtime`**, not `bond-ingress`.
- `bond-ingress` binds loopback back-ports only; the runtime contract is that the app can reach the forward locally via `127.0.0.1:<listen_port>`. The implementation may bind `0.0.0.0` for compatibility, but the contract is local reachability, not public exposure.
- Each ingress leg is a **direct TCP dial to the slave's public domestic ingress** (`host:port` in `7000–9000`) authenticated with `route_key` + `ingress_auth_token`. This is the existing `bond-ingress-leg-{i}` path (`BondRuntimeBuilder.Build`, lines 86–104).
- `single` is **not** a special WSS fast path. It is the one-leg degenerate case of the direct frontend stack, and no new direct single-transport binary is required.
- The Windows direct frontend path must not route TCP using `forward.target`, `bonding.remote_target`, `slave_relay_*`, `relay_*`, `sidecar_*`, or any underlay address. The application target is represented by the session token consumed by `bond-runtime`.
- UDP / `tcp+udp` are separately gated in §§14–16. This subsection is normative for **TCP** in Phase 1.

No WireGuard, no wstunnel, no MPTCP endpoints, no self-leg. The slave ingress is the boundary of Windows's responsibility.

### 5.2 Linux runtime model (unchanged, summarized for context)

- **Slave:** accepts the direct client ingress (`plain_tcp_mux` on `7000–9000`), aggregates/dispatches, owns preconnect/relay where applicable, forwards to the sidecar over WireGuard (carried via direct/CNIX or CF/wstunnel as needed).
- **Sidecar:** receives over WireGuard, owns mux/target delivery, hides CF/CNIX specifics behind the WG boundary.
- **Invariant:** `slave → sidecar` is always WireGuard.

These are Linux concerns and **must not** appear in the Windows contract.

---

## 6. Frontend ingress contract

### 6.1 Shape

Windows runtime execution is driven exclusively by an ingress-leg set. Each leg contains only Windows-relevant fields:

```jsonc
{
  "forward_id": 47,
  "listen_port": 11035,
  "transport": "tcp",            // tcp | udp | tcp+udp
  "session_token": "<jwt>",      // bond session token (encodes target host:port; opaque to Windows)
  "udp_session_token": "<jwt>",  // when transport includes udp
  "frontend_ingress_legs": [
    {
      "endpoint_id": 640,
      "host": "47.116.104.15",   // PUBLIC DOMESTIC slave IP (directly dialable)
      "port": 7000,              // slave ingress port (7000-9000)
      "ingress_protocol": "plain_tcp_mux",
      "ingress_auth_token": "5b1b9e39...",
      "route_key": "<route-key>",
      "priority": 50,
      "weight": 100,
      "health_status": "active",
      "display_host": "47.116.104.15"  // optional, UI only
    }
  ]
}
```

### 6.2 Source of truth (Phase 1: client-side projection, but token reuse is gated)

The rendered MPTCP forward **already contains** most of the ingress-leg data needed for Windows projection: `endpoints[].host`, `.port`, `.ingress_protocol = plain_tcp_mux`, `.ingress_auth_token`, `.priority`, `.weight`, and `.health_status`.

Windows may therefore derive `frontend_ingress_legs` from `endpoints[]` and ignore the `mptcp`/`underlays`/`leg` blocks entirely. However, **token reuse is not assumed by this spec without proof**:

- The desktop code proves only that `MptcpFallbackService` can copy `mptcp.tcp_session_token` / `mptcp.udp_session_token` into bond fields.
- The desktop code does **not** prove that the shipped `leikwan-bond` runtime and backend contract accept `mptcp.*session_token` unchanged as the frontend `--session-token`.

Therefore Phase 1 has two allowed contract states:

1. **Verified token reuse** — runtime/source validation proves `mptcp.tcp_session_token` and `mptcp.udp_session_token` are valid inputs for the Windows frontend bond runtime; Windows projects them into the frontend runtime inputs unchanged.
2. **Explicit frontend token** — if token reuse is not proven, the panel must expose a Windows-usable frontend/bond token, and Phase 1 is blocked for that traffic shape until it does.

A dedicated `windows_frontend` block remains the Phase-2 hardening (§12), but token validity is a release gate in Phase 1.

### 6.3 Validity rule for a leg

A leg is "valid" iff: `host` non-empty AND `port > 0` AND `ingress_protocol == plain_tcp_mux` AND `ingress_auth_token` non-empty AND `health_status` is acceptable (see §11). Invalid legs are dropped before mode mapping.

---

## 7. Effective-mode mapping

```
validLegs = frontend_ingress_legs.filter(valid)         // §6.3 + §11 health gate
if validLegs.count == 0  -> forward not runnable (report, do not start)
if validLegs.count == 1  -> single
if validLegs.count >= 2  -> bond_frontend
```

This mapping is independent of the backend label. A real backend `mptcp` forward becomes a Windows `bond_frontend` (or `single`). This is intentional.

---

## 8. Explicit Windows exclusions

The following must never influence Windows runtime execution and must be stripped before building the effective config:

- `underlays`, `underlay legs`
- `mptcp`, `mptcp_endpoints`, `manage_mptcp_endpoints`, `subflow_limit`
- `slave_relay_host`, `slave_relay_port`, `slave_uot_relay_*`, `preconnect_relay_port`
- `relay_host`/`relay_port`/`remote_host`/`remote_port` when they reference inner/sidecar addresses
- `peer_address`, `mptcp_remote_address`, `sidecar_*`, any `10.203.x.x` / `10.204.x.x`
- `outer_transport`, `cf_*`, `cdn_host` (for the client→slave hop)

They may remain in the global payload for Linux consumers; the Windows effective config ignores them.

---

## 9. Code changes (file-by-file)

1. **`Helpers/WindowsForwardPolicy.cs`** — keep `mptcp` as "supported on Windows" (we still accept the forward), but it is now a *frontend* forward. Add a helper `ResolveWindowsRuntimeMode(forward)` that returns `single`/`bond_frontend` per §7. (Alternatively, normalize `mptcp` → frontend at this layer.)

2. **`Helpers/ModeNormalizer.cs`** — add a Windows-facing notion of `bond_frontend`. Keep `Normalize` for backend labels, but introduce the effective Windows mode resolution that maps `mptcp`→`single`/`bond_frontend` by leg count. Do not let `mptcp` reach `MptcpRuntimeBuilder`.

3. **`Services/ForwardRuntimeManager.cs`** (dispatch, lines 65–80) — change the `switch`:
   - `mptcp` → build the frontend ingress legs and dispatch to the bond `plain_tcp_mux` path (`single` or `bond_frontend`), **not** `_mptcpBuilder`.
   - `bond` → unchanged.
   - `single`/default → for `plain_tcp_mux` forwards use the direct ingress-leg path (item 4), not the wss `BuildSingle`.
   - Remove `_mptcpBuilder` from the active path (Phase 2 deletes the field/file).

4. **`Services/BondRuntimeBuilder.cs`** —
   - Add a `BuildSingle` variant (or a `BuildFrontend` entry) that, for `plain_tcp_mux`, emits the same two-stage direct frontend stack described in §5.1: one ingress helper on a loopback back-port plus one one-leg `bond-runtime` listener on `listen_port`. Do **not** use the `wss://` path for domestic forwards.
   - The existing `Build()` logic already proves the one-leg direct TCP process graph is structurally possible; no new transport binary is required. The implementation may reuse `Build()` for the one-leg case if it removes legacy assumptions that are irrelevant to the frontend-only contract.
   - Relax or remove unconditional `bonding.remote_target` validation for the TCP `plain_tcp_mux` frontend path. The frontend-only contract is token-driven and must not require a backend-only remote-target tuple just to satisfy legacy validation.
   - Derive the frontend `session_token` only from a Windows-approved frontend token source per §6.2. Do not silently assume `mptcp.*session_token` is valid without proof.
   - Keep the existing multi-leg `Build` (`plain_tcp_mux`) as `bond_frontend`.
   - Guard rails: never read `underlays`/`slave_relay_host`/inner addresses in any Windows builder.

5. **Models** (`Models/ForwardConfig.cs`, `Models/EndpointConfig.cs`, `Models/MptcpConfig.cs`) —
   - Add a method to project `endpoints[]` (+ approved frontend token inputs) into `frontend_ingress_legs` (§6.2).
   - Mark `mptcp`/`underlays`/`slave_relay_*` as Linux-only (not consumed by Windows effective config). Do not delete the JSON fields (other consumers/back-compat) — just stop reading them on Windows.
   - (`EndpointConfig.cs` and `BondEndpointHealthService.cs` are already under change on this branch — fold the ingress-leg projection + health gating in here.)

6. **Capabilities / heartbeat** (`Services/HeartbeatService.cs`, `Models/HeartbeatPayload.cs`, `Models/ClientMeta.cs`) — the spec must distinguish three things clearly:
   - `supports_mptcp` is currently a **capability advertisement** meaning Windows accepts `mptcp` forwards via compatibility runtime behavior; it is not evidence of Linux-equivalent kernel MPTCP or client-managed underlays.
   - `wireguard_public_key`, `wireguard_listen_port`, and `wireguard_fallback_tcp_port` are **heartbeat-advertised runtime/provisioning inputs**, not frontend-ingress controls.
   - `client.agent_config_json` from `/api/client/configs/pull` is a **panel-managed authoritative config snapshot**, not a round-trip echo of heartbeat state.

   Phase 1 must either (a) keep the current heartbeat/provisioning semantics and explicitly document that frontend-only Windows runtime behavior does not imply removing WG identity advertisement, or (b) introduce and verify a new panel contract that safely suppresses self-leg/underlay expectations without breaking provisioning. This is a release gate, not a follow-up cleanup item.

7. **Cleanup** (`App/Services/InstalledRuntimeCleanupService.cs`) — ensure any previously-installed Windows MPTCP runtime artifacts (`mptcp-forward-*.json`, self-leg/WG remnants) are cleaned on upgrade.

8. **Retire** `MptcpRuntimeBuilder.cs` from the active path (Phase 2): delete or reduce to a no-op stub; remove `MptcpFallbackService` Windows usage if it only existed to prop up the MPTCP path.

---

## 10. Signature rules

The Windows runtime contract signature is computed from Windows-visible ingress semantics only:

Include: `forward_id`, `listen_port`, effective Windows mode (`single`/`bond_frontend`), the selected ingress legs (`endpoint_id`/`host`/`port`/`transport`/`route_key`/auth material), and frontend runtime knobs that actually affect execution.

Exclude: `underlays`, `mptcp_*`, relay/preconnect/sidecar tuples, `cf_*`, and any backend-only change that does not alter Windows ingress behavior.

Result: backend-only Linux changes do not force Windows restarts; ingress changes do. (Same discipline as the existing effective-signature/no-op-skip machinery — applied to the ingress object.)

---

## 11. Health and failure attribution

### 11.1 Health gating
- A leg is selectable only if panel `health_status` is active **and** the leg passes local reachability (TCP connect + ingress auth handshake to `host:port`). **Do not select a leg on panel health alone** — `forward 47` showed the panel can mark a path "active" while it is end-to-end dead.
- Local ranking happens inside the active set (latency/priority/weight), reusing existing `BondEndpointHealthService`.
- A projected Windows frontend with zero valid legs is **not runnable** and must fail before process launch with an explicit diagnostic. It must not fall back to Linux-only relay/preconnect fields.
- If a forward advertises a transport shape Windows cannot legally run in Phase 1 (for example UDP without a validated Windows UDP contract), the forward is **not runnable** and must fail explicitly rather than silently degrading to a different transport shape.

### 11.2 Attribution (must remain observable)
On failure, the runtime must classify:

| Class | Signals | Required surface |
|---|---|---|
| **Windows-side** | listener not up; ingress-leg process not up; ingress auth handshake to slave failed; bond aggregator not up | local status/log must identify startup/runtime failure on Windows and name the failing stage |
| **Linux-side** | slave ingress accepts then drops; slave→sidecar WG down; sidecar target delivery fails | local status/log must preserve that Windows listener/runtime came up but the onward backend path failed |

A Windows-side success with end-to-end failure ⇒ the fault is Linux backend (§13), and the Windows app should surface that distinction (status + log), not silently show "connected."

### 11.3 Observable status contract
At minimum, Phase 1 must preserve the following operator-visible distinctions:

1. **Not runnable (contract)** — no valid ingress legs, missing approved frontend token, or unsupported transport contract on Windows.
2. **Startup failure (Windows)** — projected runtime was runnable, but local listener / ingress helper / bond runtime failed to start.
3. **Frontend healthy, backend unhealthy (Linux-side)** — Windows listener and expected runtime graph are up, but end-to-end traffic still fails.
4. **Healthy** — listener, expected runtime graph, and end-to-end traffic validation all succeed.

The exact UI copy may evolve, but these four states must remain distinguishable in logs and runtime status.

---

## 12. Migration

**Phase 1 — client-side derivation (no panel change):**
- Windows derives `frontend_ingress_legs` from `endpoints[]` + session token(s).
- `mptcp`-tagged forwards are run as `single`/`bond_frontend`.
- `MptcpRuntimeBuilder` is bypassed (kept on disk only as dead fallback if `endpoints[]` cannot yield a valid leg — log loudly if that path is ever hit).
- Ship + validate (see §14) before Phase 2.

**Phase 2 — contract + cleanup:**
- Panel emits an explicit `windows_frontend.ingress_legs[]` block for Windows-assigned forwards (authoritative, removes reliance on `endpoints[]` projection).
- Windows advertises frontend-only capabilities (§9.6) so the panel stops sending MPTCP/self-leg expectations.
- Delete `MptcpRuntimeBuilder` from the Windows path.

---

## 13. Relationship to the `forward 47` (Linux) backend failure

This design **fixes the Windows symptom** ("no entry port") for any forward whose backend is healthy. It does **not** fix the `forward 47` backend break, which is independent and lives in the Linux layer:
- the slave-preconnect TCP path does not complete (client→slave handshake silently dropped),
- `slave → sidecar` WG is dead (`10.203.201.179` 100% loss; node13 slave-agent cannot reach the panel → stale config),
- the only healthy path (the slave's own CF self-leg to the sidecar) is not used as the connect target.

**Consequence for validation:** if Windows `bond_frontend` dials forward 47's slave ingress (`47.116.104.15:7000`) while that slave's onward `slave→sidecar` WG is still dead, the Windows listener will come up but end-to-end traffic will still fail. **Do not use forward 47 as the Phase-1 acceptance forward until its backend is repaired** — pick a forward with a known-healthy slave→sidecar path, or fix the backend first (separate work item: connect-target failover to the healthy CF/direct path + slave→sidecar WG watchdog).

This document does not change Linux behavior (§4).

---

## 14. Testing and verification

### 14.1 Windows unit / integration
- effective-mode mapping (`endpoints[]` + approved frontend token inputs → `single`/`bond_frontend`), including 0/1/≥2 valid legs;
- ingress-leg projection drops all excluded fields (§8);
- health gating: panel-active-but-unreachable leg is excluded;
- signature stability + no-op reapply on backend-only churn;
- `bond_frontend`/`single` process-graph correctness for `plain_tcp_mux` (direct dial, no wstunnel/WG processes emitted);
- `MptcpRuntimeBuilder` is never invoked for any Windows-runnable forward;
- unsupported Windows transport shapes fail explicitly instead of silently degrading.

Windows tests must **not** assert on `slave_relay_host` preference, underlay preservation, or any backend relay decision.

### 14.2 End-to-end (mandatory; listener-up is insufficient)
A Windows frontend change is verified only if **all** hold:
1. local listener is up on `listen_port`;
2. expected runtime graph is up (ingress legs + `bond-runtime`; one-leg direct stack for `single`, multi-leg direct stack for `bond_frontend`);
3. exact traffic validation succeeds;
4. failure attribution remains observable if it fails.

Exact TCP traffic command (the shape validated in debugging):
```powershell
& "C:\Users\frankniubi\Downloads\iperf3.exe" -c 127.0.0.1 -R -p <listen_port> -b 300M -t 3
```
- `-b 300M` is part of the verification contract; a listening port with failing/zero throughput is a FAIL.
- Use a forward whose backend (slave→sidecar) is currently healthy (see §13). Forward 47 is not a valid Phase-1 acceptance target until its backend is fixed.
- If Phase 1 includes a Windows UDP-capable transport shape, acceptance must include a corresponding UDP validation path. If UDP remains gated out of Phase 1, non-TCP shapes must be filtered or rejected before release rather than implicitly treated as covered by this TCP check.

### 14.3 Phase-1 release blockers (must be closed or explicitly deferred out of scope)
The following are **release gates**, not cleanup notes:

1. **Token contract** — either the shipped frontend runtime is proven to accept the projected token source, or the panel exposes an explicit Windows-usable frontend token.
2. **Single-leg direct TCP contract** — the one-leg direct `plain_tcp_mux` process graph is implemented and verified; Windows must not fall back to legacy WSS for that case.
3. **Capability / provisioning contract** — Phase 1 explicitly documents whether current heartbeat WG identity advertisement is preserved or replaced, and the panel must not infer self-leg/underlay obligations from the Windows client.
4. **Transport scope** — UDP / `tcp+udp` is either fully specified and verified for Windows Phase 1, or explicitly filtered/rejected as out of scope.
5. **Acceptance forward** — a named backend-healthy canary forward is selected and recorded before release validation starts.

---

## 15. Rollout gates and release sequence

Phase 1 is a **gated release**. Items that were previously treated as open dependencies are release blockers until closed or explicitly deferred out of scope.

### Gate A — Contract and implementation readiness (must pass before any canary)
1. The `plain_tcp_mux` `single` direct-dial TCP path is implemented and verified; Windows must not fall back to the legacy `wss` single-path for this case.
2. The frontend token source is explicit and validated: either token reuse is proven, or the panel exposes a Windows-usable frontend token.
3. The capability/provisioning contract is explicit: Phase 1 documents whether current heartbeat WG identity advertisement is preserved or replaced, and the panel must not infer self-leg/underlay obligations from the Windows client.
4. The Windows transport scope is explicit: UDP / `tcp+udp` is either fully specified and verified for Phase 1, or filtered/rejected as out of scope.

### Gate B — Automated verification (must pass before packaging)
1. Unit and integration coverage from §14.1 passes.
2. At least one test proves `MptcpRuntimeBuilder` is not invoked for any Windows-runnable forward.
3. At least one test proves Linux-only fields (`underlays`, `slave_relay_*`, preconnect, sidecar, CF metadata) do not affect the Windows runtime contract or signature.
4. At least one test proves a panel-active but locally unreachable ingress leg is excluded before effective-mode resolution.
5. At least one test proves unsupported Windows transport shapes fail explicitly instead of silently degrading.

### Gate C — Canary validation (must pass before rollout beyond the canary machine)
Before validation starts, the release operator must pre-select and record one named backend-healthy forward ID. Acceptance requires all of the following on that exact forward:
1. The old build reproduces the prior failure mode or otherwise demonstrates the regression being closed.
2. The new build exposes the listener on the expected `listen_port`.
3. The runtime graph matches the expected Windows topology (`single` or `bond_frontend`, direct ingress legs only, no WireGuard, no wstunnel, no MPTCP runtime path).
4. The exact §14.2 TCP validation command succeeds against `127.0.0.1:<listen_port>`.
5. If the backend is intentionally made unhealthy or becomes unhealthy during validation, the Windows app preserves failure attribution instead of reporting a false healthy state.

### Gate D — Release expansion
Only after Gate C passes may the build move beyond the canary machine. Phase 2 remains blocked until the explicit `windows_frontend` contract is live and the active Windows MPTCP path is removable without fallback.

If any gate fails, rollout stops immediately and §16 backout is mandatory.

---

## 16. Backout

Backout unit for this design is the **Windows client build/package only**; Linux backend behavior is unchanged by this release.

Before any rollout, retain the last known-good Windows installer/package reference and the manifest/binding needed to restore it.

### Immediate backout triggers
Back out immediately if any of the following occurs on the canary or during limited rollout:
1. No listener comes up, or readiness tears the listener back down.
2. The runtime graph includes `MptcpRuntimeBuilder`, WireGuard, wstunnel, self-leg behavior, or inner-overlay targets.
3. The §14.2 traffic command fails, stalls, or produces no usable throughput on the named backend-healthy forward.
4. Capability advertisement causes the control plane to assign self-leg, underlay, or other Linux-only expectations to Windows.
5. Signature/no-op changes cause restart churn or repeated unnecessary re-apply.
6. Failure attribution is lost such that operators cannot tell a Windows-side failure from a Linux backend failure.

### Backout procedure
1. Halt rollout and stop using the candidate Windows build for any additional clients.
2. Re-publish or reinstall the previous known-good Windows build/package.
3. Re-run baseline validation on the canary forward or another known-good baseline forward to confirm the regression is removed.
4. Leave Linux backend config unchanged unless a separate Linux incident is active.
5. Preserve logs, forward ID, build version, and runtime graph for postmortem; do not continue rollout under partial acceptance.

---

## 17. Recommendation

Adopt the Windows simplification as specified, with explicit gates:
- Windows runs only `single` / `bond_frontend`, both as direct `plain_tcp_mux` dials to domestic slave ingress.
- No MPTCP, no WireGuard, no client-as-a-slave, no wstunnel/CF on Windows for the frontend path.
- Linux owns all backend transport; `slave → sidecar` stays WireGuard.
- Start with client-side projection (Phase 1), but do not ship until token semantics, capability/provisioning semantics, transport scope, and canary validation are all explicitly closed.
- Then harden with an explicit panel `windows_frontend` contract and remove the active Windows MPTCP path (Phase 2).
