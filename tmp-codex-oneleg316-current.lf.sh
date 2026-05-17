set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== slave316 current wstunnel target/edge ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e; systemctl is-active wstunnel-svc-2-316.service; tr "\0" " " </proc/$(systemctl show -p MainPID --value wstunnel-svc-2-316.service)/cmdline; echo; sed -n "1,120p" /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json 2>/dev/null || true'
echo '=== client one-leg endpoint316 bond to sidecar local 5201 ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
BASE=/tmp/codex-oneleg-316-now
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
pkill -f 'leikwan-ingress client --listen 127.0.0.1:29160' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127.0.0.1:29161' 2>/dev/null || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29160 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .5
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29161 --legs 'tcp://127.0.0.1:29160?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
sleep 2
echo '-- tcp connect to oneleg listener --'
python3 - <<'PY'
import socket,time
for i in range(3):
 t=time.time(); err=''; ok=False
 try:
  s=socket.create_connection(('127.0.0.1',29161),timeout=3); ok=True; s.close()
 except Exception as e: err=repr(e)
 print({'try':i+1,'ok':ok,'ms':round((time.time()-t)*1000,1),'err':err})
 time.sleep(.2)
PY
echo '-- iperf oneleg --'
timeout 16 iperf3 -c 127.0.0.1 -p 29161 -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/iperf.out" 2>&1 || true
cat "$BASE/iperf.out"
echo '-- bond key tail --'; grep -E 'session established|failed|invalid bond frame magic|recv_reset|local_write_failed|WARN|ERROR|target|stream' "$BASE/bond.log" | tail -120 || tail -80 "$BASE/bond.log"
echo '-- ingress tail --'; tail -80 "$BASE/ingress.log"
REMOTE
echo '=== slave316 logs after test ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since "-2 min" --no-pager -o short-iso | grep -E "Opening TCP|Invalid status|Closing|ERROR|WARN|accepted|failed|route|backend" | tail -160 || true'