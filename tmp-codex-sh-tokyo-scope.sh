#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_sh_tokyo_scope.py <<'PY'
import json, pathlib, urllib.request
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
req=urllib.request.Request(cfg['master_url'].rstrip()+'/api/client/configs/pull', headers={'Accept':'application/json','Authorization':'Bearer '+cfg['agent_token']})
with urllib.request.urlopen(req, timeout=30) as r:
    payload=json.load(r)
for fid in (47,59):
    f=next((x for x in payload.get('forwards',[]) if int(x.get('forward_id',0))==fid), None)
    print(f'=== payload forward {fid} ===')
    if not f:
        print('missing'); continue
    print(json.dumps({k:f.get(k) for k in ['forward_id','listen_port','mode','client_mode','runtime_preference','forward_combination_runtime_preference','backend_mode','target','bond_sidecar_point_id','bond_sidecar_point_name','bond_sidecar_host','bond_sidecar_port','mptcp_sidecar_port','bond_sidecar_cf_listen_port','sni','path_prefix','transport']}, ensure_ascii=False, indent=2))
    cps=f.get('candidate_pool') or []
    print('candidate_pool', len(cps))
    for c in cps[:10]:
        print(json.dumps({k:c.get(k) for k in ['candidate_kind','endpoint_id','host','port','runtime_capabilities','selected_primary','probe_transport','dial_host','dial_connect_ip','dial_port','cf_sni_host','path_prefix','target_sidecar_port','target_sidecar_bond_port','target_sidecar_mptcp_port','measured_rtt_ms','origin_group_key','origin_group_order']}, ensure_ascii=False))
for path in ['/etc/leikwan/mptcp-forward-47.json','/usr/local/bin/leikwan-client-start-47.sh','/etc/leikwan/bond-forward-59.json','/usr/local/bin/leikwan-client-start-59.sh','/etc/leikwan/mptcp-forward-59.json']:
    p=pathlib.Path(path)
    print(f'=== runtime {path} exists={p.exists()} ===')
    if p.exists():
        text=p.read_text(errors='ignore')
        print(text[:4000])
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_sh_tokyo_scope.py root@114.80.33.50:/tmp/codex_sh_tokyo_scope.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/codex_sh_tokyo_scope.py; echo ===services===; systemctl is-active leikwan-client-47.service leikwan-client-59.service leikwan-client-agent.service || true; ss -ltnp | grep -E ":(11035|32501|39559)" || true; journalctl -u leikwan-client-agent.service --since "-3 min" --no-pager | grep -E "forward 47|forward 59|self-leg|applying|applied|refreshed|failed|mptcp mode" || true'