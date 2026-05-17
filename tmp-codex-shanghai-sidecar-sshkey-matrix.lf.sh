#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SIDE=185.220.238.143
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=publickey,password -o BatchMode=yes -o ConnectTimeout=6 -o ServerAliveInterval=3 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  [ -n "$host" ] || continue
  echo "=== $host -> sidecar ssh key test ==="
  sshpass -p "$pass" ssh -n -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 "$user@$host" "ssh ${SSH_OPTS[*]} root@$SIDE 'echo OK; hostname; date -Is; id' 2>&1 | sed -n '1,20p'" 2>&1 | sed -n '1,40p' || true
  echo
 done < shanghais.txt