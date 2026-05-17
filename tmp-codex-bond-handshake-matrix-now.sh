set -euo pipefail
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
cat >/tmp/codex_bond_handshake_matrix.sh <<'RS'
set +e
SCRIPT=/usr/local/bin/leikwan-client-start-47.sh
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
TOKEN=$(grep -oE "--session-token '[^']+'" "$SCRIPT" | head -1 | sed -E "s/--session-token '([^']+)'/\1/")
IPS=(185.220.238.143 104.16.0.3 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 162.159.39.8)
for ip in "${IPS[@]}"; do
  lp=$(python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
)
  bp=$(python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
)
  wlog=/tmp/codex-wst-bond-$ip.log; blog=/tmp/codex-bond-$ip.log
  rm -f "$wlog" "$blog"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${ip}:2053 >/dev/null 2>"$wlog" & wpid=$!
  sleep 0.35
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${lp}?priority=10&display_host=self_leg&role=primary" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 400 >"$blog" 2>&1 & bpid=$!
  sleep 3.0
  if grep -q 'bond client TCP transport session established' "$blog"; then res=established; elif grep -q 'Invalid status code: 520' "$wlog"; then res=520; elif grep -q 'failed to establish persistent bond TCP transport' "$blog"; then res=no_ack; else res=unknown; fi
  echo "=== $ip $res ==="
  grep -E 'bond client TCP transport session established|failed to connect initial bond leg|failed to establish|Invalid status code|failed to do websocket|early eof|Connection reset' "$blog" "$wlog" | tail -8 || true
  kill "$bpid" "$wpid" 2>/dev/null; wait "$bpid" "$wpid" 2>/dev/null
 done
RS
sshpass -e scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no /tmp/codex_bond_handshake_matrix.sh root@114.80.33.50:/tmp/codex_bond_handshake_matrix.sh >/dev/null
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'bash /tmp/codex_bond_handshake_matrix.sh'
