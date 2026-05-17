#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
export SSHPASS='Frank20021212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
CLIENT=root@114.80.33.50
sshpass -e scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py "$CLIENT:/tmp/" >/dev/null
sshpass -e ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'RS'
set -euo pipefail
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
python3 - <<'PY'
import importlib.util, json, pathlib, time, hashlib
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
print('agent_version', m.AGENT_VERSION)
print('client_agent_sha256', hashlib.sha256(pathlib.Path('/usr/local/bin/leikwan-client-agent.py').read_bytes()).hexdigest())
state=pathlib.Path('/var/lib/leikwan-client-agent/agent_update_state.json')
payload={}
if state.exists():
    try: payload=json.loads(state.read_text())
    except Exception: payload={}
token=str(payload.get('request_token','') or payload.get('last_completed_request_token',''))
payload.update({'request_token': token, 'status':'success', 'last_completed_request_token':token, 'last_completed_at':time.time(), 'installed_version':m.AGENT_VERSION, 'last_error':''})
state.parent.mkdir(parents=True, exist_ok=True)
state.write_text(json.dumps(payload, indent=2), encoding='utf-8')
PY
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json
systemctl daemon-reload
systemctl reset-failed leikwan-client-agent.service leikwan-client-47.service || true
systemctl restart leikwan-client-agent.service
sleep 42
systemctl is-active leikwan-client-agent.service leikwan-client-47.service || true
python3 - <<'PY'
import json, pathlib, importlib.util, urllib.request, hashlib
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
print('AGENT_VERSION', ca.AGENT_VERSION)
print('agent_sha256', hashlib.sha256(pathlib.Path('/usr/local/bin/leikwan-client-agent.py').read_bytes()).hexdigest())
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=40) as r: payload=json.load(r)
f=next((x for x in payload.get('forwards',[]) if int(x.get('forward_id',0))==47), None)
if f:
    selected=agent.select_forward_candidates(dict(f))
    rf=dict(f); rf['candidate_pool']=selected
    print('payload_has_bonding', isinstance(f.get('bonding'),dict), sorted((f.get('bonding') or {}).keys()) if isinstance(f.get('bonding'),dict) else None)
    print('selected', json.dumps([{k:c.get(k) for k in ['candidate_kind','endpoint_id','host','dial_connect_ip','selected_primary','measured_rtt_ms','target_sidecar_bond_port','target_sidecar_mptcp_port','last_error']} for c in selected], ensure_ascii=False))
    print('chosen_runtime_family', agent.choose_runtime_family(rf, agent.running_kernel_supports_mptcp()))
for p in ['/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/bond-forward-47.json','/usr/local/bin/leikwan-client-start-47.sh','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json']:
    path=pathlib.Path(p)
    print('FILE',p,'exists',path.exists(),'size',path.stat().st_size if path.exists() else '')
    if path.exists() and path.suffix=='.json':
        try:
            d=json.loads(path.read_text())
            if '47' in d and isinstance(d.get('47'),dict): print('applied47', json.dumps({k:d['47'].get(k) for k in ['published_mode','runtime_mode','managed_paths']}, indent=2))
            else: print('json-summary', json.dumps({k:d.get(k) for k in ['listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','endpoints'] if k in d}, ensure_ascii=False, indent=2)[:2000])
        except Exception as e: print('json err', repr(e))
PY
echo '=== listeners ==='
ss -ltnp | grep -E ':11035|:2173[0-9]|:2193[0-9]|:39559|:8899|:2053' || true
echo '=== start script ==='
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null | sed -E 's/(session-token '\''?)[A-Za-z0-9._-]+/\1REDACTED/g' || true
echo '=== recent agent ==='
journalctl -u leikwan-client-agent.service --since '-2 min' --no-pager -n 160 || true
echo '=== recent f47 ==='
journalctl -u leikwan-client-47.service --since '-2 min' --no-pager -n 160 || true
RS
