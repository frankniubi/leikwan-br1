#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-bond-target-probe
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
mk_token(){ python3 - "$1" "$2" <<'PY'
import base64,hashlib,hmac,json,sys,time
host=sys.argv[1]; port=int(sys.argv[2]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":host,"target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:29230:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix 07289d1f5ed25399 wss://162.159.39.4:2053 >"$BASE/wstunnel.log" 2>&1 & echo $! >"$BASE/wstunnel.pid"
sleep 1
for target in '127.0.0.1 5201' '185.220.238.143 5201' '127.0.0.1 22'; do
  set -- $target; host=$1; port=$2; lport=$((29240 + RANDOM % 1000)); token=$(mk_token "$host" "$port")
  echo "=== token target $host:$port lport=$lport ==="
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$lport --legs 'tcp://127.0.0.1:29230?priority=10&display_host=self_leg&role=primary' --session-token "$token" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 600 >"$BASE/bond-$host-$port.log" 2>&1 & bpid=$!; echo $bpid >"$BASE/bond-$host-$port.pid"
  sleep 2
  if [ "$port" = 5201 ]; then
    timeout 8 iperf3 -c 127.0.0.1 -p $lport -b 300M -t 3 --connect-timeout 3000 >"$BASE/out-$host-$port.txt" 2>&1 || true
  else
    python3 - "$lport" >"$BASE/out-$host-$port.txt" 2>&1 <<'PY' || true
import socket,sys,time
p=int(sys.argv[1])
s=socket.create_connection(('127.0.0.1',p),timeout=5); s.settimeout(5); print(repr(s.recv(80))); s.close()
PY
  fi
  cat "$BASE/out-$host-$port.txt"
  echo '---bond tail---'; tail -60 "$BASE/bond-$host-$port.log" | grep -E 'target|recv_reset|session established|failed|Connection refused|WARN|ERROR' || tail -30 "$BASE/bond-$host-$port.log"
  kill $bpid 2>/dev/null || true
  sleep .5
done
echo '---wstunnel tail---'; tail -100 "$BASE/wstunnel.log"
REMOTE