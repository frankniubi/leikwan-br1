#!/usr/bin/env bash
set -euo pipefail
EDGE=${1:-162.159.39.29}
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo "=== force endpoint316 edge=$EDGE ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set -euo pipefail
svc=/etc/systemd/system/wstunnel-svc-2-316.service
meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-316.service')
text=svc.read_text(); text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text); svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json')
if meta.exists():
    d=json.loads(meta.read_text()); d['selected_cf_ip']=edge; d.setdefault('cf_mode',{})['manual_minimum_good_path_edge']=edge; meta.write_text(json.dumps(d, ensure_ascii=False, indent=2)+'\n')
PY
systemctl daemon-reload
systemctl restart wstunnel-svc-2-316.service
sleep 1
systemctl is-active wstunnel-svc-2-316.service
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-316.service); echo pid=$pid; tr '\0' ' ' </proc/$pid/cmdline; echo
python3 - <<'PY'
import socket,time,os,statistics
edge=os.environ['EDGE']; vals=[]
for i in range(5):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); s.close(); vals.append((time.time()-t)*1000)
 except Exception as e: print('edge_tcp_err',repr(e))
 time.sleep(.08)
print('edge_tcp_ms',[round(v,1) for v in vals], 'min', round(min(vals),1) if vals else None, 'avg', round(statistics.mean(vals),1) if vals else None)
PY
REMOTE

echo "=== client test endpoint316 edge=$EDGE ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "EDGE=$EDGE bash -s" <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
ROUTE='07289d1f5ed25399'; AUTH='2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e'; SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; HOST='47.116.11.144'; EP=316
make_token(){ PORT=$1 SECRET=$SECRET python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
raw=json.dumps({"target_host":"127.0.0.1","target_port":int(os.environ['PORT']),"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600},separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(os.environ['SECRET'].encode(),raw,hashlib.sha256).digest()))
PY
}
run_bond(){
  target=$1; lp=$((16000 + target + RANDOM%1000)); ip=$((26000 + target + RANDOM%1000)); name="${EDGE//./_}-$target"
  echo "--- target=$target lp=$lp ip=$ip ---"
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$ip --remote ${HOST}:7000 --route-key "$ROUTE" --auth-token "$AUTH" >/tmp/edge-$name-ingress.log 2>&1 & ipid=$!
  for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$ip),timeout=.2); s.close()
PY
  done
  TOKEN=$(make_token $target)
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$lp --legs "tcp://127.0.0.1:$ip?priority=10&endpoint_id=${EP}&display_host=${HOST}" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 600 --connect-timeout-ms 3500 --join-timeout-ms 800 --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 30 --bulk-promote-bytes 262144 --leg-cooldown-ms 1000 >/tmp/edge-$name-bond.log 2>&1 & bpid=$!
  for i in $(seq 1 80); do ss -ltn | grep -q ":$lp " && break || sleep .1; done
  sleep 1
  if [ "$target" = 22 ]; then
    python3 - <<PY
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$lp),timeout=4); s.settimeout(4); data=s.recv(80); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:20].hex()))
 time.sleep(.15)
print('BANNER edge=$EDGE ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:3])
PY
  else
    timeout 20 iperf3 -c 127.0.0.1 -p $lp -R -b 300M -t 8 --connect-timeout 3000 >/tmp/edge-$name-iperf.log 2>&1; rc=$?
    echo iperf_rc=$rc
    python3 - <<PY
import re, pathlib
text=pathlib.Path('/tmp/edge-$name-iperf.log').read_text(errors='ignore')
vals=[]
for line in text.splitlines():
 m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
 if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
print('IPERF edge=$EDGE vals', vals, 'max', max(vals) if vals else None, 'avg', round(sum(vals)/len(vals),2) if vals else None)
print(text[:1300])
PY
  fi
  echo '--- key ---'
  grep -E 'session established|failed to read bond frame header|invalid bond frame magic|Invalid status code|failed to do websocket|local_read_failed|local_write_failed|entering cooldown|no bond legs|WARN|ERROR' /tmp/edge-$name-bond.log /tmp/edge-$name-ingress.log | tail -80 || true
  kill $bpid $ipid 2>/dev/null || true
  sleep 1
}
run_bond 22
run_bond 5201
REMOTE
