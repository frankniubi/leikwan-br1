#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
CPASS='Frank20021212.'
CLIENT=114.80.33.50
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)

echo '=== start temporary iperf3 on client 5201 ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set -euo pipefail
  pkill -f "iperf3 -s -p 5201" 2>/dev/null || true
  nohup iperf3 -s -p 5201 >/tmp/codex-iperf3-5201-server.log 2>&1 &
  sleep .5
  ss -ltnp | grep :5201 || (cat /tmp/codex-iperf3-5201-server.log; exit 1)
'

echo '=== patch endpoint316 to direct target client:5201 through best edge 162.159.39.4 ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set -euo pipefail
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  svc=/etc/systemd/system/wstunnel-svc-2-316.service
  meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json
  cp -a "$svc" "/root/codex-bak-wstunnel-svc-2-316-$stamp.service" 2>/dev/null || true
  cp -a "$meta" "/root/codex-bak-wstunnel-svc-2-316-$stamp.json" 2>/dev/null || true
  python3 - <<"PY"
import json,pathlib,re
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-316.service')
text=svc.read_text()
text=re.sub(r'tcp://127\.0\.0\.1:7013:[^\s]+', 'tcp://127.0.0.1:7013:114.80.33.50:5201', text)
text=re.sub(r'wss://[^\s]+:2053', 'wss://162.159.39.4:2053', text)
svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json')
data=json.loads(meta.read_text())
data['target']='114.80.33.50:5201'
data['selected_cf_ip']='162.159.39.4'
data.setdefault('cf_mode',{})['manual_minimum_good_path_edge']='162.159.39.4'
meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
  systemctl daemon-reload
  systemctl restart wstunnel-svc-2-316.service
  sleep 1
  systemctl is-active wstunnel-svc-2-316.service
  tr "\0" " " </proc/$(systemctl show -p MainPID --value wstunnel-svc-2-316.service)/cmdline; echo
'

echo '=== client one-leg bond via endpoint316 to local iperf3 target ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set -euo pipefail
  ROUTE=07289d1f5ed25399
  AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
  SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
  pkill -f "leikwan-ingress client --listen 127.0.0.1:29150" 2>/dev/null || true
  pkill -f "leikwan-bond client --protocol tcp --listen 127.0.0.1:29151" 2>/dev/null || true
  LOG=/tmp/codex-onegood-client5201; rm -rf "$LOG"; mkdir -p "$LOG"
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29150 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$LOG/ingress.log" 2>&1 & echo $! >"$LOG/ingress.pid"
  sleep .5
  TOKEN=$(python3 - <<"PY"
import base64,hashlib,hmac,json,os,time
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29151 --legs '"'"'tcp://127.0.0.1:29150?priority=10&endpoint_id=316&display_host=47.116.11.144'"'"' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 >"$LOG/bond.log" 2>&1 & echo $! >"$LOG/bond.pid"
  sleep 2
  timeout 12 iperf3 -c 127.0.0.1 -p 29151 -b 300M -t 6 --connect-timeout 3000 >"$LOG/iperf.out" 2>&1 || true
  cat "$LOG/iperf.out"
  echo ---bondlog---; sed -n "1,160p" "$LOG/bond.log"
  echo ---ingresslog---; sed -n "1,80p" "$LOG/ingress.log"
  kill $(cat "$LOG/bond.pid") $(cat "$LOG/ingress.pid") 2>/dev/null || true
'
