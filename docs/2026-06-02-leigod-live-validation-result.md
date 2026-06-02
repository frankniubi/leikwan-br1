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

## NOT yet validated (remaining live steps)
1. **Full leikwan agent loop** — `slave_agent.py` rendering `leikwan-gost-<iface>.service` +
   `sidecar_agent.py` rendering the gost server, driven by a panel-published `outer_transport=leigod`
   uplink end-to-end (the command FORMAT is proven; the unit-render + reconcile are unit-tested only).
2. **Racing failover** — the selector switching cnix↔leigod (selector logic is unit-tested; switch
   timing/hysteresis needs a full test-pair deploy with two candidates on one sidecar).

These need a fuller test deployment (deploy the new agents to a test slave+sidecar, configure a leigod
combination + a 2-combo route). The mechanism they build on is now proven.
