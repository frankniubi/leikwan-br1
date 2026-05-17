#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
sleep 4
echo "date=$(date -Is) onegood=$(systemctl is-active leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|2274[0-4])\b' || true
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
 t=time.time()
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(4); data=s.recv(80); s.close()
  if data.startswith(b'SSH-'): vals.append((time.time()-t)*1000)
  else: errs.append(('bad',data[:24].decode('latin1','replace')))
 except Exception as e: errs.append((type(e).__name__,str(e)[:80]))
 time.sleep(.1)
print('BANNER20 ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'p50',round(statistics.median(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:4])
PY
timeout 22 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-d6-installed-iperf.out 2>&1; rc=$?; echo iperf_rc=$rc; sed -n '1,100p' /tmp/codex-d6-installed-iperf.out
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for p in ['invalid bond frame magic','failed to read bond frame header','no bond legs acknowledged','bond client TCP transport session established','entering cooldown','local_write_failed','local_read_failed','recv_reset','failed to connect initial']:
 print(p,s.count(p))
PY
REMOTE