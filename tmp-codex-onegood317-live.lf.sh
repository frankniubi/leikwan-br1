#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
EDGE='162.159.39.7'
ROUTE='07289d1f5ed25399'
AUTH317='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'

echo "=== force endpoint317 slave CF edge to $EDGE ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set -euo pipefail
svc=/etc/systemd/system/wstunnel-svc-2-317.service
meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json
stamp=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p /root/codex-backups/f47-onegood-317-$stamp
cp -a "$svc" /root/codex-backups/f47-onegood-317-$stamp/ 2>/dev/null || true
cp -a "$meta" /root/codex-backups/f47-onegood-317-$stamp/ 2>/dev/null || true
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-317.service')
text=svc.read_text()
text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text)
svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if meta.exists():
    data=json.loads(meta.read_text())
    data['selected_cf_ip']=edge
    cf=data.setdefault('cf_mode', {})
    if isinstance(cf, dict):
        cf['manual_minimum_good_path_edge']=edge
    meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
systemctl daemon-reload
systemctl restart wstunnel-svc-2-317.service
sleep 1
systemctl is-active wstunnel-svc-2-317.service
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service)
echo pid=$pid
tr '\0' ' ' </proc/$pid/cmdline; echo
python3 - <<'PY'
import socket,time,statistics,os
edge=os.environ['EDGE']; vals=[]
for _ in range(5):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); s.close(); vals.append((time.time()-t)*1000)
 except Exception as e: print('edge_err',repr(e))
 time.sleep(.08)
print('edge_tcp_ms', [round(v,1) for v in vals], 'min', round(min(vals),1) if vals else None)
PY
REMOTE

echo "=== install/start one-good client forward47 service via endpoint317 ==="
for attempt in 1 2 3 4 5; do
  set +e
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH317=$AUTH317 SECRET=$SECRET bash -s" <<'REMOTE'
set -euo pipefail
echo "client $(hostname) $(date -Is)"
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service >/dev/null 2>&1 || true
systemctl disable leikwan-client-47.service >/dev/null 2>&1 || true
# clean old experiments that can consume sockets / confuse logs
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:(22740|291|1103)' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen (127\.0\.0\.1:)?(11035|11036|11037|291)' 2>/dev/null || true
pkill -f 'iperf3 -c 127\.0\.0\.1 -p (11035|291)' 2>/dev/null || true
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
INGRESS_PORT=22740
LISTEN=11035
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT} --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" &
for i in $(seq 1 50); do
  python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', ${INGRESS_PORT}), timeout=.2); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+86400}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "$TOKEN" \
  --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 \
  --connect-timeout-ms 3000 --join-timeout-ms 800 \
  --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
  --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 \
  --bulk-promote-bytes 1048576 --leg-cooldown-ms 3000
EOS
chmod 755 /usr/local/bin/leikwan-client-start-47-onegood.sh
cat >/etc/systemd/system/leikwan-client-47-onegood.service <<'EOS'
[Unit]
Description=Leikwan Client 47 one-good Shanghai-Tokyo CF bond path
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
EOS
systemctl daemon-reload
systemctl enable --now leikwan-client-47-onegood.service
sleep 2
systemctl is-active leikwan-client-47-onegood.service
ss -ltnp | grep -E ':(11035|22740)' || true
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | tail -80
REMOTE
  rc=$?
  set -e
  if [ $rc -eq 0 ]; then break; fi
  echo "client ssh attempt $attempt failed rc=$rc; sleeping"
  sleep $((attempt*8))
  if [ $attempt -eq 5 ]; then exit $rc; fi
done

echo "=== iperf through one-good forward47 (-b 300M) ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
sleep 2
echo "date=$(date -Is)"
timeout 18 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-onegood47-iperf.out 2>&1
rc=$?
echo iperf_rc=$rc
cat /tmp/codex-onegood47-iperf.out
echo '--- onegood journal ---'
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|no bond legs|session established|TCP transport|failed|WARN|ERROR|accepted|connected|Closing|520|Invalid status' | tail -180 || true
REMOTE

echo "=== slave317 post-test logs ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-5 min" --no-pager -o short-iso | grep -E "Invalid status code|failed to do websocket|Opening TCP|TLS handshake|Closing|plain tcp mux open|ERROR|WARN|8899|07289" | tail -160 || true'
