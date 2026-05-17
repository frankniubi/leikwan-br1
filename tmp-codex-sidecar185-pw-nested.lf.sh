#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE_PASS='$SIDEPASS' SIDE='$SIDE' bash -s" <<'OUTER'
set +euo pipefail
command -v sshpass >/dev/null || true
for port in 22 14950 14969 8899; do
  echo "=== sidecar $SIDE:$port ==="
  timeout 4 bash -lc "</dev/tcp/$SIDE/$port" >/dev/null 2>&1; echo tcp_rc=$?
  for pass in "$SIDE_PASS" 'Frank1212.' 'Frank20021212.' '@XwDQ6H5mgMXbrGD' 'RQRUbOkeh7ZpCkDa' 'U3I+sVL^%IXyqMX0' 'TnJKTGse+JMJFR%t' 'b+z@Qk860a+6UsVW'; do
    label=$(python3 - "$pass" <<'PY'
import hashlib,sys
print(hashlib.sha256(sys.argv[1].encode()).hexdigest()[:8])
PY
)
    out=$(sshpass -p "$pass" ssh -T -p "$port" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 -o ConnectionAttempts=1 root@$SIDE 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null | tail -6)
    rc=$?
    if echo "$out" | grep -q SIDE_OK; then echo "OK hash=$label port=$port"; echo "$out"; exit 0; else echo "fail hash=$label rc=$rc $(echo "$out" | tr '\n' ' ' | cut -c1-140)"; fi
  done
done
exit 1
OUTER
