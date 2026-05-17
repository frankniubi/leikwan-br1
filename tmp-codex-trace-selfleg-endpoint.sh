#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/codex-trace-selfleg-endpoint.py <<'PY'
import json, pathlib, importlib.util, sys, urllib.request
sys.path.insert(0, '/usr/local/bin')
spec = importlib.util.spec_from_file_location('client_agent_mod', '/usr/local/bin/leikwan-client-agent.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
install_spec = importlib.util.spec_from_file_location('install_runtime_mod', '/usr/local/bin/install_forward_runtime.py')
install_mod = importlib.util.module_from_spec(install_spec)
install_spec.loader.exec_module(install_mod)
agent = mod.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
cfg = json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text(encoding='utf-8'))
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id',0)) == 47)
selected = agent.select_forward_candidates(forward)
render_forward = dict(forward)
render_forward['candidate_pool'] = selected
render_forward['candidate_pool'] = agent.filter_mptcp_capable_candidates([item for item in render_forward.get('candidate_pool', []) if isinstance(item, dict)])
render_forward = agent.apply_mptcp_runtime_health_policy(render_forward)
self_leg = next(item for item in render_forward['candidate_pool'] if item.get('candidate_kind') == 'self_leg')
endpoint = install_mod.mptcp_self_leg_endpoint(self_leg, render_forward, 1)
print(json.dumps({
    'selected_self_leg': self_leg,
    'mptcp_self_leg_endpoint': endpoint,
    'sidecar_port_from_forward': render_forward.get('mptcp', {}).get('sidecar_port') if isinstance(render_forward.get('mptcp', {}), dict) else None,
}, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-trace-selfleg-endpoint.py root@114.80.33.50:/tmp/codex-trace-selfleg-endpoint.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex-trace-selfleg-endpoint.py'