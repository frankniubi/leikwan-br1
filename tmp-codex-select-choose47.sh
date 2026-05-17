#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_select_choose47.py <<'PY'
import json, pathlib, urllib.request, importlib.util, sys
sys.path.insert(0, '/usr/local/bin')
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=30) as r: payload=json.load(r)
f=next(x for x in payload['forwards'] if int(x.get('forward_id',0))==47)
selected=agent.select_forward_candidates(dict(f))
rf=dict(f); rf['candidate_pool']=selected
print('agent_version', ca.AGENT_VERSION)
print('choose_runtime_family', agent.choose_runtime_family(rf, agent.running_kernel_supports_mptcp()))
print('mptcp_supported', agent.running_kernel_supports_mptcp())
print('selected:')
for c in selected:
    print(json.dumps({k:c.get(k) for k in ['candidate_kind','endpoint_id','host','runtime_capabilities','selected_primary','probe_transport','target_sidecar_port','target_sidecar_bond_port','target_sidecar_mptcp_port','dial_connect_ip','measured_rtt_ms','selection_score','leg_role']}, ensure_ascii=False))
print('choose source snippet:')
import inspect
print('\n'.join(inspect.getsource(ca.ClientAgent.choose_runtime_family).splitlines()))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_select_choose47.py root@114.80.33.50:/tmp/codex_select_choose47.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex_select_choose47.py'