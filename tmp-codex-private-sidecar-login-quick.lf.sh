#!/usr/bin/env bash
set -u
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=4 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
for ip in 10.203.201.179 185.220.238.143; do
  echo "=== $ip ==="
  for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'U3I+sVL^%IXyqMX0' '@XwDQ6H5mgMXbrGD' 'RQRUbOkeh7ZpCkDa' 'b+z@Qk860a+6UsVW'; do
    lab=$(printf %s "$pass"|sha256sum|cut -c1-8)
    out=$(timeout 8 sshpass -p "$pass" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=4 -o ConnectionAttempts=1 root@$ip 'echo OK; hostname; date -Is; id' 2>&1 </dev/null | tail -6 || true)
    if echo "$out"|grep -q '^OK'; then echo "LOGIN ip=$ip passhash=$lab"; echo "$out"; exit 0; else echo "fail ip=$ip passhash=$lab $(echo "$out"|tr '\n' ' '|cut -c1-140)"; fi
  done
done
REMOTE