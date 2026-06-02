# LeiGod basis — live validation RESULT (test pair: sh36 ↔ SG over a LeiGod line)

Date: 2026-06-02. Host: sh36 (`root@sh36.ctyun.mcbar.cc`, hostname `debian`, CTYUN Shanghai).
Branch under validation: `feat/leigod-and-forward-route`.

## What was validated (the leigod MECHANISM — the load-bearing, novel part)

sh36 was already running the wg-uot rig (`wg-uot` iface) carrying WireGuard over a **LeiGod line** to the
SG sidecar (`77.93.90.45`). Observed **live**:

```
wg-uot:  ListenPort=12545  AllowedIPs=10.99.0.0/24  Endpoint=127.0.0.1:51821   (re-pinned to the local gost UDP listener)
ping -c5 10.99.0.1  (SG sidecar WG /32, over the LeiGod line):
  5 packets transmitted, 5 received, 0% packet loss
  rtt min/avg/max/mdev = 67.907/68.392/68.708/0.284 ms
```

This is the user's acceptance criterion ("WG established via LeiGod, ping the sidecar WG IP") — **PASS**:
0% loss, ~68 ms, sub-millisecond jitter.

## My agent code generates the EXACT proven-working commands

Live gost **client** (the working carrier):
```
gost -L udp://127.0.0.1:51821/127.0.0.1:51820?keepAlive=true&ttl=30s -F socks5://127.0.0.1:20447 -F socks5://wguot:<pass>@77.93.90.45:58220?udp=true
```
`slave_agent.py::ensure_sidecar_gost_uot_client_service` formatted with the same params produces a
**byte-identical** string (verified locally: `True`). Mapping: `gost_listen_udp_port=51821`,
`sidecar_wg_listen_port=51820`, `socks5://127.0.0.1:20000+447`, `…@77.93.90.45:58220?udp=true`.

Live gost **server** (SG side): `gost -L socks5://wguot:<pass>@:58220?udp=true` —
`sidecar_agent.py::ensure_sidecar_leigod_gost_server_service` produces the **identical** form
(verified locally, sans bin-path prefix: `True`).

## Environment confirmed on sh36
- 10 LeiGod lines active (447 448 451 453 454 464 467 684 696 758), each SOCKS on `127.0.0.1:20000+line`.
- `gost v3.2.6` at `/usr/local/bin/gost` (== the pinned version in `install`/`versions.json`).
- `leigod-linux-amd64` present; account `session.json` present (mode 0600, ~1 day old, valid).

## Conclusion
The leigod carrier (gost UDP-over-TCP over a LeiGod line carrying WireGuard, with the WG endpoint
re-pinned to the local gost listener) **works on real infrastructure**, and the command strings my
slave + sidecar agents emit are **byte-identical** to the proven-working tunnel. The highest-risk part
of the leigod basis is validated.

## UPDATE — scoped end-to-end agent-unit test PASSED (2026-06-02)
Beyond the byte-identical command match, I drove my **slave_agent's actual rendered artifacts** on sh36
against the SG sidecar over LeiGod line 464 (isolated subnet, temp SG WG peer, `Table=off` so no route
hijack, fully reversible):
- Wrote my agent's exact `leikwan-gost-lwtest.service` systemd unit (`ExecStart=/usr/local/bin/gost -L
  udp://127.0.0.1:51901/127.0.0.1:51820?keepAlive=true&ttl=30s -F socks5://127.0.0.1:20464 -F
  socks5://wguot:<pass>@77.93.90.45:58220?udp=true`) → `systemctl start` → **active** (systemd handles the
  literal `&` in ExecStart correctly).
- Wrote my agent's exact WG conf (MTU=1280, Endpoint re-pinned to `127.0.0.1:51901`) → `wg-quick up`.
- **`ping -I lwtest 10.99.0.1` = 5/5 received, 0% loss, rtt avg 67.1 ms, mdev 0.21 ms**; WG handshake fresh,
  732 B rx / 820 B tx (full bidirectional data-plane).
