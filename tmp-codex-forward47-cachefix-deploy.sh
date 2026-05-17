#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_PANEL|PASS_CLIENT|SSH_OPTS|WORKDIR|PANEL_ROOT)=' tmp-codex-live-converge4.sh)
LOCAL_SHA=$(sha256sum client-agent/client_agent.py | awk '{print $1}')
META_SHA=$(python3 - <<'PY'
import json, pathlib
v=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))['client_agent']
print(v.get('sha256',''))
PY
)
META_VER=$(python3 - <<'PY'
import json, pathlib
v=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))['client_agent']
print(v.get('version',''))
PY
)
if [[ "$LOCAL_SHA" != "$META_SHA" ]]; then
  echo "metadata sha mismatch local=$LOCAL_SHA meta=$META_SHA" >&2
  exit 1
fi
if [[ "$META_VER" != "2026.05.12.8" ]]; then
  echo "metadata version mismatch meta=$META_VER" >&2
  exit 1
fi
echo "=== publish client_agent $META_VER $LOCAL_SHA ==="
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py root@114.80.33.50:/tmp/client_agent.py >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/artifacts/client-agent.py; install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; python3 - <<'PY'
import json, hashlib, pathlib
root=pathlib.Path('$PANEL_ROOT')
agent=root/'artifacts/client-agent.py'
meta=json.loads((root/'artifacts/versions.json').read_text(encoding='utf-8'))['client_agent']
b=agent.read_bytes()
print('panel_sha', hashlib.sha256(b).hexdigest())
print('panel_version', meta.get('version'), 'meta_sha', meta.get('sha256'), 'size', meta.get('size_bytes'), 'actual_size', len(b))
assert hashlib.sha256(b).hexdigest() == meta.get('sha256')
assert len(b) == int(meta.get('size_bytes'))
PY"
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "set -e; install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/leikwan-client-agent.py; sha256sum /usr/local/bin/leikwan-client-agent.py; systemctl restart leikwan-client-agent.service; sleep 8; systemctl is-active leikwan-client-agent.service"

cat > /tmp/codex-forward47-cachefix-verify.py <<'PY'
import copy, hashlib, importlib.util, json, pathlib, subprocess, sys, time, urllib.request
sys.path.insert(0, '/usr/local/bin')
AGENT_PATH = pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec = importlib.util.spec_from_file_location('client_agent_mod', str(AGENT_PATH))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
config_path = pathlib.Path('/etc/leikwan-client-agent/config.json')
cfg = json.loads(config_path.read_text(encoding='utf-8'))
state_dir = pathlib.Path(cfg.get('state_dir', '/var/lib/leikwan-client-agent'))
cache_path = state_dir / 'self_leg_cf_best_ips.json'
backup = None
if cache_path.exists():
    backup = state_dir / f'self_leg_cf_best_ips.json.bak-cachefix-{int(time.time())}'
    backup.write_text(cache_path.read_text(encoding='utf-8'), encoding='utf-8')
    cache_path.unlink()
req = urllib.request.Request(
    cfg['master_url'].rstrip('/') + '/api/client/configs/pull',
    headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-Forward47-CacheFix/1.0'},
)
with urllib.request.urlopen(req, timeout=30) as resp:
    payload = json.load(resp)
forward = next(item for item in payload['forwards'] if int(item.get('forward_id', 0) or 0) == 47)

def summarize_selection(agent, forward_obj, idx):
    selected = agent.select_forward_candidates(copy.deepcopy(forward_obj))
    self_leg = next((x for x in selected if x.get('candidate_kind') == 'self_leg'), None)
    primary = selected[0] if selected else {}
    return {
        'iter': idx,
        'primary_kind': primary.get('candidate_kind'),
        'primary_rtt': primary.get('measured_rtt_ms'),
        'primary_ip': primary.get('dial_connect_ip'),
        'self_leg_ip': self_leg.get('dial_connect_ip') if self_leg else None,
        'self_leg_rtt': self_leg.get('measured_rtt_ms') if self_leg else None,
        'self_leg_ready': self_leg.get('ready') if self_leg else None,
        'self_leg_error': self_leg.get('last_error') if self_leg else None,
    }

agent = mod.ClientAgent(config_path)
same_runs = []
for i in range(6):
    same_runs.append(summarize_selection(agent, forward, i))
    time.sleep(1.0)
fresh_runs = []
for i in range(6):
    fresh_runs.append(summarize_selection(mod.ClientAgent(config_path), forward, i))
    time.sleep(1.0)
selected_before_apply = summarize_selection(agent, forward, 'before_apply')
agent.apply_forward(copy.deepcopy(forward))
time.sleep(3)
config_file = pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
start_script_path = pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh')
runtime_config = json.loads(config_file.read_text(encoding='utf-8')) if config_file.exists() else {}
start_script = start_script_path.read_text(encoding='utf-8', errors='ignore') if start_script_path.exists() else ''
cache_after = json.loads(cache_path.read_text(encoding='utf-8')) if cache_path.exists() else {}
service_active = subprocess.run(['systemctl','is-active','leikwan-client-47.service'], text=True, capture_output=True, check=False).stdout.strip()
agent_active = subprocess.run(['systemctl','is-active','leikwan-client-agent.service'], text=True, capture_output=True, check=False).stdout.strip()
ps = subprocess.run("ps -ef | grep -E 'wstunnel|leikwan-mptcp-relay' | grep -v grep | grep -E '47|39559|2053|11035' || true", shell=True, text=True, capture_output=True, check=False).stdout.strip().splitlines()
ss = subprocess.run("ss -ltnp | grep ':11035' || true", shell=True, text=True, capture_output=True, check=False).stdout.strip().splitlines()
print(json.dumps({
    'agent_sha': hashlib.sha256(AGENT_PATH.read_bytes()).hexdigest(),
    'agent_version_const': getattr(mod, 'AGENT_VERSION', None),
    'cache_backup': str(backup) if backup else None,
    'agent_active': agent_active,
    'same_agent_runs': same_runs,
    'fresh_agent_runs': fresh_runs,
    'selected_before_apply': selected_before_apply,
    'best_ip_cache_after': cache_after,
    'rendered_direct_legs': runtime_config.get('direct_legs'),
    'rendered_remote_host': runtime_config.get('remote_host'),
    'rendered_remote_port': runtime_config.get('remote_port'),
    'rendered_bond_mode': runtime_config.get('bond_mode'),
    'service_active': service_active,
    'listener_11035': ss,
    'process_lines': ps,
    'script_has_8899': '127.0.0.1:8899' in start_script,
    'script_has_8898': '127.0.0.1:8898' in start_script,
    'wss_targets': [part for part in start_script.split() if part.startswith('wss://')],
}, ensure_ascii=False, indent=2))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-forward47-cachefix-verify.py root@114.80.33.50:/tmp/codex-forward47-cachefix-verify.py >/dev/null
echo "=== verify same-instance selection, reapply 47 ==="
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "python3 /tmp/codex-forward47-cachefix-verify.py"
echo "=== traffic test ==="
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "set +e; timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 50M -t 10; echo iperf_exit=\$?; echo ===service===; systemctl --no-pager -l status leikwan-client-47.service | sed -n '1,45p'; echo ===journal===; journalctl -u leikwan-client-47.service -n 100 --no-pager"
