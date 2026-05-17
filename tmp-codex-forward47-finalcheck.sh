#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/codex-forward47-finalcheck.py <<'PY'
import json, pathlib, importlib.util, sys, urllib.request, subprocess
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
probe_candidate = dict(next(item for item in forward['candidate_pool'] if item.get('candidate_kind') == 'self_leg'))
probe = agent.self_leg_direct_cf_connect_probe(forward, probe_candidate, timeout=3.0)
agent.apply_forward(forward)
config = json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
script = pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh').read_text()
print(json.dumps({
    'probe_result': probe,
    'probe_chosen_ip': probe_candidate.get('dial_connect_ip'),
    'rendered_direct_legs': config.get('direct_legs'),
    'rendered_remote_host': config.get('remote_host'),
    'rendered_remote_port': config.get('remote_port'),
    'rendered_relay_port': config.get('relay_port'),
    'script_contains_8899': '127.0.0.1:8899' in script,
    'script_contains_8898': '127.0.0.1:8898' in script,
}, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-forward47-finalcheck.py root@114.80.33.50:/tmp/codex-forward47-finalcheck.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex-forward47-finalcheck.py; echo ===; systemctl restart leikwan-client-47.service; sleep 5; systemctl is-active leikwan-client-47.service; ss -ltnp | grep 11035 || true; ps -ef | grep -E "wstunnel|leikwan-mptcp-relay" | grep 47 || true; echo ===; timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 50M -t 8 || true; echo ===; journalctl -u leikwan-client-47.service -n 80 --no-pager || true'