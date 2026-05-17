set -euo pipefail
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
cat >/tmp/codex_wst_matrix_now.sh <<'RS'
set +e
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IPS=(104.16.0.3 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 162.159.39.8)
for ip in "${IPS[@]}"; do
  lp=$(python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
)
  log=/tmp/codex-wst-now-$ip.log
  rm -f "$log"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${ip}:2053 >/dev/null 2>"$log" &
  pid=$!
  sleep 0.5
  python3 - "$lp" <<'PY'
import socket, sys, time
lp=int(sys.argv[1])
try:
    s=socket.create_connection(('127.0.0.1', lp), timeout=1)
    s.settimeout(1.2)
    s.sendall(b'X')
    time.sleep(0.7)
    try: data=s.recv(1); print('recv', len(data))
    except Exception as e: print('recv_exc', type(e).__name__)
    s.close()
except Exception as e: print('connect_fail', repr(e))
PY
  sleep 1.3
  if grep -q 'Invalid status code: 520' "$log"; then res=520; elif grep -q 'failed to do websocket handshake' "$log"; then res=ws_fail; elif kill -0 "$pid" 2>/dev/null; then res=alive; else res=exited; fi
  err=$(grep -E 'Invalid status code|failed to do websocket handshake|HTTP status|ERROR' "$log" | tail -3 | tr '\n' ' ')
  echo "$ip result=$res $err"
  kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
 done
RS
sshpass -e scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no /tmp/codex_wst_matrix_now.sh root@114.80.33.50:/tmp/codex_wst_matrix_now.sh >/dev/null
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'bash /tmp/codex_wst_matrix_now.sh'
