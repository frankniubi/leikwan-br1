#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== direct public sidecar iperf health from client ==='
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
for p in 5201 5202; do echo "-- public sidecar $p --"; timeout 14 iperf3 -c 185.220.238.143 -p $p -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,80p'; done
REMOTE

echo '=== onegood f47 current short retest ==='
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
for u in leikwan-client-40.service leikwan-client-48.service leikwan-client-59.service leikwan-client-47.service; do systemctl stop "$u" >/dev/null 2>&1 || true; systemctl disable "$u" >/dev/null 2>&1 || true; done
systemctl restart leikwan-client-47-onegood.service
sleep 2
echo "date=$(date -Is) onegood=$(systemctl is-active leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740|32501)\b' || true
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(30):
 t=time.time()
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(4); data=s.recv(80); s.close();
  if data.startswith(b'SSH-'): vals.append((time.time()-t)*1000)
  else: errs.append(('bad',data[:24].decode('latin1','replace')))
 except Exception as e: errs.append((type(e).__name__,str(e)[:80]))
 time.sleep(.08)
print('BANNER30 ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'p50',round(statistics.median(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:5])
PY
timeout 22 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-onegood-now-r.out 2>&1; echo iperfR_rc=$?; sed -n '1,100p' /tmp/codex-onegood-now-r.out
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for p in ['invalid bond frame magic','failed to read bond frame header','no bond legs acknowledged','bond client TCP transport session established','entering cooldown','local_write_failed','local_read_failed','recv_reset','failed to connect initial']:
 print(p,s.count(p))
PY
REMOTE

echo '=== slave317 recent wst state ==='
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) wst=$(systemctl is-active wstunnel-svc-2-317.service) mux=$(systemctl is-active leikwan-tcp-mux-7000.service) agent=$(systemctl is-active leikwan-slave-agent.service)/$(systemctl is-enabled leikwan-slave-agent.service)"
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service); echo pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
journalctl -u wstunnel-svc-2-317.service --since '-3 min' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for p in ['Invalid status code: 520','failed to do websocket handshake','Opening TCP connection','Closing local','ERROR','WARN']:
 print(p,s.count(p))
PY
REMOTE