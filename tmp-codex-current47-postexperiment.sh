#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
echo ===status===; date -u; systemctl is-active leikwan-client-agent.service; systemctl is-active leikwan-client-47.service; systemctl --no-pager -l status leikwan-client-47.service | sed -n "1,80p"
echo ===listeners===; ss -ltnp | grep -E ":(11035|39559)" || true
echo ===config summary===; python3 - <<PY
import json, pathlib
p=pathlib.Path("/etc/leikwan/mptcp-forward-47.json")
if p.exists():
 j=json.loads(p.read_text()); print({k:j.get(k) for k in ["bond_mode","remote_host","remote_port","relay_host","relay_port","manage_mptcp_endpoints"]}); print("direct", j.get("direct_legs")); print("mptcp_eps", j.get("mptcp_endpoints"));
PY
echo ===recent47===; journalctl -u leikwan-client-47.service --since "-2 min" --no-pager | tail -n 160
echo ===agent tail===; journalctl -u leikwan-client-agent.service --since "-2 min" --no-pager | tail -n 120
'