#!/usr/bin/env bash
set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@114.80.33.50 'bash -s' <<'REMOTE'
set -euo pipefail
cat > /tmp/codex_probe_selection1213.py <<'PY'
import json, pathlib, urllib.request, importlib.util, sys
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip('/')+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=40) as r:
    payload=json.load(r)
forward=next(x for x in payload.get('forwards',[]) if int(x.get('forward_id',0))==47)
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
print('AGENT_VERSION', ca.AGENT_VERSION)
sel=agent.select_forward_candidates(forward)
print('selected')
for c in sel:
    print(json.dumps({k:c.get(k) for k in ['candidate_kind','endpoint_id','dial_connect_ip','selected_primary','ready','measured_rtt_ms','last_error']}, ensure_ascii=False))
rf=dict(forward); rf['candidate_pool']=sel
print('runtime_family', agent.choose_runtime_family(rf, agent.running_kernel_supports_mptcp()))
print('best_map', pathlib.Path('/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json').read_text() if pathlib.Path('/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json').exists() else 'MISSING')
PY
python3 /tmp/codex_probe_selection1213.py
REMOTE