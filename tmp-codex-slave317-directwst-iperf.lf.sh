#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
PASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set -euo pipefail
echo "=== slave direct-wstunnel iperf matrix $(hostname) $(date -Is) ==="
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
EDGES=(162.159.39.7 162.159.39.6 162.159.39.8 162.159.39.29 162.159.39.4 104.16.0.3)
BASE=/tmp/codex-slave317-wst-iperf
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
for idx in "${!EDGES[@]}"; do
  IP=${EDGES[$idx]}
  LP=$((33100+idx))
  LOG="$BASE/wst-$IP.log"
  echo "--- edge=$IP lp=$LP ---"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${LP}:127.0.0.1:5201 \
    --tls-sni-override "$SNI" --http-headers "Host: $SNI" \
    --http-upgrade-path-prefix "$PATHP" wss://${IP}:2053 >"$LOG" 2>&1 & echo $! >"$BASE/$idx.pid"
  for i in $(seq 1 40); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$LP" >/dev/null 2>&1 && break || sleep .1; done
  timeout 16 iperf3 -c 127.0.0.1 -p "$LP" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/iperf-$IP.out" 2>&1 || true
  cat "$BASE/iperf-$IP.out" | sed -n '1,30p'
  if grep -q 'bits/sec' "$BASE/iperf-$IP.out"; then grep -E 'receiver|sender|bits/sec' "$BASE/iperf-$IP.out" | tail -5; fi
  echo "log markers:"; grep -E 'Invalid status code|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' "$LOG" | tail -15 || true
  kill $(cat "$BASE/$idx.pid") 2>/dev/null || true; wait $(cat "$BASE/$idx.pid") 2>/dev/null || true
  sleep .5
done
REMOTE
