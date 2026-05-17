#!/usr/bin/env bash
set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set -euo pipefail
cat > /tmp/codex_direct_probe_selfleg.py <<'PY'
import json, pathlib, urllib.request, importlib.util, sys, time, traceback
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip('/')+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=40) as r:
    payload=json.load(r)
forward=next(x for x in payload.get('forwards',[]) if int(x.get('forward_id',0))==47)
candidate=next(x for x in forward.get('candidate_pool',[]) if x.get('candidate_kind')=='self_leg')
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
print('VERSION', ca.AGENT_VERSION)
print('state_best_map=', pathlib.Path('/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json').read_text() if pathlib.Path('/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json').exists() else 'MISSING')
print('candidate0', json.dumps(candidate, ensure_ascii=False))
for i in range(1,5):
    c=dict(candidate)
    try:
        ok,lat,err=agent.self_leg_direct_cf_connect_probe(forward,c,timeout=4.0)
    except Exception as e:
        print('probe',i,'EXC',repr(e)); traceback.print_exc(); continue
    print('probe',i,'ok=',ok,'lat=',lat,'err=',err,'chosen=',c.get('dial_connect_ip'),'ready=',c.get('ready'),'last_error=',c.get('last_error'))
    time.sleep(0.5)
print('cache', json.dumps(getattr(agent,'self_leg_probe_cache',{}), ensure_ascii=False, indent=2)[:3000])
# individual websocket probes
for ip in candidate.get('self_leg_cf_candidates',[]):
    try:
        res=agent.probe_self_leg_websocket_path(ip, int(candidate.get('dial_port') or 2053), candidate.get('cf_sni_host') or 'nrt1.nbwly.com', candidate.get('path_prefix') or forward.get('path_prefix'), int(candidate.get('target_sidecar_port') or 8899), timeout=4.0)
    except Exception as e:
        res=('EXC',None,repr(e))
    print('ws',ip,res)
PY
python3 /tmp/codex_direct_probe_selfleg.py
REMOTE