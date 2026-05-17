set -euo pipefail
PASS='@XwDQ6H5mgMXbrGD'
SLAVE='47.116.11.144'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE 'set -e; echo === slave keys ===; ls -la ~/.ssh 2>/dev/null || true; for f in ~/.ssh/*; do [ -f "$f" ] && echo ---$f && head -20 "$f"; done; echo === batch sidecar ===; ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 root@185.220.238.143 "echo SIDE_OK; hostname; id" 2>&1 || true'
