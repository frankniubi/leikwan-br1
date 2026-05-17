#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== freeze check $(date -Is) ==="
systemctl disable --now leikwan-client-agent.service 2>&1 | sed -n '1,5p'
echo "agent_enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "agent_active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)"
echo "svc47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) svc47_enabled=$(systemctl is-enabled leikwan-client-47.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true) onegood_enabled=$(systemctl is-enabled leikwan-client-47-onegood.service 2>/dev/null || true)"
echo '--- processes ---'
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-start-47|mptcp-forward-47|leikwan-mptcp-relay|leikwan-bond|leikwan-ingress client|wstunnel client' | grep -v grep | sed -n '1,260p'
echo '--- listeners ---'
ss -ltnp | grep -E ':(11035|39559|2914[0-9]|2916[0-9]|217[0-9]{2}|22740)' || true
echo '--- config summary ---'
python3 - <<'PY'
import json, pathlib
for f in ['/etc/leikwan/mptcp-forward-47.json','/run/leikwan/bond-leg-health-forward-47.json','/var/lib/leikwan-client-agent/latency-primary-state.json','/var/lib/leikwan-client-agent/self-leg-best-ip-cache.json']:
 print('###',f)
 p=pathlib.Path(f)
 if not p.exists(): print('missing'); continue
 try:
  d=json.loads(p.read_text())
  if f.endswith('mptcp-forward-47.json'):
   print(json.dumps({k:d.get(k) for k in ['forward_id','listen_host','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','bond_legs','leg_mode','runtime_preference']}, indent=2))
  else:
   print(json.dumps(d, indent=2)[:4000])
 except Exception as e:
  print('json_err',repr(e)); print(p.read_text()[:2000])
PY
echo '--- recent logs 47/onegood ---'
journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-90 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|session established|accepted local connection|Operation timed out|local_write_failed|recv_reset|WARN|ERROR|520|Invalid status' | tail -200 || true
REMOTE