#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
python3 - <<'PY'
import json, pathlib, urllib.request, importlib.util
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
url=cfg['master_url'].rstrip('/') + '/api/client/configs/pull'
ua='Leikwan-Client-Agent/2026.05.17.2'
req=urllib.request.Request(url, headers={'Authorization':'Bearer '+cfg['agent_token'], 'User-Agent':ua, 'Accept':'application/json'})
with urllib.request.urlopen(req, timeout=30) as r:
 data=json.loads(r.read().decode())
f=next(x for x in data['forwards'] if int(x.get('forward_id',0) or 0)==47)
print('candidate_count', len(f.get('candidate_pool',[])))
for c in f.get('candidate_pool',[]):
 print('cand', c.get('candidate_kind'), c.get('endpoint_id'), c.get('device_id'), c.get('probe_transport'), c.get('self_leg_wg',{}).get('client_address'), c.get('self_leg_wg',{}).get('sidecar_address'))
# Use real ClientAgent selection a few times without applying.
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent.__new__(ca.ClientAgent)
agent.config=cfg
agent.state_dir=pathlib.Path(cfg.get('state_dir','/var/lib/leikwan-client-agent'))
agent.applied_path=agent.state_dir/'applied.json'
agent.pull_state_path=agent.state_dir/'config_pull_state.json'
agent.usage_state_path=agent.state_dir/'usage_state.json'
for i in range(5):
 sel=agent.select_forward_candidates(json.loads(json.dumps(f)))
 print('select',i, [(x.get('candidate_kind'),x.get('endpoint_id'),x.get('measured_rtt_ms'),x.get('ready'),x.get('last_error'),x.get('selected_primary')) for x in sel[:3]])
PY
REMOTE