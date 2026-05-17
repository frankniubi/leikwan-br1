#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
for p in 5201 5202 22 8899 8898 2053; do
  echo "=== public 185.220.238.143:$p ==="
  timeout 5 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1; echo tcp_rc=$?
  if [ "$p" = 5201 ] || [ "$p" = 5202 ]; then
    timeout 12 iperf3 -c 185.220.238.143 -p $p -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,80p'; echo iperf_rc=${PIPESTATUS[0]}
  fi
done
REMOTE
