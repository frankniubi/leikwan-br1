set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
EDGE=162.159.39.4
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0

echo "=== force one Shanghai slave endpoint316 to best edge $EDGE ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set -euo pipefail
svc=/etc/systemd/system/wstunnel-svc-2-316.service
meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json
stamp=$(date -u +%Y%m%dT%H%M%SZ)
cp -a "$svc" "/root/codex-bak-wstunnel-svc-2-316-$stamp.service" 2>/dev/null || true
cp -a "$meta" "/root/codex-bak-wstunnel-svc-2-316-$stamp.json" 2>/dev/null || true
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json')
if meta.exists():
    data=json.loads(meta.read_text())
    data['selected_cf_ip']=edge
    data.setdefault('cf_mode', {})['manual_minimum_good_path_edge']=edge
    meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-316.service')
text=svc.read_text()
text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text)
svc.write_text(text)
PY
systemctl daemon-reload
systemctl restart wstunnel-svc-2-316.service
sleep 1
systemctl is-active wstunnel-svc-2-316.service
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-316.service)
echo pid=$pid
tr '\0' ' ' </proc/$pid/cmdline; echo
python3 - <<'PY'
import socket,time,statistics,os
edge=os.environ['EDGE']
vals=[]
for _ in range(5):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); s.close(); vals.append((time.time()-t)*1000)
 except Exception as e: print('tcp_edge_err',repr(e))
 time.sleep(.1)
print('edge_tcp_ms', [round(v,1) for v in vals], 'min', round(min(vals),1) if vals else None)
PY
REMOTE

echo "=== one-leg bond data-plane probe via endpoint316 to sidecar localhost ssh(22) ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET bash -s" <<'REMOTE'
set -euo pipefail
pkill -f 'leikwan-ingress client --listen 127.0.0.1:29140' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127.0.0.1:29141' 2>/dev/null || true
LOGDIR=/tmp/codex-oneleg-316
rm -rf "$LOGDIR"; mkdir -p "$LOGDIR"
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29140 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$LOGDIR/ingress.log" 2>&1 & echo $! >"$LOGDIR/ingress.pid"
sleep .5
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
secret=os.environ['SECRET'].encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29141 --legs 'tcp://127.0.0.1:29140?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 >"$LOGDIR/bond.log" 2>&1 & echo $! >"$LOGDIR/bond.pid"
sleep 2
python3 - <<'PY'
import socket,time
started=time.time()
try:
    s=socket.create_connection(('127.0.0.1',29141),timeout=3)
    s.settimeout(4)
    try:
        data=s.recv(128)
    except socket.timeout:
        data=b''
    print('probe_connected_ms', round((time.time()-started)*1000,1))
    print('banner_or_bytes', data[:120].decode('latin1','replace'))
    s.close()
except Exception as e:
    print('probe_error',repr(e))
PY
sleep 1
echo '--- bond log ---'; sed -n '1,160p' "$LOGDIR/bond.log"
echo '--- ingress log ---'; sed -n '1,80p' "$LOGDIR/ingress.log"
kill $(cat "$LOGDIR/bond.pid") $(cat "$LOGDIR/ingress.pid") 2>/dev/null || true
REMOTE

echo "=== slave endpoint316 recent log after one-leg probe ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-316.service --since "-3 min" --no-pager -o short-iso | grep -E "Opening TCP|Invalid status|handshake|Closing|ERROR|WARN|INFO" | tail -120 || true'