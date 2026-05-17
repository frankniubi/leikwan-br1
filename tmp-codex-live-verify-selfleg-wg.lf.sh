#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SLAVE=root@47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SPASS_ESC=${SPASS//%/%%}
SIDE=root@185.220.238.143
SIDEPASS='DefaultPasswd123.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
PROXY="sshpass -p '$SPASS_ESC' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=10 -W %h:%p $SLAVE"

echo '=== wait for two agent cycles ==='
sleep 45

echo '=== client forward47 state ==='
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
ss -ltnp | egrep ':(11035|43003|53003)\b' || true
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ip route get 10.204.47.2 2>/dev/null || true
python3 - <<'PY'
import json, pathlib
paths=['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/bond-endpoint-health.json']
for p in paths:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  if p.endswith('mptcp-forward-47.json'):
   print(json.dumps({k:data.get(k) for k in ['listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','underlays','mptcp_endpoints']}, indent=2, ensure_ascii=False)[:12000])
  else:
   print(json.dumps(data.get('47', data), indent=2, ensure_ascii=False)[:12000])
 except Exception as e: print(type(e).__name__, e)
PY
echo '=== recent client logs ==='
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 240 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|mptcp|restart|failed|error|selected|private|direct|probe|520|magic' | tail -180 || true
REMOTE

echo '=== sidecar selfleg state ==='
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" "$SIDE" 'bash -s' <<'REMOTE'
set +e
systemctl is-active leikwan-bond-sidecar-5-agent.service leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service 2>/dev/null || true
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ls -l /etc/wireguard/lw-self-47.conf /var/lib/leikwan-sidecar-5/wireguard/self-leg-clients/lw-self-47.conf 2>/dev/null || true
sed -n '1,100p' /etc/wireguard/lw-self-47.conf 2>/dev/null | sed -E 's/(PrivateKey = ).*/\1REDACTED/' || true
ss -ltnup | egrep ':(14969|24969|8899|8898|2053|5201|5202)\b' || true
journalctl -u leikwan-bond-sidecar-5-agent.service -u wg-quick@lw-self-47.service -n 180 --no-pager -o short-iso | egrep -i 'self|wg|wireguard|peer|lw-self|failed|error|restart' | tail -140 || true
REMOTE