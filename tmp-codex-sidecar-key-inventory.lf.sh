#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SIDE=185.220.238.143
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e
 echo "=== slave key inventory $(hostname) $(date -Is) ==="
 ls -la ~/.ssh 2>/dev/null || true
 for f in ~/.ssh/id_* ~/.ssh/*key* ~/.ssh/*.pem; do [ -f "$f" ] || continue; echo "--- try $f"; chmod 600 "$f" 2>/dev/null || true; ssh -i "$f" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 root@185.220.238.143 "echo SIDE_OK; hostname; date -Is; id" 2>&1 | sed -n "1,20p"; done
 echo "=== authorized aliases/config ==="; for f in ~/.ssh/config /etc/ssh/ssh_config /root/.ssh/known_hosts; do [ -f "$f" ] && echo ---$f && sed -n "1,120p" "$f"; done
'