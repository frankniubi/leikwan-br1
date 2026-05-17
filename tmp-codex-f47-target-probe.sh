#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_CLIENT|SSH_OPTS)=' tmp-codex-live-converge4.sh)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
echo ===decode-token-target===
python3 - <<"PY"
import base64,json,pathlib
j=json.loads(pathlib.Path("/etc/leikwan/mptcp-forward-47.json").read_text())
for name in ["tcp_session_token","udp_session_token"]:
 tok=j.get(name,"")
 body=tok.split(".")[0] if tok else ""
 body += "="*((4-len(body)%4)%4)
 try: print(name, json.loads(base64.urlsafe_b64decode(body)))
 except Exception as e: print(name,e)
print({k:j.get(k) for k in ["remote_host","remote_port","relay_host","relay_port","bond_mode","direct_legs"]})
PY
echo ===direct-target-probe===
(timeout 5 bash -lc "cat < /dev/null > /dev/tcp/185.220.238.143/5201" && echo tcp_open) || echo tcp_fail
echo ===direct-iperf===
timeout 12 iperf3 -c 185.220.238.143 -p 5201 -R -b 50M -t 5; echo direct_iperf_exit=$?
echo ===through-local-no-r===
timeout 12 iperf3 -c 127.0.0.1 -p 11035 -b 10M -t 5; echo local_iperf_exit=$?
echo ===journal-new===
journalctl -u leikwan-client-47.service --since "-1 min" --no-pager | tail -n 120
'