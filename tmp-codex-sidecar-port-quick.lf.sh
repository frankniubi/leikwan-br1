#!/usr/bin/env bash
set -u
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=4 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
for ip in 10.203.201.179 10.203.201.175 10.203.201.183 10.203.201.187 10.203.201.191 185.220.238.143; do
  echo "=== $ip ==="
  for p in 22 14950 14969 14985 8899 8898 2053 5201 5202; do
    timeout 1 bash -lc "</dev/tcp/$ip/$p" >/dev/null 2>&1 && echo "open $p"
  done
done
REMOTE