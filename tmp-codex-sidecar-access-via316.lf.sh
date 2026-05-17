#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='47.116.11.144'
SLAVE_PASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'bash -s' <<'REMOTE'
set +e
echo host=$(hostname) date=$(date -Is)
echo --tcp ports--; for p in 22 14950 14969 2053 8899 8898 5201; do timeout 3 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo $p open || echo $p fail; done
echo --ssh key?--; ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 root@185.220.238.143 'echo SIDE_OK; hostname; date -Is; id' 2>&1 | sed -n '1,40p'
echo --sshpass GK?--; command -v sshpass >/dev/null 2>&1 && sshpass -p 'GK@Sp7z%T!s4*w5P' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=6 root@185.220.238.143 'echo SIDE_OK; hostname; id' 2>&1 | sed -n '1,40p'
REMOTE