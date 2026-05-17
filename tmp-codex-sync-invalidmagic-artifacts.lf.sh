#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py slave-agent/slave_agent.py
python3 - <<'PY'
import json, pathlib, hashlib
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text())
for key, rel in [('client_agent','client-agent/client_agent.py'),('slave_agent','slave-agent/slave_agent.py'),('install_forward_runtime','client-agent/install_forward_runtime.py'),('mptcp_client_runtime','client-agent/leikwan_mptcp_client.py')]:
    p=pathlib.Path(rel)
    assert versions[key]['sha256']==hashlib.sha256(p.read_bytes()).hexdigest(), (key, versions[key]['sha256'], hashlib.sha256(p.read_bytes()).hexdigest())
    assert int(versions[key]['size_bytes'])==p.stat().st_size, (key, versions[key]['size_bytes'], p.stat().st_size)
print('manifest ok')
PY
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" \
  client-agent/client_agent.py \
  client-agent/install_forward_runtime.py \
  client-agent/leikwan_mptcp_client.py \
  slave-agent/slave_agent.py \
  artifacts/versions.json \
  "$PANEL:/tmp/" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" "set -euo pipefail
cd '$ROOT'
install -m 0644 /tmp/client_agent.py client-agent/client_agent.py
install -m 0755 /tmp/client_agent.py artifacts/client-agent.py
install -m 0644 /tmp/install_forward_runtime.py client-agent/install_forward_runtime.py
install -m 0755 /tmp/install_forward_runtime.py artifacts/install-forward-runtime.py
install -m 0644 /tmp/leikwan_mptcp_client.py client-agent/leikwan_mptcp_client.py
install -m 0755 /tmp/leikwan_mptcp_client.py artifacts/leikwan-mptcp-client.py
install -m 0644 /tmp/slave_agent.py slave-agent/slave_agent.py
install -m 0755 /tmp/slave_agent.py artifacts/slave-agent.py
install -m 0644 /tmp/versions.json artifacts/versions.json
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py slave-agent/slave_agent.py artifacts/client-agent.py artifacts/install-forward-runtime.py artifacts/leikwan-mptcp-client.py artifacts/slave-agent.py
sha256sum client-agent/client_agent.py artifacts/client-agent.py slave-agent/slave_agent.py artifacts/slave-agent.py artifacts/versions.json
grep -n 'AGENT_VERSION' client-agent/client_agent.py slave-agent/slave_agent.py | head -5
"