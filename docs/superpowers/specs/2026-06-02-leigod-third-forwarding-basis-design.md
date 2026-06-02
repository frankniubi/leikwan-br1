# LeiGod — Third Forwarding Basis (slave↔sidecar WG outer-transport) — Design Spec

Date: 2026-06-02
Status: **Design approved direction; pending spec review → implementation plan**
Author: agent session (planning-with-files + workflows)
Source toolkit: `C:\Users\frankniubi\Downloads\leigod-forward` (separate repo)
Target repo: `leikwan-br1` (this repo), baseline branch `master`

---

## 1. Goal

Add **LeiGod accelerated lines** as a third *forwarding basis*, selectable peer-to-peer with the two
existing bases:

| Basis | Slave↔sidecar WG outer transport | Realization today |
|---|---|---|
| `bond` (normal mode, via cnix ip0) | `udp` | WG UDP egresses straight to the sidecar's CNIX IP:port |
| `cf` (networking via CFIP) | `wstunnel_udp` | slave runs a wstunnel **client** → Cloudflare → Japan VM → sidecar |
| **`leigod` (NEW)** | **`leigod`** | slave runs **gost** UoT client through a **LeiGod line SOCKS** → sidecar **gost** UoT server |

The chain is unchanged and the sidecar is preserved: `client → slave → sidecar → target`. LeiGod only
changes the **outer transport of the slave↔sidecar WireGuard hop**.

### Non-goals (v1)
- No change to the **client→slave ingress** (the WSS path-mux relay clients connect to). LeiGod is **not
  client-visible**; the desktop/mobile client never sees `leigod`.
- No native LeiGod UDP forwarding (its SOCKS-UDP relay is confirmed dead). We carry WG (UDP) only as
  **gost UDP-over-TCP** over the line's TCP SOCKS.
- No multi-line gost balancer / line round-robin in v1 (a later enhancement).

---

## 2. Resolved decisions (from brainstorm, 2026-06-02)

1. **Placement** = outer transport of the slave↔sidecar WG hop (sidecar **included**). Peer to `udp`/`wstunnel_udp`.
2. **`leigod` is a NEW `sidecar_uplink.outer_transport` value — NOT a new `backend_mode`.** `backend_mode`
   (`current_mode|cf_mode|tcp_relay`) governs the client→slave ingress, a different axis; it stays unchanged.
3. **Both ends run gost** (the slave emits gost's UoT framing method `0xF3`; the python `leikwan-uot.py`
   server would not interop). Realization **Variant A** (gost client dials the line SOCKS directly via a
   chained `-F socks5://127.0.0.1:<20000+line_id>` hop) — the proven `wg-auto.sh` path.
4. **Bandwidth is not treated as capped.** The prior ~7-8 Mbit/s was the user's SG VM throttled on a
   traffic runout, not a LeiGod limit. (TCP-over-TCP stacking overhead still applies; see Risks.)
5. **~~Per-slave LeiGod accounts.~~ SUPERSEDED 2026-06-02 → ONE shared account session, fanned out.**
   Live finding: a single account `session.json` works **concurrently across many slaves** — the
   account's `pc_max_connects=1` does NOT cap line acceleration (proven: node 2/19/20 all ran
   `leigod@453` off the same session at once, 0% loss, no kicking). LeiGod also needs **no active
   membership** (paid or free) to connect to nodes. So the panel holds **one** shared session
   (`LEIGOD_SESSION_JSON_B64`) and publishes it to every leigod node via `management['leigod_session_json']`
   — no per-slave account provisioning. Login is still out-of-band (SMS+captcha), but only **once** for
   the shared account. (Per-line `LEIGOD_LINE_TOML_<id>` is published the same way; the agent
   self-provisions the `leigod@<line>` unit + line toml from these — no manual file placement.)
6. **Deliverable** = this spec + a full task-by-task implementation plan (panel + slave-agent +
   sidecar-agent + install/update + tests).
