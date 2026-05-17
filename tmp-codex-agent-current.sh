#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_CLIENT|SSH_OPTS)=' tmp-codex-live-converge4.sh)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
echo ===agent===
systemctl is-active leikwan-client-agent.service; systemctl --no-pager -l status leikwan-client-agent.service | sed -n "1,35p"
echo ===pull-state===
python3 - <<"PY"
import json, pathlib
for path in ["/var/lib/leikwan-client-agent/config_pull_state.json","/var/lib/leikwan-client-agent/agent_update_state.json"]:
 p=pathlib.Path(path); print(path)
 if p.exists():
  j=json.loads(p.read_text())
  for k in ["status","agent_version","agent_update_request_token","last_success_at","last_error","request_token","installed_version","last_completed_request_token"]:
   if k in j: print(k, j[k])
PY
echo ===agent-journal-last90===
journalctl -u leikwan-client-agent.service -n 90 --no-pager | tail -n 90
'