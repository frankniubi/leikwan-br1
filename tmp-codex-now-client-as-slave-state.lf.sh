#!/usr/bin/env bash
set -euo pipefail
SSHPASS='Frank20021212.'
SSH="sshpass -p $SSHPASS ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 root@114.80.33.50"
$SSH 'bash -s' <<'REMOTE'
set -u
printf "=== host/time ===\n"; hostname; date -Is; uptime || true
printf "=== agent/service ===\n"; systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true; systemctl is-active leikwan-client-agent.service 2>/dev/null || true; systemctl status leikwan-client-agent.service --no-pager -l | sed -n '1,35p' || true
printf "=== agent version/hash ===\n"; grep -m1 'AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py || true; sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client 2>/dev/null || true; file /usr/local/bin/leikwan-mptcp-client 2>/dev/null || true
printf "=== f47 services ===\n"; systemctl is-active leikwan-client-47.service 2>/dev/null || true; systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,60p' || true
printf "=== f47 listener/process ===\n"; ss -ltnp | egrep ':(11035|2053|8898|8899|5201|5202)\b' || true; pgrep -af 'leikwan.*47|wstunnel|iperf3' || true
printf "=== applied state ===\n"; python3 - <<'PY'
import json, pathlib
for p in ['/var/lib/leikwan-client-agent/applied-forwards.json','/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/bond-endpoint-health.json','/var/lib/leikwan-client-agent/latency-primary-state.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  print(json.dumps(data if p.endswith('mptcp-forward-47.json') else data.get('47', data), ensure_ascii=False, indent=2)[:6000])
 except Exception as e:
  print(type(e).__name__, e)
PY
printf "=== start script 47 ===\n"; sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
printf "=== recent f47 journal ===\n"; journalctl -u leikwan-client-47.service -n 120 --no-pager -o short-iso || true
printf "=== recent agent journal f47/self-leg ===\n"; journalctl -u leikwan-client-agent.service -n 260 --no-pager -o short-iso | egrep -i 'forward 47|self|direct|bond|mptcp|restart|invalid|magic|520|8899|2053|selected|probe' | tail -n 160 || true
REMOTE
