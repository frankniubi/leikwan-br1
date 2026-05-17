#!/usr/bin/env bash
set -euo pipefail
CLIENT=root@114.80.33.50
PASS='Frank20021212.'
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 "$CLIENT" 'bash -s' <<'REMOTE'
set +euo pipefail
echo '=== pre ==='
date -Is
printf 'agent=%s/%s\n' "$(systemctl is-active leikwan-client-agent.service 2>/dev/null)" "$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)"
printf 'f47=%s\n' "$(systemctl is-active leikwan-client-47.service 2>/dev/null)"
ss -ltnp | grep -E ':(11035)\b' || true
echo '=== config ==='
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
j=json.loads(p.read_text())
print({k:j.get(k) for k in ['bond_mode','remote_host','remote_port','relay_host','relay_port','subflow_limit','policy_mode']})
print('direct_legs=', j.get('direct_legs'))
print('endpoints=', [(e.get('endpoint_id'), e.get('host'), e.get('port'), e.get('leg_role'), e.get('health_role'), e.get('one_leg_rtt_ms'), e.get('last_latency_ms')) for e in j.get('endpoints',[])])
print('underlays=', [(u.get('mode'), u.get('interface_name'), [(l.get('endpoint_id'), l.get('peer_public_host'), l.get('peer_udp_port'), l.get('local_address'), l.get('mptcp_remote_address')) for l in u.get('legs',[])]) for u in j.get('underlays',[])])
PY
echo '=== iperf reverse -b 300M -t 12 ==='
timeout 40 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 12 --connect-timeout 3000
rc=$?
echo "iperf_rc=$rc"
echo '=== post/log ==='
date -Is
systemctl is-active leikwan-client-47.service 2>/dev/null || true
journalctl -u leikwan-client-47.service --since '-90 sec' --no-pager -o short-iso | tail -n 120 || true
echo '=== agent f47 recent ==='
journalctl -u leikwan-client-agent.service --since '-90 sec' --no-pager -o short-iso | grep -Ei 'forward 47|self|direct|mptcp|health|failed|restart|selected|probe' | tail -n 80 || true
REMOTE
