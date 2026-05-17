#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_select_choose_f47_f59.py <<'PY'
import json, pathlib, urllib.request, importlib.util, sys, inspect
sys.path.insert(0, '/usr/local/bin')
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=30) as r: payload=json.load(r)
print('agent_version', ca.AGENT_VERSION)
for fid in (47,59):
    f=next((x for x in payload['forwards'] if int(x.get('forward_id',0))==fid), None)
    print('=== forward',fid,'===')
    if not f:
        print('missing'); continue
    selected=agent.select_forward_candidates(dict(f))
    rf=dict(f); rf['candidate_pool']=selected
    print('mode_before', f.get('mode'), 'runtime_pref', f.get('runtime_preference'), 'choose', agent.choose_runtime_family(rf, agent.running_kernel_supports_mptcp()))
    for c in selected:
        print(json.dumps({k:c.get(k) for k in ['candidate_kind','endpoint_id','host','runtime_capabilities','selected_primary','probe_transport','target_sidecar_port','target_sidecar_bond_port','target_sidecar_mptcp_port','dial_connect_ip','dial_port','cf_sni_host','path_prefix','measured_rtt_ms']}, ensure_ascii=False))
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_select_choose_f47_f59.py root@114.80.33.50:/tmp/codex_select_choose_f47_f59.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e; python3 /tmp/codex_select_choose_f47_f59.py; echo ===services===; systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service || true; echo ===scripts===; grep -E "wstunnel client|leikwan-bond client|leikwan-mptcp-relay" -n /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-59.sh 2>/dev/null | head -80; echo ===agentlog===; journalctl -u leikwan-client-agent.service --since "-2 min" --no-pager | grep -E "forward 47|forward 59|requested client self-update|mptcp mode missing|applied|failed|refreshed|applying" || true'