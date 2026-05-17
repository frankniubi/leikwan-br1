#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
python3 - <<'PY'
import socket,ssl,time,statistics
ips=['162.159.39.4','162.159.39.6','162.159.39.7','162.159.39.8','162.159.39.29','104.16.0.3','104.16.0.4','172.64.0.3','162.159.36.3']
path='07289d1f5ed25399'; host='nrt1.nbwly.com'; port=2053
for ip in ips:
 vals=[]; statuses=[]; errs=[]
 for i in range(5):
  t=time.time(); resp=''
  try:
   raw=socket.create_connection((ip,port),timeout=1.5)
   ctx=ssl.create_default_context(); ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
   s=ctx.wrap_socket(raw,server_hostname=host); s.settimeout(2)
   req=(f'GET /{path} HTTP/1.1\r\nHost: {host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGVzdC1sZWlrd2FuLXByb2Jl\r\nSec-WebSocket-Version: 13\r\n\r\n').encode()
   s.sendall(req); resp=s.recv(256).decode('latin1','ignore').split('\r\n',1)[0]; s.close()
   if ' 101 ' in resp: vals.append((time.time()-t)*1000)
   statuses.append(resp)
  except Exception as e: errs.append(type(e).__name__+':'+str(e)[:80])
  time.sleep(.12)
 print('WSUP',ip,'ok',len(vals),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'statuses',statuses[:5],'errs',errs[:2])
PY
REMOTE