#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_BASE=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=6 -o NumberOfPasswordPrompts=1)
PROXY="sshpass -p '$SLAVE_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=6 -W %h:%p root@$SLAVE_HOST"
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
for port in 22 14950 14969; do
  echo "=== 185.220.238.143:$port ==="
  for p in "${passwords[@]}"; do
    label=$(python3 - "$p" <<'PY'
import hashlib,sys
print(hashlib.sha256(sys.argv[1].encode()).hexdigest()[:8])
PY
)
    out=$(sshpass -p "$p" ssh "${SSH_BASE[@]}" -o ProxyCommand="$PROXY" -p "$port" root@185.220.238.143 'echo SIDE_OK $(hostname) $(date -Is)' 2>&1 </dev/null | tail -n 3 || true)
    if grep -q SIDE_OK <<<"$out"; then
      echo "PASS_HASH=$label SUCCESS $out"
      exit 0
    else
      echo "PASS_HASH=$label fail $(echo "$out" | tr '\n' ' ' | sed 's/Permission denied.*/Permission denied/; s/.*Connection refused.*/Connection refused/; s/.*Connection timed out.*/Connection timed out/' | cut -c1-160)"
    fi
  done
 done
exit 1
