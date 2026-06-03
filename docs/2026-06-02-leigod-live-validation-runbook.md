# LeiGod basis — live validation runbook (A8)

> Not runnable in the dev (Windows) environment — this is the operator runbook for a Linux
> slave + sidecar pair. Implements §9 of `docs/superpowers/specs/2026-06-02-leigod-third-forwarding-basis-design.md`.
> Branch: `feat/leigod-and-forward-route`. Code: A1–A7 (commits 547dfb6 → e7821db).

## Pre-reqs
1. A non-prod **slave** and **sidecar** on the same `sidecar_point`, both updated to the branch artifacts:
   - slave + sidecar pulled `gost` to `/usr/local/bin/gost`; slave also has `/usr/local/bin/leigod-linux-amd64`.
     Publish those two binaries to the artifact store first and fill `sha256`/`size_bytes` in `artifacts/versions.json`
     (`gost_linux_amd64`, `leigod_linux_amd64`) — they are intentionally empty until published.
   - slave agent == `AGENT_VERSION 2026.06.03.2` (`grep AGENT_VERSION /usr/local/bin/leikwan-slave-agent.py`).
2. A **LeiGod account session** minted out-of-band and delivered to the slave:
   - `leigod-forward.sh login --mobile <num> --password-env LEIGOD_FORWARD_PW` (captcha/SMS by a human).
   - Push the resulting `session.json` to the slave via management `leigod_session_json`
     (lands at `/opt/leigod/session.json`, mode 0600). Confirm: `stat -c '%a' /opt/leigod/session.json` → `600`.
3. Set panel `LEIGOD_BASIS_ENABLED=1`; create a `forward_combination` with
   `self_leg_outer_transport=leigod` + a `leigod_line_id` whose exit can reach the sidecar; attach a forward.
4. Sidecar cloud security-group **allows inbound TCP** on the lane's gost SOCKS port (47000–47999).

## 1. WG-over-LeiGod ping (the headline check, spec §9.1)
On the slave, find the lane interface (`wg show interfaces | tr ' ' '\n' | grep '^lws'`), then:
```bash
ping -c5 -W3 <sidecar WG /32 peer_address>     # the 10.x peer; e.g. the rig's 10.22.0.1 <-> 10.22.0.2
wg show <iface>                                 # expect a recent handshake + rising rx/tx
```
PASS = **0% loss** + a fresh handshake. (The docs measured ~65 ms / 0% loss on this rig.)

## 2. Prove the path actually rides the line (spec §9.2)
```bash
# slave: the gost client chains through the LeiGod line SOCKS then the sidecar gost server
systemctl is-active leikwan-gost-<iface>
systemctl show -p ExecStart leikwan-gost-<iface> | grep -o '\-F socks5://[^ ]*'
#   expect BOTH: -F socks5://127.0.0.1:20<line>  AND  -F socks5://...@<sidecar>:<port>?udp=true
systemctl is-active leigod@<line>               # LeiGod accelerator up; SOCKS on 127.0.0.1:(20000+line)
ss -lntp | grep 20<line>
# sidecar: the gost UoT server is up and the WG listen_port only sees loopback
systemctl is-active leikwan-leigod-gost-server-<iface>
ss -lntp | grep <gost_socks_port>               # gost SOCKS bound :port
```

## 3. End-to-end (spec §9.3)
A client on the slave reaches the real target through `sidecar -> target`; confirm `publishTarget` is the
**sidecar** (not the real target) in the published task payload.

## 4. Teardown idempotency + line safety (spec §9.4)
```bash
# disable the lane (panel), then on the slave:
systemctl is-active leikwan-gost-<iface>   # -> inactive/gone
systemctl is-active wg-quick@<iface>       # -> inactive/gone
systemctl is-active leigod@<line>          # STAYS active by design (shared-line safety; not auto-stopped)
```
Re-enable → clean re-up.

## 5. Orphan reap (spec §9.5)
Leave a stale `leikwan-gost-lws*` unit whose interface is gone; on the next heartbeat with the
static-uplink section present, `cleanup_orphan_sidecar_uplinks` must stop+disable+reset-failed it.

## 6. Negative (spec §9.6)
Publish a leigod lane missing `socks_pass` or `leigod_line_id`: the slave must **tear down** (no
`leikwan-gost-<iface>`, no fail-looping `wg-quick`) rather than render a broken unit.

## Record results
Append measured ping/handshake/throughput + any deviations to this file and to `progress.md`.
