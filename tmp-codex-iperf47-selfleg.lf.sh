#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set +e
echo '=== pre status ==='
date -Is
systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service || true
ss -ltnp | grep -E ':(11035|39559)\b' || true
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
if p.exists():
 d=json.loads(p.read_text())
 print(json.dumps({k:d.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','subflow_limit']}, indent=2))
PY
echo '=== local direct WST leg iperf -R -b 300M ==='
timeout 12 iperf3 -c 127.0.0.1 -p 39559 -R -b 300M -t 5 || true
echo '=== forward47 listener iperf -R -b 300M ==='
timeout 15 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5 || true
echo '=== post status/listeners ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service || true
ss -ltnp | grep -E ':(11035|39559)\b' || true
echo '=== f47 journal since test ==='
journalctl -u leikwan-client-47.service --since '-1 min' --no-pager -n 120 || true
echo '=== agent journal since test ==='
journalctl -u leikwan-client-agent.service --since '-1 min' --no-pager -n 80 || true
RS
