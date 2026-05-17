#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
SCP_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PASS" scp "${SCP_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py "$CLIENT:/tmp/" >/dev/null
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service >/dev/null 2>&1 || true
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
pkill -f 'wstunnel client .*07289d1f5ed25399.*nrt1\.nbwly\.com' 2>/dev/null || true
python3 - <<'PY'
import hashlib, pathlib, importlib.util
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
print('AGENT_VERSION', ca.AGENT_VERSION)
for p in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-bond','/usr/local/bin/wstunnel']:
    path=pathlib.Path(p)
    print(p, path.exists(), hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() and path.is_file() else '')
PY
REMOTE