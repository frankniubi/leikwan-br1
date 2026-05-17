#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set +e
echo '=== remote identity ==='
echo REMOTE_OK
hostname
pwd
date -Is
whoami
echo '=== service active ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service || true
echo '=== service status agent ==='
systemctl status leikwan-client-agent.service --no-pager -l | sed -n '1,80p' || true
echo '=== service status f47 ==='
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,80p' || true
echo '=== update units ==='
systemctl list-units --all 'leikwan-client-agent-update*' --no-pager || true
echo '=== update state ==='
cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo '=== pull state ==='
cat /var/lib/leikwan-client-agent/config_pull_state.json 2>/dev/null || true
echo '=== agent recent journal ==='
journalctl -u leikwan-client-agent.service --since '2026-05-16 04:48:35' --no-pager -n 220 || true
echo '=== all updater journal ==='
journalctl --since '2026-05-16 04:48:35' --no-pager | grep -E 'leikwan-client-agent-update|client-agent-update|install-client-agent|requested client self-update|auto-release|Stopped leikwan-client|Stopping leikwan-client' | tail -n 240 || true
echo '=== hashes versions ==='
python3 - <<'PY'
import importlib.util, json, pathlib, re, hashlib
for p in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py','/usr/local/bin/leikwan-client-agent-update','/usr/local/bin/leikwan-install-client.sh']:
    path=pathlib.Path(p)
    print('FILE',p,'exists',path.exists(),'size',path.stat().st_size if path.exists() else '')
    if path.exists(): print('sha256', hashlib.sha256(path.read_bytes()).hexdigest())
try:
    spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
    m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    print('AGENT_VERSION',m.AGENT_VERSION)
except Exception as e: print('agent import err',repr(e))
PY
echo '=== client meta desired ==='
python3 - <<'PY'
import json, pathlib, urllib.request
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=30) as r: payload=json.load(r)
client=payload.get('client',{})
print(json.dumps({k:client.get(k) for k in sorted(client.keys()) if k.startswith('desired_') or k in ('id','agent_update_request_token','agent_config_json')}, indent=2, ensure_ascii=False))
print('forwards', [f.get('forward_id') for f in payload.get('forwards',[])])
PY
RS
