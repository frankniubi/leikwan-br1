#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
echo "date=$(date -Is) host=$(hostname)"
echo "=== direct public sidecar iperf health ==="
for p in 5201 5202; do
  echo "-- public sidecar $p reverse -b 300M --"
  timeout 18 iperf3 -c 185.220.238.143 -p $p -R -b 300M -t 6 --connect-timeout 3000 2>&1 | sed -n '1,120p'
  sleep 1
  echo "-- public sidecar $p forward -b 300M --"
  timeout 14 iperf3 -c 185.220.238.143 -p $p -b 300M -t 4 --connect-timeout 3000 2>&1 | sed -n '1,100p'
  sleep 1
done
REMOTE
