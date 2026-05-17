#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
set -euo pipefail
WST=/usr/local/bin/wstunnel
HOST=162.159.39.6
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
for target in 8898 8899; do
  lp=$((39000 + target))
  log=/tmp/wst-test-$target.log
  rm -f "$log"
  echo "=== target $target local $lp ==="
  "$WST" client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${HOST}:2053 > /dev/null 2>"$log" &
  pid=$!
  sleep 1
  python3 - <<PY || true
import socket, time
try:
    s=socket.create_connection(("127.0.0.1", int("$lp")), timeout=2)
    s.sendall(b"X")
    time.sleep(0.5)
    try:
        data=s.recv(1)
        print('recv', data)
    except Exception as e:
        print('recv_err', repr(e))
    s.close()
    print('local_connect_ok')
except Exception as e:
    print('local_connect_fail', repr(e))
PY
  sleep 1
  if kill -0 $pid 2>/dev/null; then echo still_running; else echo exited; fi
  sed -n "1,80p" "$log" || true
  kill $pid 2>/dev/null || true
  wait $pid 2>/dev/null || true
  echo
 done
'