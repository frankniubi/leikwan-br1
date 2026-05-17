#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_wst_handshake_matrix.sh <<'RS'
#!/usr/bin/env bash
set -euo pipefail
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IPS=(185.220.238.143 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 104.16.0.3)
TARGETS=(8899 8898)
idx=0
for target in "${TARGETS[@]}"; do
  for ip in "${IPS[@]}"; do
    idx=$((idx+1))
    lp=$((51000+idx))
    log=/tmp/codex-wst-$target-$ip.log
    rm -f "$log"
    echo "=== ip=$ip target=$target lp=$lp ==="
    RUST_LOG=debug /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${ip}:2053 > /tmp/codex-wst-$target-$ip.out 2>"$log" &
    pid=$!
    sleep 0.8
    python3 - "$lp" <<'PY'
import socket, sys, time
lp=int(sys.argv[1])
try:
    s=socket.create_connection(('127.0.0.1', lp), timeout=2)
    s.settimeout(2)
    s.sendall(b'LEIKWAN_PROBE\n')
    time.sleep(1.0)
    try:
        data=s.recv(16)
        print('client_recv_len', len(data))
    except Exception as e:
        print('client_recv_exc', repr(e))
    s.close()
    print('client_connect_ok')
except Exception as e:
    print('client_connect_fail', repr(e))
PY
    sleep 2.0
    if kill -0 "$pid" 2>/dev/null; then state=alive; else state=exited; fi
    echo "state=$state"
    sed -n '1,160p' "$log" | sed 's/\x1b\[[0-9;]*m//g'
    kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    echo
  done
done
RS
chmod +x /tmp/codex_wst_handshake_matrix.sh
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_wst_handshake_matrix.sh root@114.80.33.50:/tmp/codex_wst_handshake_matrix.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/codex_wst_handshake_matrix.sh'