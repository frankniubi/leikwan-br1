set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
EDGES=(162.159.39.4 162.159.39.6 162.159.39.7 162.159.39.8 162.159.39.29 104.16.0.3)
for EDGE in "${EDGES[@]}"; do
  echo "=== EDGE $EDGE: configure slave317 ==="
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set -euo pipefail
svc=/etc/systemd/system/wstunnel-svc-2-317.service
meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json
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
 if isinstance(cf, dict): cf['manual_minimum_good_path_edge']=edge
 meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
systemctl daemon-reload
systemctl restart wstunnel-svc-2-317.service
sleep 1
systemctl is-active wstunnel-svc-2-317.service
REMOTE
  echo "=== EDGE $EDGE: client target22 bond probe ==="
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET EDGE=$EDGE bash -s" <<'REMOTE'
set +e
BASE=/tmp/codex-f47-target22-$EDGE
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:22840 --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
for i in $(seq 1 40); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',22840),timeout=.25); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode()
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:11036 --legs 'tcp://127.0.0.1:22840?priority=10&endpoint_id=317&display_host=47.116.104.15' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --connect-timeout-ms 3000 --join-timeout-ms 800 --leg-cooldown-ms 1000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 50); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',11036),timeout=.25); s.close()
PY
done
python3 - <<'PY'
import socket,time,statistics
vals=[]; fails=[]
for i in range(12):
 t=time.time(); ok=False; data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',11036),timeout=2.5); s.settimeout(2.5); data=s.recv(80); ok=data.startswith(b'SSH-'); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if ok: vals.append(ms)
 else: fails.append({'ms':round(ms,1),'err':err,'data':data[:32].decode('latin1','replace')})
 time.sleep(.12)
print({'ok':len(vals),'fail':len(fails),'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':[round(v,1) for v in vals], 'fails':fails[:3]})
PY
echo '-- bond markers --'
grep -E 'invalid bond frame magic|failed to read bond frame header|hello-ack|session established|recv_reset|local_read_failed|local_write_failed|WARN|ERROR' "$BASE/bond.log" | tail -40 || true
echo '-- ingress markers --'
grep -E 'invalid|error|WARN|ERROR|accepted|Opening' "$BASE/ingress.log" | tail -20 || true
REMOTE
  echo "=== EDGE $EDGE: slave markers ==="
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-90 sec" --no-pager -o short-iso | grep -E "Invalid status code|failed to do websocket|Opening TCP|TLS handshake|Closing|plain tcp mux|ERROR|WARN|8899|07289" | tail -80 || true'
done