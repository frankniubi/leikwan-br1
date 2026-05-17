#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
printf "now="; date -u
systemctl status leikwan-client-agent.service --no-pager -l | sed -n "1,140p"
echo ===journal-agent===
journalctl -u leikwan-client-agent.service -n 160 --no-pager | tail -n 160
echo ===update-state===
cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo ===version===
python3 - <<"PY"
import importlib.util
spec=importlib.util.spec_from_file_location("ca","/usr/local/bin/leikwan-client-agent.py")
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); print(m.AGENT_VERSION)
PY
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py 2>/dev/null
'