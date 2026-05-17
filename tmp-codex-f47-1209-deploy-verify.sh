#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_PANEL|PASS_CLIENT|SSH_OPTS|WORKDIR|PANEL_ROOT)=' tmp-codex-live-converge4.sh)
LOCAL_SHA=$(sha256sum client-agent/client_agent.py | awk '{print $1}')
META=$(python3 - <<'PY'
import json, pathlib
v=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))['client_agent']
print(v.get('version','') + ' ' + v.get('sha256','') + ' ' + str(v.get('size_bytes','')))
PY
)
read -r META_VER META_SHA META_SIZE <<<"$META"
if [[ "$META_VER" != "2026.05.12.9" || "$LOCAL_SHA" != "$META_SHA" ]]; then
  echo "metadata mismatch local=$LOCAL_SHA meta=$META" >&2
  exit 1
fi
echo "=== publish 2026.05.12.9 $LOCAL_SHA ==="
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py root@114.80.33.50:/tmp/client_agent.py >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py $PANEL_ROOT/artifacts/client-agent.py; install -m 0644 /tmp/versions.json $PANEL_ROOT/artifacts/versions.json; python3 - <<'PY'
import json, hashlib, pathlib
root=pathlib.Path('$PANEL_ROOT')
b=(root/'artifacts/client-agent.py').read_bytes()
meta=json.loads((root/'artifacts/versions.json').read_text())['client_agent']
out={'sha':hashlib.sha256(b).hexdigest(),'version':meta.get('version'),'meta_sha':meta.get('sha256'),'size':meta.get('size_bytes'),'actual_size':len(b)}
print(json.dumps(out, indent=2))
assert out['sha']==meta.get('sha256')
assert out['version']=='2026.05.12.9'
assert out['actual_size']==int(meta.get('size_bytes'))
PY"
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "set -e; install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/leikwan-client-agent.py; python3 - <<'PY'
import importlib.util, hashlib, pathlib
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('agent', str(p)); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
print('client_sha', hashlib.sha256(p.read_bytes()).hexdigest())
print('agent_version', getattr(m, 'AGENT_VERSION', ''))
assert hashlib.sha256(p.read_bytes()).hexdigest() == '$LOCAL_SHA'
assert getattr(m, 'AGENT_VERSION', '') == '2026.05.12.9'
PY
systemctl restart leikwan-client-agent.service; sleep 12; echo agent_active=$(systemctl is-active leikwan-client-agent.service); cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true"
cat > /tmp/codex-f47-1209-verify.py <<'PY'
import copy, hashlib, importlib.util, json, pathlib, re, subprocess, sys, time, urllib.request
sys.path.insert(0, '/usr/local/bin')
AGENT_PATH = pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec = importlib.util.spec_from_file_location('client_agent_mod', str(AGENT_PATH))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
config_path = pathlib.Path('/etc/leikwan-client-agent/config.json')
cfg = json.loads(config_path.read_text())
state_dir = pathlib.Path(cfg.get('state_dir', '/var/lib/leikwan-client-agent'))
cache_path = state_dir / 'self_leg_cf_best_ips.json'
if cache_path.exists():
    backup = state_dir / f'self_leg_cf_best_ips.json.bak-1209-{int(time.time())}'
    backup.write_text(cache_path.read_text(), encoding='utf-8')
    cache_path.unlink()
else:
    backup = None
req = urllib.request.Request(cfg['master_url'].rstrip('/') + '/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token'],'User-Agent':'Codex-F47-1209/1.0'})
with urllib.request.urlopen(req, timeout=30) as resp:
    payload=json.load(resp)
forward=next(item for item in payload['forwards'] if int(item.get('forward_id',0) or 0)==47)

