#!/usr/bin/env bash
set -euo pipefail
CLIENT=root@114.80.33.50
PASS='Frank20021212.'
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 "$CLIENT" 'bash -s' <<'REMOTE'
set +euo pipefail
echo '=== final latency/stability snapshot ==='
date -Is
printf 'agent=%s/%s f47=%s\n' "$(systemctl is-active leikwan-client-agent.service 2>/dev/null)" "$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)" "$(systemctl is-active leikwan-client-47.service 2>/dev/null)"
ss -ltnp | grep -E ':(11035)\b' || true
ip -4 addr show dev lw-self-47 2>/dev/null | sed -n '1,4p' || true
wg show lw-self-47 2>/dev/null || true
echo '-- ping self-leg sidecar private 10.204.53.42 --'
ping -c 10 -i 0.2 -W 1 10.204.53.42 || true
echo '-- ping selected slave-sidecar private 10.203.201.179 --'
ping -c 10 -i 0.2 -W 1 10.203.201.179 || true
echo '-- current f47 health summary --'
python3 - <<'PY'
import json, pathlib
for p in ['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/bond-endpoint-health.json']:
 print('FILE',p)
 try: j=json.loads(pathlib.Path(p).read_text())
 except Exception as e: print(type(e).__name__, e); continue
 if 'mptcp-forward' in p:
  print('route', {k:j.get(k) for k in ['bond_mode','remote_host','remote_port','relay_host','relay_port','subflow_limit']})
  print('direct_legs_len', len(j.get('direct_legs') or []))
  print('endpoints', [(e.get('endpoint_id'), e.get('host'), e.get('health_role'), e.get('one_leg_rtt_ms'), e.get('last_latency_ms')) for e in j.get('endpoints',[])])
 else:
  print(json.dumps({k:v for k,v in j.items() if k in ['317','self_leg','0']}, indent=2)[:2000])
PY
REMOTE
