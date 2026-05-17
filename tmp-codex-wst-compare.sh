#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/selfleg_wst_compare.sh <<'RS'
#!/usr/bin/env bash
set -euo pipefail
WST=/usr/local/bin/wstunnel
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IP=162.159.39.6
for TARGET in 8898 8899; do
  LP=$((50000 + TARGET))
  LOG=/tmp/compare-${TARGET}.log
  rm -f "$LOG"
  echo "=== TARGET $TARGET LP $LP ==="
  "$WST" client -L tcp://127.0.0.1:${LP}:127.0.0.1:${TARGET} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${IP}:2053 >"$LOG" 2>&1 &
  PID=$!
  sleep 1
  python3 - "$LP" <<'PY'
import socket, sys, time
lp = int(sys.argv[1])
s = socket.create_connection(("127.0.0.1", lp), timeout=2)
s.settimeout(1)
s.sendall(b'A' * 303)
time.sleep(1)
try:
    print('recv', s.recv(1))
except Exception as e:
    print('recv_exc', repr(e))
s.close()
PY
  sleep 3
  if kill -0 "$PID" 2>/dev/null; then echo "state alive"; else echo "state exited"; fi
  sed -n '1,120p' "$LOG" || true
  kill "$PID" 2>/dev/null || true
  wait "$PID" 2>/dev/null || true
  echo
 done
RS
chmod +x /tmp/selfleg_wst_compare.sh
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/selfleg_wst_compare.sh root@114.80.33.50:/tmp/selfleg_wst_compare.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/selfleg_wst_compare.sh'