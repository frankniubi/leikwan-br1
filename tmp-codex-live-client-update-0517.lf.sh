#!/usr/bin/env bash
set -euo pipefail
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
cat >/tmp/codex_client_update_now.sh <<'SH'
#!/usr/bin/env bash
set -euo pipefail
systemctl stop leikwan-client-agent.service >/dev/null 2>&1 || true
mapfile -t FORWARD_UNITS < <(systemctl list-units 'leikwan-client-*.service' --all --no-legend --no-pager 2>/dev/null | awk '{print $1}' | grep -v '^leikwan-client-agent\.service$' || true)
for unit in "${FORWARD_UNITS[@]}"; do [ -n "$unit" ] && systemctl stop "$unit" >/dev/null 2>&1 || true; done
curl --connect-timeout 15 --max-time 300 --retry 3 --retry-delay 2 -fsSL https://v3.leikwanhost.com/artifacts/install-client-agent.sh -o /tmp/leikwan-install-client-agent.sh
chmod +x /tmp/leikwan-install-client-agent.sh
LEIKWAN_SKIP_UPDATE_HELPER=1 /tmp/leikwan-install-client-agent.sh --master https://v3.leikwanhost.com --client-id 3 --agent-token "$(python3 - <<'PY'
import json, pathlib
print(json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text()).get('agent_token',''))
PY
)" --config-dir /etc/leikwan-client-agent
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
systemctl enable --now leikwan-client-agent.service >/dev/null 2>&1 || systemctl restart leikwan-client-agent.service
SH
bash /tmp/codex_client_update_now.sh
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py
python3 - <<'PY'
import importlib.util, pathlib, hashlib, json
for q in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py','/var/lib/leikwan-client-agent/leikwan-mptcp-relay']:
 p=pathlib.Path(q); print(q, hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else 'missing', p.stat().st_size if p.exists() else 0)
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py'); spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca); print('AGENT_VERSION', ca.AGENT_VERSION)
print('config', pathlib.Path('/etc/leikwan-client-agent/config.json').read_text()[:1000])
PY
systemctl is-active leikwan-client-agent.service || true
REMOTE