#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE bash -s" <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
echo '-- tcp ports sidecar direct from slave --'
for p in 22 2053 8899 8898 5201 5202 14969; do timeout 3 bash -lc "</dev/tcp/$SIDE/$p" >/dev/null 2>&1; echo "$p rc=$?"; done
echo '-- iperf3 direct 5201 reverse 5s 300M --'
command -v iperf3 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y iperf3 >/dev/null)
timeout 15 iperf3 -c "$SIDE" -p 5201 -R -b 300M -t 5 --connect-timeout 3000; echo iperf5201_rc=$?
echo '-- iperf3 direct 5202 reverse 5s 300M --'
timeout 15 iperf3 -c "$SIDE" -p 5202 -R -b 300M -t 5 --connect-timeout 3000; echo iperf5202_rc=$?
REMOTE
