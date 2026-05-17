#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
passwords=(
 'GK@Sp7z%T!s4*w5P'
 'Frank1212.'
 'Frank20021212.'
 '@XwDQ6H5mgMXbrGD'
 'RQRUbOkeh7ZpCkDa'
 'U3I+sVL^%IXyqMX0'
 'TnJKTGse+JMJFR%t'
 'b+z@Qk860a+6UsVW'
 '^XaL5MezM3pQ'
 'Ri*Qn7a=QwM6'
)
for p in "${passwords[@]}"; do
  label=$(python3 - <<PY
import hashlib
print(hashlib.sha256(b'''$p''').hexdigest()[:8])
PY
)
  echo "=== try sidecar pw hash=$label ==="
  set +e
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$p' bash -s" <<'OUTER'
set +e
command -v sshpass >/dev/null 2>&1 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass >/dev/null)
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o ConnectTimeout=8 -o ConnectionAttempts=1 -o NumberOfPasswordPrompts=1 root@$SIDE 'echo SIDE_OK $(hostname) $(date -Is); id; systemctl is-active leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service 2>/dev/null || true'
OUTER
  rc=$?
  set -e
  echo rc=$rc
  [ $rc -eq 0 ] && exit 0
done
exit 1