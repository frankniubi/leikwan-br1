#!/usr/bin/env bash
set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@114.80.33.50 'bash -s' <<'REMOTE'
set -u
echo date=$(date -Is)
echo agent=$(python3 - <<'PY'
import importlib.util
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py'); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca); print(ca.AGENT_VERSION)
PY
)
systemctl is-active leikwan-client-agent.service || true
systemctl is-active leikwan-client-47.service || true
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,45p' || true
echo ---start
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
echo ---bestmap
cat /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json 2>/dev/null || true
echo ---applied
cat /var/lib/leikwan-client-agent/applied.json 2>/dev/null || true
echo ---listeners
ss -ltnp | egrep ':(11035|21739|21740|21741|21742|21743|21744|21745)\b' || true
REMOTE