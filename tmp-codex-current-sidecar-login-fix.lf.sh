#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SPASS_ESC=${SPASS//%/%%}
PROXY="sshpass -p '$SPASS_ESC' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 -W %h:%p root@$SLAVE"
for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'U3I+sVL^%IXyqMX0' '@XwDQ6H5mgMXbrGD' 'RQRUbOkeh7ZpCkDa'; do
  label=$(PASS="$pass" python3 - <<'PY'
import hashlib, os
print(hashlib.sha256(os.environ['PASS'].encode()).hexdigest()[:8])
PY
)
  echo "try passhash=$label"
  set +e
  out=$(sshpass -p "$pass" ssh -T -o ProxyCommand="$PROXY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null)
  rc=$?
  set -e
  echo "rc=$rc"; echo "$out" | tail -8
  echo "$out" | grep -q SIDE_OK && exit 0
 done
exit 1
