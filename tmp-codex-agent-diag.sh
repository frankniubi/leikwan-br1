#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_CLIENT|SSH_OPTS)=' tmp-codex-live-converge4.sh)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'cat > /tmp/agent_diag.py <<"PY"
import json, pathlib
p=pathlib.Path("/etc/leikwan-client-agent/config.json")
try:
    c=json.loads(p.read_text())
except Exception as exc:
    print("config_error", exc)
    c={}
for k in ["disable_auto_update","state_dir","master_url","poll_interval_seconds","health_check_interval_seconds"]:
    print(k, c.get(k))
PY

echo ===agent-status===
systemctl --no-pager -l status leikwan-client-agent.service || true
echo ===journal===
journalctl -u leikwan-client-agent.service -n 180 --no-pager || true
echo ===update-state===
cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo ===config===
python3 /tmp/agent_diag.py
echo ===manual-run-8s===
timeout 8 /usr/bin/python3 /usr/local/bin/leikwan-client-agent.py --config /etc/leikwan-client-agent/config.json run || true
'