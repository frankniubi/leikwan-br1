#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
SCP_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sha256sum client-agent/client_agent.py artifacts/versions.json
sshpass -p "$PPASS" scp "${SCP_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json "$PANEL:/tmp/" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
install -m 0644 /tmp/client_agent.py client-agent/client_agent.py
install -m 0644 /tmp/versions.json artifacts/versions.json
python3 -m py_compile client-agent/client_agent.py
php -r '$v=json_decode(file_get_contents("artifacts/versions.json"), true); echo $v["client_agent"]["version"]."\n".$v["client_agent"]["sha256"]."\n";'
sha256sum client-agent/client_agent.py artifacts/versions.json
REMOTE
sshpass -p "$CPASS" scp "${SCP_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json "$CLIENT:/tmp/" >/dev/null
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
python3 - <<'PY'
import importlib.util, pathlib, hashlib
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
print('AGENT_VERSION', ca.AGENT_VERSION)
print('sha256', hashlib.sha256(p.read_bytes()).hexdigest())
PY
REMOTE