def select_summary(agent, label):
    selected=agent.select_forward_candidates(copy.deepcopy(forward))
    self_leg=next((x for x in selected if x.get('candidate_kind')=='self_leg'), {})
    primary=selected[0] if selected else {}
    return {'label':label,'primary_kind':primary.get('candidate_kind'),'primary_ip':primary.get('dial_connect_ip'),'primary_rtt':primary.get('measured_rtt_ms'),'primary_ep':primary.get('endpoint_id'),'self_leg_ip':self_leg.get('dial_connect_ip'),'self_leg_rtt':self_leg.get('measured_rtt_ms'),'self_leg_ready':self_leg.get('ready')}
agent=mod.ClientAgent(config_path)
same=[]
for i in range(6):
    same.append(select_summary(agent, f'same-{i}'))
    time.sleep(1)
pre_apply=select_summary(agent, 'pre_apply')
agent.apply_forward(copy.deepcopy(forward))
time.sleep(5)
# Watch for service restarts/churn for 65s, including one health interval.
def start_mono(service):
    r=subprocess.run(['systemctl','show',service,'-p','ActiveEnterTimestampMonotonic','--value'],text=True,capture_output=True)
    return r.stdout.strip()
start0=start_mono('leikwan-client-47.service')
script0=pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh').read_text(errors='ignore')
config0=json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
time.sleep(65)
start1=start_mono('leikwan-client-47.service')
script1=pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh').read_text(errors='ignore')
config1=json.loads(pathlib.Path('/etc/leikwan/mptcp-forward-47.json').read_text())
cache_after=json.loads(cache_path.read_text()) if cache_path.exists() else {}
agent_active=subprocess.run(['systemctl','is-active','leikwan-client-agent.service'],text=True,capture_output=True).stdout.strip()
svc_active=subprocess.run(['systemctl','is-active','leikwan-client-47.service'],text=True,capture_output=True).stdout.strip()
print(json.dumps({
 'agent_sha': hashlib.sha256(AGENT_PATH.read_bytes()).hexdigest(),
 'agent_version_const': getattr(mod,'AGENT_VERSION',''),
 'cache_backup': str(backup) if backup else None,
 'same_agent_runs': same,
 'pre_apply': pre_apply,
 'agent_active': agent_active,
 'service_active': svc_active,
 'service_start_before_watch': start0,
 'service_start_after_watch': start1,
 'service_restarted_during_watch': start0 != start1,
 'script_changed_during_watch': script0 != script1,
 'runtime_before': {k:config0.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','subflow_limit']},
 'runtime_after': {k:config1.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','subflow_limit']},
 'wss_before': re.findall(r'wss://\S+', script0),
 'wss_after': re.findall(r'wss://\S+', script1),
 'has_8899_after': '127.0.0.1:8899' in script1,
 'has_8898_after': '127.0.0.1:8898' in script1,
 'best_ip_cache_after': cache_after,
}, indent=2, ensure_ascii=False))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-f47-1209-verify.py root@114.80.33.50:/tmp/codex-f47-1209-verify.py >/dev/null
echo "=== verify 47 convergence/churn ==="
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "python3 /tmp/codex-f47-1209-verify.py"
echo "=== iperf + logs ==="
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "set +e; echo active_agent=\$(systemctl is-active leikwan-client-agent.service); echo active_47=\$(systemctl is-active leikwan-client-47.service); ss -ltnp | grep -E ':(11035|39559)' || true; timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 50M -t 12; echo iperf_exit=\$?; echo ===process===; ps -ef | grep -E 'leikwan-mptcp-relay client --config /etc/leikwan/mptcp-forward-47|wstunnel client -L tcp://127.0.0.1:39559' | grep -v grep || true; echo ===journal47===; journalctl -u leikwan-client-47.service --since '-3 min' --no-pager | tail -n 180; echo ===agent47===; journalctl -u leikwan-client-agent.service --since '-3 min' --no-pager | grep -E 'forward 47|self-update|leikwan-client-47|Stopping leikwan-client-agent|Started leikwan-client-agent' || true"