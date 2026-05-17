#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
echo ===status===
systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service || true
echo ===agent_recent===
journalctl -u leikwan-client-agent.service --since "2026-05-16 04:48:40" --no-pager -n 160 || true
echo ===f47_recent===
journalctl -u leikwan-client-47.service --since "2026-05-16 04:48:40" --no-pager -n 120 || true
echo ===f47_render===
python3 - <<"PY"
import json, pathlib
p=pathlib.Path("/etc/leikwan/mptcp-forward-47.json")
if p.exists():
    d=json.loads(p.read_text())
    print(json.dumps({k:d.get(k) for k in ["remote_host","remote_port","relay_host","relay_port","bond_mode","direct_legs","subflow_limit"]}, indent=2))
else: print("missing")
PY
grep -E "wstunnel|mptcp-client|mptcp-relay|leikwan-bond" -n /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
echo ===listeners===
ss -ltnp | grep -E ":(11035|39559|8898|8899)" || true
'
