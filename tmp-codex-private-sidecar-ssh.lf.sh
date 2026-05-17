#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "slave $(hostname) $(date -Is)"
ip -4 addr | grep -E "10\.203\.201\.|lwf47" || true
for ip in 10.203.201.179 10.203.201.183 10.203.201.191 10.203.201.175 185.220.238.143; do
  echo "-- $ip tcp --"
  for p in 22 8899 8898 2053 5201; do timeout 2 bash -lc "</dev/tcp/$ip/$p" >/dev/null 2>&1 && echo "$ip:$p open" || true; done
  for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'U3I+sVL^%IXyqMX0'; do
    out=$(timeout 8 sshpass -p "$pass" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o NumberOfPasswordPrompts=1 -o ConnectTimeout=4 root@$ip 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null | tail -5 || true)
    if echo "$out" | grep -q SIDE_OK; then echo "LOGIN $ip pass_len=${#pass}"; echo "$out"; exit 0; fi
  done
done
REMOTE