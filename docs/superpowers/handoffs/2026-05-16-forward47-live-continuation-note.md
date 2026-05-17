# Forward 47 automatic-compact live progress note (2026-05-16)

## Current verified state from this continuation
- Real client `114.80.33.50` still has client agent `2026.05.12.9` and forward `47` applied.
- Rendered runtime is now self-leg selected:
  - `/etc/leikwan/mptcp-forward-47.json`: `bond_mode="tcp"`, `remote_host="127.0.0.1"`, `remote_port=39559`, `relay_host="10.203.201.183"`, `relay_port=8898`.
  - `direct_legs[0]`: `host="162.159.39.6"`, `port=2053`, `local_port=39559`.
  - `/usr/local/bin/leikwan-client-start-47.sh` has `wstunnel client -L tcp://127.0.0.1:39559:127.0.0.1:8899 ... wss://162.159.39.6:2053`.
- Self-leg cache currently orders forward 47 candidate IPs as `[162.159.39.6, 104.16.0.3, 162.159.39.4, 162.159.39.7, 162.159.39.29, 162.159.39.8]`.
- Sidecar panel DB point 5 is `tokyo-nrt1`: `sidecar_host=185.220.238.143`, `bond_listen_port=8899`, `mptcp_listen_port=8898`, `cf_listen_port=2053`; heartbeat fresh and ok.
- Panel `SidecarAgentController` currently renders `cf_runtime.routes[].target` as `127.0.0.1:<bond_remote_port>` for cf_mode forwards, so for forward 47 CF mux should target `127.0.0.1:8899`.

## Probe evidence from this continuation
- `tmp-codex-selfleg-520-matrix.sh` against all candidate IPs and targets `8898`/`8899` no longer printed `Invalid status code: 520`; all wstunnel client processes stayed alive after a tiny invalid payload and local connect succeeded, but reads returned EOF/0 because payload was not a real protocol transaction.
- SSH to sidecar through slave using earlier helper with `-J root@slave root@185.220.238.143` failed due missing sidecar password handling; use nested/proxy helpers with sidecar password.
- Testing sidecar SSH reachability showed `185.220.238.143:22` is open from Shanghai slaves but the remembered sidecar password was rejected on port 22; `185.220.238.143:14950` is refused; old `161.248.136.183:14950` timed out from listed slaves. Sidecar agent heartbeat is still ok from panel, so runtime can likely be inspected indirectly if SSH remains unavailable.

## Immediate next step
- Retry SSH to `114.80.33.50` (last command got `Connection closed by 114.80.33.50 port 22`) and run a segmented traffic test:
  1. direct `iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5`
  2. local direct WST leg `iperf3 -c 127.0.0.1 -p 39559 -R -b 300M -t 5`
  3. forward 47 listener `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5`
- If direct WST leg works but forward listener fails, inspect `/var/lib/leikwan-client-agent/leikwan-mptcp-relay` protocol/logs and `leikwan_mptcp_client.py` fallback.
- If direct WST leg fails, inspect sidecar CF mux route/backend indirectly through panel heartbeat payload and maybe use sidecar agent self-report/log collection endpoint if available.
