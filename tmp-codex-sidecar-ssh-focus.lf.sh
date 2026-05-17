#!/usr/bin/env bash
set -u
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
TARGET_HOST='185.220.238.143'
SSH_BASE=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
PROXY="sshpass -p '$SLAVE_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 -W %h:%p root@$SLAVE_HOST"
echo 'raw tcp via slave:'
sshpass -p "$SLAVE_PASS" ssh "${SSH_BASE[@]}" root@$SLAVE_HOST "for p in 22 14950 14969; do timeout 3 bash -lc '</dev/tcp/$TARGET_HOST/'\$p >/dev/null 2>&1 && echo \$p open || echo \$p fail; done" 2>&1
for port in 22 14950 14969; do
  echo "=== try target port $port ==="
  for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'Frank20021212' 'root' ''; do
    out=$(sshpass -p "$pass" ssh "${SSH_BASE[@]}" -o ProxyCommand="$PROXY" -p "$port" root@$TARGET_HOST 'echo OK $(hostname) $(date -Is); id; exit' 2>&1 </dev/null | tail -n 4 || true)
    echo "len=${#pass} out=$(echo "$out" | tr '\n' ' ' | sed 's/GK@[^ ]*/<redacted>/g' | cut -c1-220)"
    if grep -q '^OK ' <<<"$out"; then exit 0; fi
  done
done
exit 1