#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SLAVE=root@47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SPASS_ESC=${SPASS//%/%%}
SIDE=root@185.220.238.143
SIDEPASS='DefaultPasswd123.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
PROXY="sshpass -p '$SPASS_ESC' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=10 -W %h:%p $SLAVE"

echo '=== sync client files ==='
sshpass -p "$CPASS" scp "${SSH_OPTS[@]}" \
  client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py \
  artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64 \
  "$CLIENT:/tmp/" >/dev/null
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
install -m 0755 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0755 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
install -m 0755 /tmp/leikwan_mptcp_client.py /usr/local/bin/leikwan-mptcp-client.py
mkdir -p /var/lib/leikwan-client-agent
install -m 0755 /tmp/leikwan-mptcp-relay_0.1.0_linux_amd64 /var/lib/leikwan-client-agent/leikwan-mptcp-relay
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan-client-agent/config.json')
data=json.loads(p.read_text())
data['installer_version']='2026.05.15.3'
data['installer_sha256']='06544241cff358c15f2b6fbdf1b54523ec3677e05692cb475c4d0f645a426d98'
p.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
systemctl restart leikwan-client-agent.service
sleep 2
systemctl is-active leikwan-client-agent.service || true
python3 - <<'PY'
import importlib.util, pathlib, hashlib, json
for q in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py','/var/lib/leikwan-client-agent/leikwan-mptcp-relay']:
 p=pathlib.Path(q); print(q, hashlib.sha256(p.read_bytes()).hexdigest(), p.stat().st_size)
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('ca', str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca); print('client_version', ca.AGENT_VERSION)
PY
REMOTE

echo '=== sync sidecar via Shanghai slave hop ==='
sshpass -p "$SIDEPASS" scp "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" \
  sidecar-agent/sidecar_agent.py sidecar-agent/leikwan_mptcp_sidecar.py artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64 \
  "$SIDE:/tmp/" >/dev/null
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" "$SIDE" 'bash -s' <<'REMOTE'
set -euo pipefail
install -m 0755 /tmp/sidecar_agent.py /usr/local/bin/leikwan-sidecar-agent.py
install -m 0755 /tmp/leikwan_mptcp_sidecar.py /usr/local/bin/leikwan-mptcp-sidecar.py
# Install relay into both possible sidecar state dirs; agent will use point-specific state_dir after management patch.
mkdir -p /var/lib/leikwan-sidecar /var/lib/leikwan-sidecar-5
install -m 0755 /tmp/leikwan-mptcp-relay_0.1.0_linux_amd64 /var/lib/leikwan-sidecar/leikwan-mptcp-relay
install -m 0755 /tmp/leikwan-mptcp-relay_0.1.0_linux_amd64 /var/lib/leikwan-sidecar-5/leikwan-mptcp-relay
python3 -m py_compile /usr/local/bin/leikwan-sidecar-agent.py /usr/local/bin/leikwan-mptcp-sidecar.py
python3 - <<'PY'
import importlib.util, pathlib, hashlib
for q in ['/usr/local/bin/leikwan-sidecar-agent.py','/usr/local/bin/leikwan-mptcp-sidecar.py','/var/lib/leikwan-sidecar-5/leikwan-mptcp-relay']:
 p=pathlib.Path(q); print(q, hashlib.sha256(p.read_bytes()).hexdigest(), p.stat().st_size)
p=pathlib.Path('/usr/local/bin/leikwan-sidecar-agent.py')
spec=importlib.util.spec_from_file_location('sa', str(p)); sa=importlib.util.module_from_spec(spec); spec.loader.exec_module(sa); print('sidecar_version', sa.AGENT_VERSION)
PY
systemctl restart leikwan-bond-sidecar-5-agent.service || systemctl restart leikwan-sidecar-agent.service || true
sleep 3
systemctl is-active leikwan-bond-sidecar-5-agent.service 2>/dev/null || systemctl is-active leikwan-sidecar-agent.service 2>/dev/null || true
REMOTE