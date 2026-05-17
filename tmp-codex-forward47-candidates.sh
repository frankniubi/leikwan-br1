#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/codex-forward47-candidates.py <<'PY'
import json, pathlib, importlib.util, sys, urllib.request
sys.path.insert(0, '/usr/local/bin')
spec = importlib.util.spec_from_file_location('client_agent_mod', '/usr/local/bin/leikwan-client-agent.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
agent = mod.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
cfg = json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text(encoding='utf-8'))
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id',0)) == 47)
self_leg = next(item for item in forward['candidate_pool'] if item.get('candidate_kind') == 'self_leg')
results = []
for ip in self_leg.get('self_leg_cf_candidates', []):
    ws_ok, ws_latency, ws_err = agent.probe_self_leg_websocket_path(ip, int(self_leg['dial_port']), str(self_leg.get('cf_sni_host') or self_leg.get('dial_host') or ''), str(self_leg.get('path_prefix') or ''), int(self_leg.get('target_sidecar_port') or 0), timeout=3.0)
    edge_latency = agent.self_leg_candidate_edge_latency_ms(ip, int(self_leg['dial_port']), str(self_leg.get('cf_sni_host') or self_leg.get('dial_host') or ''), timeout=3.0) if ws_ok else None
    results.append({'ip': ip, 'websocket_ok': ws_ok, 'websocket_latency_ms': ws_latency, 'edge_latency_ms': edge_latency, 'error': ws_err})
print(json.dumps(results, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-forward47-candidates.py root@114.80.33.50:/tmp/codex-forward47-candidates.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex-forward47-candidates.py'