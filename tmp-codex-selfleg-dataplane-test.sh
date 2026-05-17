#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/selfleg_dataplane_test.sh <<'RS'
#!/usr/bin/env bash
set -euo pipefail
WST=/usr/local/bin/wstunnel
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IPS=(104.16.0.3 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 162.159.39.8)
for TARGET in 8898 8899; do
  echo "=== TARGET $TARGET ==="
  idx=0
  for IP in "${IPS[@]}"; do
    LP=$((48000 + TARGET + idx))
    LOG=/tmp/wst-${TARGET}-${idx}.log
    rm -f "$LOG"
    "$WST" client -L tcp://127.0.0.1:${LP}:127.0.0.1:${TARGET} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${IP}:2053 >/dev/null 2>"$LOG" &
    PID=$!
    sleep 1
    python3 - "$LP" <<'PY'
import socket, sys, time
lp = int(sys.argv[1])
try:
    s = socket.create_connection(("127.0.0.1", lp), timeout=2)
    s.settimeout(1)
    s.sendall(b"X" * 256)
    time.sleep(0.5)
    try:
        data = s.recv(1)
        print("recv_len", len(data))
    except Exception as e:
        print("recv_exc", repr(e))
    s.close()
    print("connect_ok")
except Exception as e:
    print("connect_fail", repr(e))
PY
    sleep 1
    if kill -0 "$PID" 2>/dev/null; then STATE=alive; else STATE=exited; fi
    echo "IP=$IP STATE=$STATE"
    sed -n '1,40p' "$LOG" || true
    kill "$PID" 2>/dev/null || true
    wait "$PID" 2>/dev/null || true
    idx=$((idx + 1))
  done
  echo
 done
RS
chmod +x /tmp/selfleg_dataplane_test.sh
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/selfleg_dataplane_test.sh root@114.80.33.50:/tmp/selfleg_dataplane_test.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/selfleg_dataplane_test.sh'