7. **Validation** = a WG-over-LeiGod intranet ping: slave WG /32 ↔ sidecar WG /32 (the user's
   `10.22.0.1 ↔ 10.22.0.2`), 0% loss + fresh `wg show` handshake, then teardown/refcount/orphan idempotency.

---

## 3. Architecture & data flow

```
  SLAVE (gost client + leigod@<line>)                         SIDECAR (gost UoT server)
  ┌──────────────────────────────────────┐                   ┌──────────────────────────────┐
  │ wg-quick@<iface>  (WG/UDP)            │                   │ wg-quick@<iface> (WG/UDP)      │
  │   [Peer] Endpoint = 127.0.0.1:<gUDP>  │                   │   ListenPort = <listen_port>   │
  │        │ WG/UDP (loopback)            │                   │        ▲ WG/UDP (loopback)     │
  │   gost client (leikwan-gost-<iface>)  │  TCP (UoT 0xF3)   │   gost server                  │
  │   -L udp://127.0.0.1:<gUDP>/          │ = = = = = = = = ► │   -L socks5://u:p@:<gSocks>    │
  │        127.0.0.1:<sidecar_wg_port>    │  via LeiGod line  │       ?udp=true                │
  │   -F socks5://127.0.0.1:<20000+line>  │                   │   (delivers UDP to             │
  │   -F socks5://u:p@<sidecar>:<gSocks>  │                   │    127.0.0.1:<listen_port>)    │
  │        ?udp=true                      │                   └──────────────────────────────┘
  │   leigod@<line> → SOCKS 127.0.0.1:    │
  │        (20000+line_id)                │   the TCP CONNECT rides the LeiGod line → exits → sidecar
  └──────────────────────────────────────┘
```

Steady state: WG packet → `127.0.0.1:<gUDP>` (gost client) → gost frames it as UoT → CONNECT through the
LeiGod line's SOCKS (so the stream is accelerated/exits at the line's PoP) → terminating
`-F socks5://…@<sidecar>:<gSocks>?udp=true` → sidecar gost server unwraps → UDP to `127.0.0.1:<listen_port>`
→ sidecar WG decrypts → forwards to target. `publishTarget` is the **sidecar** (like cf_mode).

The two existing transports re-pin the WG `[Peer] Endpoint` to a local helper port the same way
(cf at `slave_agent.py` L4327-4328, tcp/UoT at L4332-4333); `leigod` re-pins to the local gost UDP port.
Everything below the endpoint re-pin (WG conf render + `wg-quick`) is **reused unchanged**.

---

## 4. Component design

### 4.1 Panel (`master`)

Single producer: `ProvisioningService::buildStaticSidecarUplinkPairForNode` (L5394; transport decision L5451).

- **New axis detection** — `staticSidecarCombinationUsesLeigod($combination)` (next to
  `staticSidecarCombinationUsesCf` L7798). Keys off a **new `forward_combinations` column**, NOT
  `backend_mode` (leigod is not a backend_mode). Because the static builders SELECT `fc.*`
  (`staticSlaveSidecarCombinationsForPoint` L5304 / `...ForNode` L5328), the new column flows through with
  **no query change**.
- **Transport decision** (L5451): widen `$cfMode ? 'wstunnel_udp' : 'udp'` to
  `$leigodMode ? 'leigod' : ($cfMode ? 'wstunnel_udp' : 'udp')`.
- **Slave half** emits: `outer_transport='leigod'`, `leigod_line_id`, `gost_listen_udp_port`
  (stable local UDP port), `sidecar_gost_host`, `sidecar_gost_port`, `sidecar_wg_listen_port`,
  `socks_user`, `socks_pass`, and re-pins `sidecar_endpoint_host='127.0.0.1'` /
  `sidecar_endpoint_port=<gost_listen_udp_port>` (mirror cf L5509-5510).
