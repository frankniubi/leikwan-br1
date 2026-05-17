#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank20021212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_probe_selection.py <<'PY'
import json, pathlib, urllib.request, importlib.util, sys, time, os
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=40) as r:
    payload=json.load(r)
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan-client-agent/config.json'))
print('AGENT_VERSION', ca.AGENT_VERSION)
print('kernel_mptcp', agent.running_kernel_supports_mptcp())
for fid in [47]:
    f=next((x for x in payload.get('forwards',[]) if int(x.get('forward_id',0))==fid), None)
    print('\n=== forward',fid,'payload ===')
    if not f:
        print('missing'); continue
    print(json.dumps({k:f.get(k) for k in ['forward_id','mode','client_mode','runtime_preference','forward_combination_runtime_preference','backend_mode','transport','listen_port','target_host','target_port','target','sni','path_prefix','helper_group_cap','desired_latency_ms']}, ensure_ascii=False, indent=2))
    print('mptcp', json.dumps({k:(f.get('mptcp') or {}).get(k) for k in ['tcp_session_token','sidecar_port','remote_port','sidecar_host','remote_host','subflow_limit']}, ensure_ascii=False, indent=2))
    print('bonding', json.dumps({k:(f.get('bonding') or {}).get(k) for k in ['session_token','sidecar_port','remote_port','sidecar_host','remote_host','chunk_size','max_window_bytes']}, ensure_ascii=False, indent=2))
    print('candidate_pool raw:')
    for c in f.get('candidate_pool',[]):
        if not isinstance(c,dict): continue
        d={k:c.get(k) for k in ['candidate_kind','endpoint_id','host','port','runtime_capabilities','probe_transport','dial_host','dial_connect_ip','self_leg_cf_candidates','dial_port','cf_sni_host','path_prefix','target_sidecar_port','target_sidecar_bond_port','target_sidecar_mptcp_port','origin_group_key','origin_group_order','ready','measured_rtt_ms','one_leg_rtt_ms','latency_ewma_ms','last_latency_ms']}
        print(json.dumps(d, ensure_ascii=False))
    selected=agent.select_forward_candidates(dict(f))
    print('selected:')
    for c in selected:
        print(json.dumps({k:c.get(k) for k in ['candidate_kind','endpoint_id','host','dial_connect_ip','ready','selected_primary','measured_rtt_ms','last_error','runtime_capabilities','target_sidecar_port','target_sidecar_bond_port','target_sidecar_mptcp_port']}, ensure_ascii=False))
    rf=dict(f); rf['candidate_pool']=selected
    print('runtime_family', agent.choose_runtime_family(rf, agent.running_kernel_supports_mptcp()))
    print('mptcp_ready', agent.forward_has_mptcp_runtime_config(f))
PY
sshpass -e scp "${SSH_OPTS[@]}" /tmp/codex_probe_selection.py root@114.80.33.50:/tmp/codex_probe_selection.py >/dev/null
sshpass -e ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex_probe_selection.py'