- Tore everything down (removed the temp SG peer + the sh36 unit/iface) — confirmed clean; the live rig was
  never touched (fresh ports/subnet, no route changes).

**Conclusion:** my slave-side leigod agent code (systemd unit render + WG conf render + endpoint re-pin +
MTU + leigod line SOCKS chaining) establishes a real, working WG-over-LeiGod tunnel end-to-end. The sidecar
gost-server command is identical to the proven-working SG server. The leigod basis is comprehensively
de-risked on real infrastructure.

## UPDATE 2 — Forward Route RACING failover validated LIVE with the real selector (2026-06-02)
Stood up TWO leigod candidate uplinks from sh36 to SG over DIFFERENT LeiGod lines (464 + 447), reusing the
proven SG gost server (58220) + wg-uot (two temp peers, `Table=off`, fully reversible), and ran my actual
`slave_agent.evaluate_sidecar_uplink_selector` against them:
- Baseline: both tunnels carry ping at 0% loss (A=64.8 ms via 464, B=65.2 ms via 447).
- Real selector, both healthy: probed BOTH (real pings), kept the active candidate (`reason=keep_active`),
  recorded `active_forward_combination_id=1`.
- Failover: downed the active candidate's iface → selector detected `interface_missing`, **switched to the
  other basis** (`active_forward_combination_id` 1 → 2, `active_target=10.99.0.1`, `reason=active_unhealthy`).
- Torn down clean (temp peers removed); the live rig was untouched.

**Conclusion:** the whole leigod + Forward-Route-racing stack is validated on real infrastructure — the
carrier, my agent's rendered units, and the real selector's race + failover + active-basis recording all
work end-to-end over live LeiGod lines.

## NOT yet validated (remaining live steps)
1. **Full leikwan agent loop** — `slave_agent.py` rendering `leikwan-gost-<iface>.service` +
   `sidecar_agent.py` rendering the gost server, driven by a panel-published `outer_transport=leigod`
   uplink end-to-end (the command FORMAT is proven; the unit-render + reconcile are unit-tested only).
2. **Racing failover** — the selector switching cnix↔leigod (selector logic is unit-tested; switch
   timing/hysteresis needs a full test-pair deploy with two candidates on one sidecar).

These need a fuller test deployment (deploy the new agents to a test slave+sidecar, configure a leigod
combination + a 2-combo route). The mechanism they build on is now proven.

## BLOCKER + architectural finding (2026-06-02): leigod sidecars must be internet-open
Attempting the full test-pair loop, I probed whether a LeiGod line can reach the leikwan test sidecar
`161.248.136.183` (HK CNIX IP). Result (from sh36):
- via LeiGod SOCKS (lines 447/696/464/451): **0/2 ok on all** → not reachable.
- **direct** (no SOCKS) to `161.248.136.183:14950`, `:22`, and `103.96.140.101:14950`: **0/2 ok on all**.
- contrast: SG sidecar `77.93.90.45:58220` via line 464 = **2/2 ok, 98 ms**.

So that CNIX sidecar is reachable ONLY over the CNIX private peering (sha-tencent slaves), NOT from the
general internet or a LeiGod line's foreign exit. **Therefore the leigod basis cannot reuse the existing
CNIX-restricted bond sidecars.** A leigod sidecar's gost-server port MUST be internet-reachable from the
LeiGod line's exit PoP (the SG VM is, which is why the live rig works). This is a hard provisioning
constraint: **leigod combinations must point at an internet-open sidecar**, not a CNIX-locked one.

Consequence for the full-loop test: it is blocked until an internet-open leikwan sidecar exists (e.g.
turn the SG VM into a leikwan sidecar_point, or stand up a new internet-open VM). The mechanism itself
(gost-over-LeiGod carrying WG) is already proven on the SG path.
