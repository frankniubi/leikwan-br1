set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:22740' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<'SH'
#!/usr/bin/env bash
set -euo pipefail
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
export SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SLAVE='47.116.104.15'
LISTEN=11035
INGRESS_PORT=22740
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT} --remote ${SLAVE}:7000 --route-key "$ROUTE" --auth-token "$AUTH" &
for i in $(seq 1 80); do
  python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', ${INGRESS_PORT}), timeout=.25); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode()
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+86400}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "$TOKEN" \
  --chunk-size 65536 --max-window-bytes 16777216 --retransmit-ms 500 \
  --connect-timeout-ms 3500 --join-timeout-ms 900 \
  --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
  --warmup-trigger-bytes 1048576 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 \
  --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000
SH
chmod +x /usr/local/bin/leikwan-client-start-47-onegood.sh
cat >/etc/systemd/system/leikwan-client-47-onegood.service <<'UNIT'
[Unit]
Description=Leikwan Client 47 one-good Shanghai-Tokyo CF bond via slave317 target22 proof
After=network.target

[Service]
Type=simple
Environment=RUST_LOG=info
ExecStart=/bin/bash /usr/local/bin/leikwan-client-start-47-onegood.sh
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now leikwan-client-47-onegood.service
sleep 2
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:32].decode('latin1','replace')))
 time.sleep(.12)
print('TARGET22_RESULT ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:5])
PY
journalctl -u leikwan-client-47-onegood.service --since '-1 min' --no-pager -o short-iso | grep -E 'session established|failed|invalid bond frame|hello|cooldown|WARN|ERROR' | tail -80 || true
REMOTE