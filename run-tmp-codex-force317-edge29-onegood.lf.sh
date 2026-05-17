#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
EDGE='162.159.39.29'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)

echo "=== slave317 force edge $EDGE $(date -Is) ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'SLAVE_REMOTE'
set -euo pipefail
EDGE=${EDGE:?}
systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true
python3 - <<'PY'
import json, pathlib, os
edge=os.environ['EDGE']
p=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
d=json.loads(p.read_text())
d['selected_cf_ip']=edge
cf=d.get('cf_mode') if isinstance(d.get('cf_mode'),dict) else {}
cf['manual_minimum_good_path_edge']=edge
# Keep the probe target explicit so future reconciliation validates full WSS path, not bare TCP.
cf['probe_target']=d.get('target','127.0.0.1:8899')
d['cf_mode']=cf
p.write_text(json.dumps(d, ensure_ascii=False, indent=2), encoding='utf-8')
print('metadata_selected_cf_ip', edge)
PY
cat >/etc/systemd/system/wstunnel-svc-2-317.service <<UNIT
[Unit]
Description=Leikwan CF WSS Backend wstunnel-svc-2-317 forced one-good edge
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/wstunnel client -L tcp://127.0.0.1:7002:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers "Host: nrt1.nbwly.com" --http-upgrade-path-prefix 07289d1f5ed25399 wss://$EDGE:2053
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now leikwan-tcp-mux-7000.service >/dev/null 2>&1 || true
systemctl restart wstunnel-svc-2-317.service
sleep 1
systemctl is-active wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service); echo pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
ss -ltnp | grep -E ':(7000|7002)\b' || true
python3 - <<'PY'
import socket,time,statistics
edge='162.159.39.29'; vals=[]; errs=[]
for i in range(12):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); s.close(); vals.append((time.time()-t)*1000)
 except Exception as e: errs.append(repr(e))
 time.sleep(.05)
print('edge_tcp_ms', 'min', round(min(vals),1) if vals else None, 'avg', round(statistics.mean(vals),1) if vals else None, 'vals', [round(v,1) for v in vals], 'errs', errs[:2])
PY
SLAVE_REMOTE

echo "=== client install single slave317 onegood $(date -Is) ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET SLAVE=$SLAVE EDGE=$EDGE bash -s" <<'CLIENT_REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:22740' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<SH
#!/usr/bin/env bash
set -euo pipefail
trap 'kill \$(jobs -p) 2>/dev/null || true' EXIT
ROUTE='$ROUTE'
AUTH='$AUTH'
SECRET='$SECRET'
SLAVE='$SLAVE'
LISTEN=11035
INGRESS_PORT=22740
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:[0m\${INGRESS_PORT} --remote \${SLAVE}:7000 --route-key "\$ROUTE" --auth-token "\$AUTH" &
for i in \$(seq 1 80); do
  python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', \${INGRESS_PORT}), timeout=.25); s.close()
PY
done
TOKEN=\$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode()
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+86400}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:\${LISTEN} \
  --legs "tcp://127.0.0.1:\${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "\$TOKEN" \
  --chunk-size 65536 --max-window-bytes 16777216 --retransmit-ms 500 \
  --connect-timeout-ms 3500 --join-timeout-ms 900 \
  --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
  --warmup-trigger-bytes 1048576 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 \
  --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000
SH
python3 - <<'PY'
from pathlib import Path
p=Path('/usr/local/bin/leikwan-client-start-47-onegood.sh')
s=p.read_text()
s=s.replace('\x1b[0m','')
p.write_text(s)
PY
chmod +x /usr/local/bin/leikwan-client-start-47-onegood.sh
cat >/etc/systemd/system/leikwan-client-47-onegood.service <<'UNIT'
[Unit]
Description=Leikwan Client 47 one-good Shanghai-Tokyo CF bond via slave317
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
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service)/$(systemctl is-enabled leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
journalctl -u leikwan-client-47-onegood.service -n 40 --no-pager -o short-iso | tail -40
CLIENT_REMOTE