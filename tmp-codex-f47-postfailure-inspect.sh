#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_CLIENT|SSH_OPTS)=' tmp-codex-live-converge4.sh)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
echo ===active===
systemctl is-active leikwan-client-agent.service; systemctl is-active leikwan-client-47.service
echo ===agent-update-state===
cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo ===runtime-summary===
python3 - <<"PY"
import json, pathlib, re
for path in ["/etc/leikwan/mptcp-forward-47.json"]:
    p=pathlib.Path(path)
    if p.exists():
        j=json.loads(p.read_text())
        print(json.dumps({k:j.get(k) for k in ["remote_host","remote_port","relay_host","relay_port","bond_mode","direct_legs","subflow_limit"]}, indent=2))
script=pathlib.Path("/usr/local/bin/leikwan-client-start-47.sh").read_text(errors="ignore")
print("wss", re.findall(r"wss://\\S+", script))
print("has8899", "127.0.0.1:8899" in script, "has8898", "127.0.0.1:8898" in script)
PY
echo ===process===
ps -ef | grep -E "leikwan-client-start-47|leikwan-mptcp-relay client --config /etc/leikwan/mptcp-forward-47|wstunnel client -L tcp://127.0.0.1:39559" | grep -v grep || true
echo ===ss===
ss -ltnp | grep -E ":(11035|39559)" || true
echo ===journal47-recent===
journalctl -u leikwan-client-47.service --since "-3 min" --no-pager | tail -n 180
echo ===agent-recent47===
journalctl -u leikwan-client-agent.service --since "-3 min" --no-pager | grep -E "forward 47|requested client self-update|Stopping leikwan-client-agent|Started leikwan-client-agent|refreshed leikwan-client-47|restarted leikwan-client-47" || true
'