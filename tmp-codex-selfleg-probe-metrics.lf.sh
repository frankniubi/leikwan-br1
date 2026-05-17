#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set -euo pipefail
IPS=(104.16.0.3 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 162.159.39.8)
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
printf '=== curl connect/appconnect to CF edge 2053 ===\n'
for ip in "${IPS[@]}"; do
  printf '%s ' "$ip"
  curl -k -sS -o /dev/null --connect-timeout 2 --max-time 4 --resolve ${SNI}:2053:${ip} -H "Host: ${SNI}" -w 'code=%{http_code} connect=%{time_connect} app=%{time_appconnect} total=%{time_total}\n' "https://${SNI}:2053/_leikwan/healthz" || true
done
printf '\n=== wstunnel listener open latency, no payload, per IP ===\n'
idx=0
for ip in "${IPS[@]}"; do
  lp=$((43000+idx))
  log=/tmp/wst-open-${idx}.log
  rm -f "$log"
  start=$(python3 - <<'PY'
import time; print(time.time())
PY
)
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${ip}:2053 >$log 2>&1 &
  pid=$!
  ok=0
  for n in $(seq 1 40); do
    if ss -ltn "sport = :$lp" | grep -q ":$lp"; then ok=1; break; fi
    if ! kill -0 $pid 2>/dev/null; then break; fi
    sleep 0.05
  done
  end=$(python3 - <<'PY'
import time; print(time.time())
PY
)
  lat=$(python3 - "$start" "$end" <<'PY'
import sys; print(round((float(sys.argv[2])-float(sys.argv[1]))*1000,1))
PY
)
  if [ "$ok" = 1 ]; then state=listener_ok; else state=listener_fail; fi
  printf '%s %s %sms log=' "$ip" "$state" "$lat"; tr '\n' '|' < "$log" | cut -c1-180; echo
  kill $pid 2>/dev/null || true; wait $pid 2>/dev/null || true
  idx=$((idx+1))
done
printf '\n=== direct wstunnel tiny invalid payload result ===\n'
idx=0
for ip in "${IPS[@]}"; do
  lp=$((43100+idx)); log=/tmp/wst-payload-${idx}.log; rm -f "$log"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${ip}:2053 >$log 2>&1 & pid=$!
  sleep 1
  python3 - "$lp" <<'PY'
import socket, sys, time
lp=int(sys.argv[1])
try:
 s=socket.create_connection(('127.0.0.1',lp),timeout=1)
 s.settimeout(1)
 s.sendall(b'X')
 time.sleep(.2)
 try: data=s.recv(1); print('recv_len',len(data))
 except Exception as e: print('recv_exc',repr(e))
 s.close()
 print('connect_ok')
except Exception as e: print('connect_fail',repr(e))
PY
  sleep .2
  if kill -0 $pid 2>/dev/null; then alive=alive; else alive=exited; fi
  printf '%s %s log=' "$ip" "$alive"; tr '\n' '|' < "$log" | cut -c1-240; echo
  kill $pid 2>/dev/null || true; wait $pid 2>/dev/null || true
  idx=$((idx+1))
done
RS
