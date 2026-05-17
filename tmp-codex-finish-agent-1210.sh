#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set -e
install -m 0644 /tmp/leikwan-client-agent.py /usr/local/bin/leikwan-client-agent.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py
python3 - <<"PY"
import importlib.util
spec=importlib.util.spec_from_file_location("ca","/usr/local/bin/leikwan-client-agent.py")
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); print("agent_version", m.AGENT_VERSION)
PY
sha256sum /usr/local/bin/leikwan-client-agent.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json
systemctl restart leikwan-client-agent.service
sleep 6
systemctl is-active leikwan-client-agent.service
journalctl -u leikwan-client-agent.service -n 50 --no-pager | tail -n 50
'