- **Sidecar half** emits: `outer_transport='leigod'`, `gost_socks_listen_port`, `socks_user`, `socks_pass`,
  and keeps `listen_port` = the sidecar WG UDP port (gost server's delivered-to `127.0.0.1:<listen_port>`).
- **Credential minting** — panel generates `socks_user` (default `wguot`) + `socks_pass`
  (168-bit `token_urlsafe(21)`), stored secret-flagged, pushed to **both** halves (no SSH-over-line
  `WGUOT_PARAMS`; the existing panel↔agent control plane carries it). WG pubkeys are already exchanged via
  heartbeat/`agent_config_json` (`buildStaticSidecarUplinkPairForNode` L5408-5409) — the sidecar reuses its
  existing WG identity (no new keypair).
- **Port allocator** — new `stableSidecarGostSocksPort()` (model on `allocateStaticSidecarUplinkPort` L7904
  / `stableStaticLocalUdpPort` L7614). Must be **provably disjoint** from: 20000-40000 (backend), 26000-26999
  (cf-backend), 43000+hash%2000 (WG-local), 45000 (UoT), and selector ranges. (Heeds the prior
  selector port-collision storm.)
- **Validation** — `saveForwardCombination`: add a guard analogous to the cf gate (L470): when leigod
  selected, require `sidecarPointId > 0` **and** a valid `leigod_line_id` **and** a per-node LeiGod account
  binding; thread the new fields through `$normalized` (L477) + UPDATE list (L514). **Do NOT touch the
  `backend_mode` allow-list (L461).**
- **Admin UI** — `AdminController` (~L2085): add a **self-leg transport** picker
  (CNIX-direct / CF / **LeiGod line**) and a `leigod_line_id` line picker. **Feature-flag gated** until
  per-slave accounts + capacity are provisioned.

### 4.2 Slave-agent (`slave-agent/slave_agent.py` AND `artifacts/slave-agent.py` — byte-identical)

Single render point: `apply_sidecar_uplink` (L4241; dispatch on `outer_transport` L4252).

- **New arm** before the `else`/`udp` arm (L4335):
  `elif outer_transport == 'leigod' and gost_listen_udp_port > 0 and sidecar_gost_host and sidecar_gost_port > 0:`
  - `ensure_leigod_line_service(leigod_line_id)` — ensures `leigod@<line>.service` active and
    `127.0.0.1:(20000+line_id)` listening (mirrors `leigod-forward.sh cmd_wg up` step 1). **Line-scoped,
    reference-counted** across lanes (multiple uplinks may share one SOCKS; only stop when refcount→0).
  - `ensure_sidecar_gost_uot_client_service(iface, gost_listen_udp_port, sidecar_gost_host,
    sidecar_gost_port, leigod_line_id, sidecar_wg_listen_port, socks_user, socks_pass)` — renders
    `leikwan-gost-<iface>.service` (model on `ensure_sidecar_uot_client_service` L4436).
  - Re-pin `endpoint_host='127.0.0.1'`, `endpoint_port=gost_listen_udp_port` (mirror L4332-4333).
  - Call `remove_sidecar_uot_client_service` + `remove_sidecar_wstunnel_udp_client_service` to clear other transports.
- **ListenPort-collision guard** (L4338-4344): when `local_listen_port == gost_listen_udp_port`, drop WG
  `ListenPort` to 0 (the local gost UDP listener owns that port).
- **New unit `leikwan-gost-<iface>.service`** — ExecStart (exact `wg-auto.sh _client_bringup` flags
  L101-103; **arithmetic** `20000+line_id`, not string `20<line>`):
  ```
  <gost_bin> -L 'udp://127.0.0.1:<gost_listen_udp_port>/127.0.0.1:<sidecar_wg_listen_port>?keepAlive=true&ttl=30s' \
             -F 'socks5://127.0.0.1:<20000+leigod_line_id>' \
             -F 'socks5://<socks_user>:<socks_pass>@<sidecar_gost_host>:<sidecar_gost_port>?udp=true'
  ```
  Managed by direct `systemctl` (like the UoT helper), or via `ensure_backend_service` with a new
  `expected_exec_substring` (`leikwan-gost-` / gost path) if cmdline-drift self-healing is wanted.
- **MTU** — set WG `MTU=1280` on leigod lanes (the rig fixes 1280 to avoid fragmentation over gost UoT);
  add an `mtu` field to the leigod uplink payload + the WG conf render path.
- **Teardown** — `remove_sidecar_gost_uot_client_service(iface)` wired into `remove_sidecar_uplink`
  (alongside L4390 UoT + L4394 wstunnel removals); decrement leigod line refcount, stop `leigod@<line>` iff 0.
- **Orphan reap** — `cleanup_orphan_sidecar_uplinks` (L4991): add `leikwan-gost-lws*` to the units glob
  (L5023) and a regex branch (L5030-5032) `^leikwan-gost-(lws[^.]+)\.service$`; refcount-aware sweep of `leigod@*`.
- **Config** — `DEFAULT_CONFIG` (L155-182): add `gost_bin='/usr/local/bin/gost'` and
  `leigod_bin='/usr/local/bin/leigod-linux-amd64'`.
- **Version** — bump `AGENT_VERSION` (currently `2026.06.02.1`) in **both** copies + `versions.json`.

### 4.3 Sidecar-agent (`sidecar-agent/sidecar_agent.py`)

Single render point: `apply_slave_sidecar_uplink` (L361 dispatch).

- **New branch** parallel to the `tcp` branch (L361): when `outer_transport=='leigod'` and
  `gost_socks_listen_port>0` and `listen_port>0` →
  `ensure_sidecar_leigod_gost_server_service(iface, gost_socks_listen_port, socks_user, socks_pass, listen_port)`.
  The WG conf render (PrivateKey, `ListenPort=<listen_port>`) is **reused unchanged** — gost delivers the
  unwrapped UDP to `127.0.0.1:<listen_port>`, the same local endpoint the legacy tcp/cf paths already feed.
- **New unit `leikwan-leigod-gost-server-<iface>.service`** (model on `ensure_sidecar_uot_server_service`
  L487) — **must be gost** (NOT `leikwan-uot.py`): ExecStart (exact `wg-auto.sh cmd_server` line L174):
  ```
  /usr/local/bin/gost -L 'socks5://<socks_user>:<socks_pass>@:<gost_socks_listen_port>?udp=true'
  ```
  `Restart=always RestartSec=3`, `WantedBy=multi-user.target`, unit mode 0644.
  **Operational prerequisite:** the cloud security-group MUST allow inbound TCP `<gost_socks_listen_port>`
  (the LeiGod line exits to it). No in-host iptables needed (gost is userspace).
- **Teardown** — `remove_sidecar_leigod_gost_server_service` wired into `remove_slave_sidecar_uplink`
  (alongside L411 UoT-server + L412 cf-wstunnel removals) and the non-leigod `else` arm (so a transport
  switch reaps the old gost server).
- **Controller** — `SidecarAgentController.php`: **no new query/route** —
  `slaveSidecarUplinkManagementForPoint` (L5234) already passes the per-uplink dict (incl. the new keys)
  into `management` (L308). **Do NOT** touch the `cfRows` query (L153-172) or `cfwg` reduce (L229-239) —
  leigod rides the `sidecar_uplinks` channel, not `cf_runtime`, so the cf source-grep contract tests stay green.

---

## 5. Data model & migrations

New migration `master/migrations/20260602_add_leigod_self_leg_transport.sql`:

| Table | Column | Type / default | Purpose |
|---|---|---|---|
| `forward_combinations` | `self_leg_outer_transport` | `ENUM('cnix','cf','leigod')` DEFAULT `'cnix'` | Per-combination selector for the slave↔sidecar WG outer transport (mutually exclusive — one value per hop). Sourced by `staticSidecarCombinationUsesLeigod()`. |
| `forward_combinations` | `leigod_line_id` | `INT UNSIGNED NULL` | The chosen LeiGod line → slave gost client dials SOCKS `127.0.0.1:(20000+leigod_line_id)`. Required when `self_leg_outer_transport='leigod'`. |
| `forward_combinations` | `leigod_socks_user` `VARCHAR(64)`, `leigod_socks_pass` `VARCHAR(128)` (secret-flagged) | NULL | Panel-minted gost SOCKS auth shared by the sidecar gost server + the slave gost client. Re-mintable; never logged. |
| `nodes.agent_config_json` (JSON, existing column) | `leigod` object: `{ account_label, expired_time, session_present }` | — | Per-slave LeiGod **account metadata** (decision: **per-slave accounts**). The account-bound `session.json` blob itself is NOT a DB column — it is stored as a secret and delivered to the slave via management state `management['leigod_session_json']` (written to `/opt/leigod/session.json`, mode 0600). The panel never logs the blob. |

`schema.sql` updated to match. No `backend_mode` width change (it is **not** used for leigod). The
slave's on-disk `static_sidecar_uplink_state` (load L2371 / save L2381) needs **no schema change** —
the leigod helper teardown is wired into `remove_sidecar_uplink` + `cleanup_orphan` globs instead.

---

## 6. Publish payload contract (`management['sidecar_uplinks'][i]`)

**Slave half:** `outer_transport='leigod'`, `leigod_line_id`, `gost_listen_udp_port`, `sidecar_gost_host`,
`sidecar_gost_port`, `sidecar_wg_listen_port`, `socks_user`, `socks_pass`, `mtu=1280`,
`sidecar_endpoint_host='127.0.0.1'`, `sidecar_endpoint_port=<gost_listen_udp_port>`, `sidecar_peer_public_key`.

**Sidecar half:** `outer_transport='leigod'`, `gost_socks_listen_port`, `socks_user`, `socks_pass`,
`listen_port` (WG UDP).

> `sidecar_wg_listen_port` (the gost `-L` logical delivery target = the sidecar's WG listen port) is a
> **distinct key** from the slave's local `gost_listen_udp_port`. Keep them separate in the payload.

---

## 7. Credentials & per-slave account model

- **gost SOCKS auth** (`socks_user`/`socks_pass`): **panel-minted**, pushed to both halves via
  `management['sidecar_uplinks']`. Stored secret-flagged/encrypted. No `sshpass`/SSH-over-line dependency.
- **LeiGod account `session.json`** (per-slave): account-bound (mobile + Tencent captcha + SMS login,
  minted by `leigod-forward.sh login`). The **agent never mints it** (interactive). An operator/provisioning
  step mints one session per leigod slave and the panel pushes it via management state to
  `/opt/leigod/session.json` (mode `0600`). A **refresh pipeline** re-mints before `expired_time`
  (membership ~83 days observed). The agent only ensures `leigod@<line>` is running + the SOCKS is listening
  (with per-line refcount on teardown).
- **Capacity:** with per-slave accounts, each slave's gost carrier(s) live on a dedicated account, so the
  observed `pc/route_max_connects=1` doesn't cause cross-slave contention. A slave hosting **multiple
  leigod lanes** still opens multiple carriers on its one account — confirm the account tier permits the
  needed concurrent connects, or pin one leigod lane per slave in v1.

---

## 8. Install / update of binaries

Two self-hosted binaries (neither in the upstream `source.mcbar.cc` one-liner):

- **gost** (both slave AND sidecar): `/usr/local/bin/gost` mode 0755, pinned **v3.2.6**
  (`leigod-forward/scripts/diagnose.sh` L17). Add to `versions.json` with a sha256 and fetch via the existing
  `install-slave.sh` `download_atomic` + `check_binary_current` pattern (wstunnel L350-354) and the
  `install-bond-sidecar.sh` relay-fetch pattern. Replicate diagnose.sh's `--cn` mirror chain
  (`ghfast.top→gh-proxy.com→ghproxy.net→direct`, `curl --connect-timeout 12 --max-time 240`) for China hosts.
- **leigod-linux-amd64** (slave only): `/usr/local/bin/`, self-hosted in `versions.json` + sha256.
- **Units** `leigod@.service`, `lgfwd.service` not in the upstream published set — ship from leikwan artifacts.
- **CAUTION** (memory `v3_bandwidth_db_fix`): guard every download with **sha256-skip** so install does not
  unconditionally re-download (the 100GB/day wstunnel re-download bug).
- **Dual-write:** any slave-agent change lands in BOTH `slave_agent.py` and `artifacts/slave-agent.py` +
  `AGENT_VERSION` bump, or the fleet never pulls it.

---

## 9. Validation method

Stage on a non-prod slave+sidecar pair with one leigod lane:

1. **WG-over-LeiGod ping** (the user's test): from the slave, `ping -c5 -W3 <sidecar WG /32>` (the
   `peer_address` from `allocateStaticSidecarUplinkSubnet`; the `10.22.0.1 ↔ 10.22.0.2` framing) →
   **0% loss**, and `wg show <iface>` shows a **recent handshake** + rising rx/tx.
2. **Prove it rides the line:** slave `leikwan-gost-<iface>.service` active and cmdline contains BOTH
   `-F socks5://127.0.0.1:<20000+line_id>` and `-F socks5://…@<sidecar>:<gost_socks_port>?udp=true`; sidecar
   `leikwan-leigod-gost-server-<iface>.service` active with `-L socks5://…?udp=true`; `ss` shows the WG
   `listen_port` receiving from `127.0.0.1` only.
3. **End-to-end:** a client on the slave reaches a target via sidecar→target; `publishTarget` is the sidecar.
4. **Teardown idempotency:** disable the lane → `leikwan-gost-<iface>` + `wg-quick@<iface>` reaped;
   `leigod@<line>` stays up iff another lane references it (refcount); re-enable → clean re-up.
5. **Orphan reap:** leave a stale `lws*` iface → heartbeat with section present → `cleanup_orphan_sidecar_uplinks`
   stops the `leikwan-gost-lws*` unit.
6. **Negative:** publish a leigod lane with missing `socks_pass`/`leigod_line_id` → slave tears down rather
   than fail-loop `wg-quick`.

---

## 10. Tests to add

- **sidecar-agent** (`tests/test_static_sidecar_uplinks.py`): `outer_transport='leigod'` renders
  `leikwan-leigod-gost-server-<iface>.service` with ExecStart `gost -L socks5://u:p@:<port>?udp=true` and WG
  conf `ListenPort=<listen_port>`; teardown reaps the gost server; switching `leigod→udp` reaps it.
- **slave-agent**: `apply_sidecar_uplink` leigod arm renders `leikwan-gost-<iface>.service` with the exact
  chained `-F` flags + `-L udp://…?keepAlive=true&ttl=30s` and re-pins WG Endpoint to
  `127.0.0.1:<gost_listen_udp_port>`; ListenPort-collision guard drops WG ListenPort to 0;
  `cleanup_orphan_sidecar_uplinks` reaps a stale `leikwan-gost-lws*`; leigod line refcount keeps a shared
  `leigod@<line>` running.
- **panel** (`master/tests/`): `buildStaticSidecarUplinkPairForNode` for a leigod combination emits the
  leigod keys on both halves + re-pins `sidecar_endpoint` to `127.0.0.1:<gost_listen_udp_port>`;
  `staticSidecarCombinationUsesLeigod` true only for the new column; the gost-port allocator returns stable,
  collision-free ports; `saveForwardCombination` rejects leigod when sidecar absent or `leigod_line_id`
  missing (mirror cf `cf_location_group_required` L470).
- **source-grep contract** (like `sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`): assert
  leigod rides `sidecar_uplinks` and does NOT inject into `cfRows`/`cf_runtime`.

---

## 11. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Wrong-binary trap** — slave gost UoT (0xF3) won't interop with python `leikwan-uot.py` | Sidecar server is **gost**, end-to-end. Test asserts the gost ExecStart on both ends. |
| **Shared `leigod@<line>` lifecycle** (SOCKS is line-scoped, not iface-scoped) | Per-line **refcount**; only stop `leigod@<line>` when no active uplink references it. |
| **Sidecar gost SOCKS is internet-exposed** (auth-only) | 168-bit pass; per-lane port; cloud SG allow-inbound checklist + a reachability probe before marking the lane healthy. |
| **CONFIRMED CONSTRAINT (live probe 2026-06-02): leigod sidecars must be internet-open.** A CNIX-restricted bond sidecar (e.g. `161.248.136.183`) is reachable ONLY over the CNIX private peering, NOT from a LeiGod line's foreign exit (probed: 0/4 lines + direct = unreachable; SG VM = reachable). So leigod **cannot reuse the CNIX-locked bond sidecars** — a leigod combination must point at a sidecar whose gost port is internet-reachable from the line exit. | `saveForwardCombination` (or a provisioning probe) should reject/warn when a leigod combo's sidecar is CNIX-only; admins provision an internet-open sidecar for leigod. |
| **Port-collision storm precedent** | Allocator provably disjoint from all known ranges; unit-tested. |
| **Orphan accumulation** | Extend `remove_sidecar_uplink` + `cleanup_orphan` globs/regexes for `leikwan-gost-lws*` (same bug class fixed for cf). |
| **TCP-over-TCP overhead** | Bandwidth cap dismissed, but stacking adds latency under load; keep `keepAlive/ttl`; measure via the ping test; consider multi-line `-F` round-robin only after baseline. |
| **session.json provisioning + caps** | Per-slave accounts; refresh pipeline before expiry; pin one leigod lane per slave in v1 if `max_connects` is tight. |
| **MTU fragmentation over gost UoT** | Set WG `MTU=1280` on leigod lanes. |
| **Dual-write drift** | Land every slave-agent change in both copies + bump `AGENT_VERSION`; a test/CI check on byte-identity. |

---

## 12. Phased implementation scope (for the plan)

1. **Panel data model + publish** (migration, `self_leg_outer_transport`/`leigod_line_id`, detection helper,
   transport decision, credential mint, port allocator, `saveForwardCombination` guard) + panel tests. *No
   agent behavior yet — contract first.*
2. **Sidecar-agent** leigod gost-server branch + unit + teardown + tests.
3. **Slave-agent** leigod arm + gost-client unit + `leigod@<line>` refcount + endpoint re-pin + MTU +
   orphan reap + tests; `AGENT_VERSION` bump (both copies).
4. **Install/update**: self-host gost + leigod binary in `versions.json` (sha256-skip), install-slave.sh +
   install-bond-sidecar.sh fetch blocks.
5. **Account provisioning**: per-slave `session.json` push via management state + refresh pipeline + node
   account binding + UI feature flag.
6. **Admin UI**: self-leg transport + line picker (gated).
7. **Live validation** on a non-prod pair (the §9 method), then graduated rollout.

---

## 13. Open items (deferred, not blocking the plan)

- Multi-line gost `-F` round-robin / line failover (a `forward_chain_remediation` analog for leigod).
- Per-line UDP-relay-health probe (only if a future LeiGod build fixes native SOCKS-UDP — out of scope).
- Whether a slave may host >1 leigod lane given account `max_connects` (capacity model).
- Rate-limit/`speed_limit_mbps` enforcement on the leigod path (memory notes it's unenforced for bond/mptcp today).
