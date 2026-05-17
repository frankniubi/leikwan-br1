set -euo pipefail
HOST='root@47.116.104.15'
PASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$HOST" 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-bond-direct-317
rm -rf "$BASE"; mkdir -p "$BASE"
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+300}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
echo "date=$(date -Is) host=$(hostname)"
echo "-- service state --"
for u in wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service leikwan-wss-mux-7000.service leikwan-slave-agent.service; do echo "## $u"; systemctl is-active "$u" 2>/dev/null || true; done
ss -ltnp | grep -E '(:7000|:7002|:18080)' || true
printf '\n-- backend metadata --\n'
for p in /var/lib/leikwan-slave-agent/services/wstunnel-svc-2-317.json /etc/systemd/system/wstunnel-svc-2-317.service /etc/systemd/system/leikwan-tcp-mux-7000.service; do [ -e "$p" ] && { echo "--- $p"; sed -n '1,220p' "$p"; }; done
printf '\n-- direct bond to local wstunnel backend 7002 target sidecar ssh 22 --\n'
RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:18080 \
  --legs 'tcp://127.0.0.1:7002?priority=10&endpoint_id=317&display_host=local_wst_7002' \
  --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 \
  --connect-timeout-ms 3000 --join-timeout-ms 800 --leg-cooldown-ms 5000 >"$BASE/bond.log" 2>&1 &
BPID=$!
for i in $(seq 1 50); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1',18080),timeout=.2); s.close()
PY
done
python3 - <<'PY'
import socket,time
start=time.time()
try:
    s=socket.create_connection(('127.0.0.1',18080),timeout=3)
    s.settimeout(4)
    try:
        data=s.recv(128)
    except Exception as e:
        data=b''; print('recv_error',type(e).__name__,str(e))
    dt=(time.time()-start)*1000
    print('connect_recv_ms',round(dt,1),'bytes',len(data),'data',data[:80])
    s.close()
except Exception as e:
    print('connect_error',type(e).__name__,str(e),'ms',round((time.time()-start)*1000,1))
PY
sleep 1
kill "$BPID" >/dev/null 2>&1 || true
wait "$BPID" >/dev/null 2>&1 || true
printf '\n-- bond log key --\n'
grep -E 'session established|invalid bond frame magic|failed to read bond frame|hello-ack|recv_reset|local_read_failed|local_write_failed|failed|WARN|ERROR|Opening|connected' "$BASE/bond.log" | tail -120 || cat "$BASE/bond.log"
printf '\n-- wstunnel recent --\n'
journalctl -u wstunnel-svc-2-317.service --since '-3 min' --no-pager -o short-iso | grep -E 'Opening TCP connection|Invalid status code|520|handshake|Closing|Connection refused|error|WARN|INFO' | tail -120 || true
REMOTE
