set -euo pipefail
CPASS='Frank20021212.'
CLIENT='root@114.80.33.50'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-client-oneleg-proof
rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SLAVE='47.116.104.15'
INGRESS_PORT=22740
LISTEN=18081
cleanup(){ kill $(jobs -p) >/dev/null 2>&1 || true; }
trap cleanup EXIT
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service leikwan-client-47.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127.0.0.1:22740' >/dev/null 2>&1 || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127.0.0.1:18081' >/dev/null 2>&1 || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT} --remote ${SLAVE}:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 &
for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', $INGRESS_PORT), timeout=.2); s.close()
PY
done
make_token(){ python3 - "$1" <<'PY'
import base64,hashlib,hmac,json,time,sys
port=int(sys.argv[1]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+300}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_bond_probe(){
  local target_port="$1" label="$2"
  local token; token=$(make_token "$target_port")
  rm -f "$BASE/bond-$label.log"
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
    --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=${SLAVE}" \
    --session-token "$token" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 \
    --connect-timeout-ms 3000 --join-timeout-ms 800 --leg-cooldown-ms 5000 >"$BASE/bond-$label.log" 2>&1 &
  local bpid=$!
  for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', $LISTEN), timeout=.2); s.close()
PY
  done
  echo "-- probe $label target=$target_port --"
  python3 - "$target_port" <<'PY'
import socket,time,sys
start=time.time()
try:
    s=socket.create_connection(('127.0.0.1',18081),timeout=3)
    s.settimeout(5)
    try:
        data=s.recv(128)
    except Exception as e:
        data=b''; print('recv_error',type(e).__name__,str(e))
    print('connect_recv_ms',round((time.time()-start)*1000,1),'bytes',len(data),'data',data[:90])
    s.close()
except Exception as e:
    print('connect_error',type(e).__name__,str(e),'ms',round((time.time()-start)*1000,1))
PY
  sleep 1
  kill "$bpid" >/dev/null 2>&1 || true; wait "$bpid" >/dev/null 2>&1 || true
  echo "-- bond key $label --"; grep -E 'session established|invalid bond frame magic|failed to read bond frame|hello-ack|recv_reset|local_read_failed|local_write_failed|cooldown|failed|WARN|ERROR' "$BASE/bond-$label.log" | tail -80 || cat "$BASE/bond-$label.log"
}
echo "date=$(date -Is) client=$(hostname) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
run_bond_probe 22 ssh22
# Now iperf against sidecar 5201 if it is there, with requested -b 300M
TOKEN=$(make_token 5201)
RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=${SLAVE}" \
  --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 \
  --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
  --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000 >"$BASE/bond-5201.log" 2>&1 &
BPID=$!
for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', $LISTEN), timeout=.2); s.close()
PY
done
echo "-- iperf sidecar 5201 via one leg (-b 300M) --"
timeout 15 iperf3 -c 127.0.0.1 -p ${LISTEN} -R -b 300M -t 5 >"$BASE/iperf-5201.txt" 2>&1 || true
cat "$BASE/iperf-5201.txt"
sleep 1
kill "$BPID" >/dev/null 2>&1 || true; wait "$BPID" >/dev/null 2>&1 || true
echo "-- bond key 5201 --"; grep -E 'session established|invalid bond frame magic|failed to read bond frame|hello-ack|recv_reset|local_read_failed|local_write_failed|cooldown|failed|WARN|ERROR|stream_open|open_ack' "$BASE/bond-5201.log" | tail -120 || cat "$BASE/bond-5201.log"
echo "-- ingress key --"; grep -E 'failed|Broken pipe|WARN|ERROR|accepted|connection' "$BASE/ingress.log" | tail -80 || true
REMOTE
