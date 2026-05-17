#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'

echo '=== refresh local versions.json hashes ==='
python3 - <<'PY'
import json, hashlib, pathlib
p = pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/artifacts/versions.json')
versions = json.loads(p.read_text(encoding='utf-8'))
for key, rel, ver in [
    ('client_agent','/mnt/c/Users/frankniubi/Downloads/leikwan-br1/client-agent/client_agent.py','2026.05.12.7'),
]:
    b = pathlib.Path(rel).read_bytes()
    versions[key]['sha256'] = hashlib.sha256(b).hexdigest()
    versions[key]['size_bytes'] = len(b)
    versions[key]['version'] = ver
p.write_text(json.dumps(versions, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps(versions['client_agent'], indent=2))
PY

echo '=== publish latest client agent to panel + client ==='
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" "$WORKDIR/client-agent/client_agent.py" "$WORKDIR/artifacts/versions.json" root@10.0.10.196:/tmp/
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" "$WORKDIR/client-agent/client_agent.py" root@114.80.33.50:/tmp/client_agent.py
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/artifacts/client-agent.py; install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; sha256sum $PANEL_ROOT/artifacts/client-agent.py; sed -n '45,50p' $PANEL_ROOT/artifacts/versions.json"
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py; sha256sum /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/leikwan-client-agent.py"

echo '=== clear best-ip cache, restart agent, reapply 47, dump convergence + traffic ==='
cat > /tmp/codex-forward47-converge4.py <<'PY'
import json, pathlib, importlib.util, sys, urllib.request, time
sys.path.insert(0, '/usr/local/bin')
spec = importlib.util.spec_from_file_location('client_agent_mod', '/usr/local/bin/leikwan-client-agent.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
agent = mod.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
state_dir = pathlib.Path('/var/lib/leikwan-client-agent')
cache_path = state_dir / 'self_leg_cf_best_ips.json'
backup_path = state_dir / f'self_leg_cf_best_ips.json.bak-{int(time.time())}'
if cache_path.exists():
    backup_path.write_text(cache_path.read_text(encoding='utf-8'), encoding='utf-8')
    cache_path.unlink()
cfg = json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text(encoding='utf-8'))
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id',0)) == 47)
probe_candidate = dict(next(item for item in forward['candidate_pool'] if item.get('candidate_kind') == 'self_leg'))
probe = agent.self_leg_direct_cf_connect_probe(forward, probe_candidate, timeout=3.0)
selected = agent.select_forward_candidates(forward)
selected_self = next((item for item in selected if item.get('candidate_kind') == 'self_leg'), None)
agent.apply_forward(forward)
config = json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
start_script = pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh').read_text(encoding='utf-8', errors='ignore')
cache_after = json.loads(cache_path.read_text(encoding='utf-8')) if cache_path.exists() else {}
summary = {
    'probe_result': probe,
    'probe_chosen_ip': probe_candidate.get('dial_connect_ip'),
    'selected_self_leg': selected_self,
    'best_ip_cache_after': cache_after,
    'rendered_direct_legs': config.get('direct_legs'),
    'rendered_remote_host': config.get('remote_host'),
    'rendered_remote_port': config.get('remote_port'),
    'rendered_bond_mode': config.get('bond_mode'),
    'script_contains_8899': '127.0.0.1:8899' in start_script,
    'script_contains_8898': '127.0.0.1:8898' in start_script,
    'start_script': start_script,
}
print(json.dumps(summary, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-forward47-converge4.py root@114.80.33.50:/tmp/codex-forward47-converge4.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "systemctl restart leikwan-client-agent.service; sleep 8; systemctl is-active leikwan-client-agent.service; python3 /tmp/codex-forward47-converge4.py; echo ===; systemctl is-active leikwan-client-47.service || true; ss -ltnp | grep 11035 || true; ps -ef | grep -E 'wstunnel|leikwan-mptcp-relay' | grep 47 || true; echo ===; timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 50M -t 8 || true; echo ===; journalctl -u leikwan-client-47.service -n 60 --no-pager || true"