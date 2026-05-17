#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/f47_stability_probe.py <<'PY'
import copy, json, pathlib, importlib.util, sys, urllib.request, time
sys.path.insert(0, '/usr/local/bin')
spec = importlib.util.spec_from_file_location('client_agent_mod', '/usr/local/bin/leikwan-client-agent.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
config_path = pathlib.Path('/etc/leikwan-client-agent/config.json')
cfg = json.loads(config_path.read_text(encoding='utf-8'))
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id',0)) == 47)
print('=== same agent repeated select ===')
agent = mod.ClientAgent(config_path)
for i in range(6):
    f = copy.deepcopy(forward)
    selected = agent.select_forward_candidates(f)
    self_leg = next((x for x in selected if x.get('candidate_kind')=='self_leg'), None)
    print(json.dumps({
        'iter': i,
        'selected_primary': selected[0].get('candidate_kind') if selected else None,
        'self_leg_ip': self_leg.get('dial_connect_ip') if self_leg else None,
        'self_leg_rtt': self_leg.get('measured_rtt_ms') if self_leg else None,
    }, ensure_ascii=False))
    time.sleep(1)
print('=== new agent each time ===')
for i in range(6):
    agent2 = mod.ClientAgent(config_path)
    f = copy.deepcopy(forward)
    selected = agent2.select_forward_candidates(f)
    self_leg = next((x for x in selected if x.get('candidate_kind')=='self_leg'), None)
    print(json.dumps({
        'iter': i,
        'selected_primary': selected[0].get('candidate_kind') if selected else None,
        'self_leg_ip': self_leg.get('dial_connect_ip') if self_leg else None,
        'self_leg_rtt': self_leg.get('measured_rtt_ms') if self_leg else None,
    }, ensure_ascii=False))
    time.sleep(1)
print('=== current best_ip cache ===')
cache = pathlib.Path('/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json')
if cache.exists():
    print(cache.read_text(encoding='utf-8'))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/f47_stability_probe.py root@114.80.33.50:/tmp/f47_stability_probe.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/f47_stability_probe.py'