#!/usr/bin/env bash
set -euo pipefail
SLAVES=("47.116.11.144|@XwDQ6H5mgMXbrGD" "47.116.104.15|U3I+sVL^%IXyqMX0" "101.133.142.65|b+z@Qk860a+6UsVW" "47.116.134.3|RQRUbOkeh7ZpCkDa" "8.133.170.123|TnJKTGse+JMJFR%t")
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=4 -o ServerAliveCountMax=1)
for item in "${SLAVES[@]}"; do
  host=${item%%|*}; pass=${item#*|}
  echo "=== slave $host ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" root@$host 'bash -s' <<'REMOTE' || true
set +e
hostname; date -Is
echo --sidecar tcp--; for p in 22 8899 8898 2053 5201 14950 14969; do timeout 2 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo "$p open" || echo "$p fail"; done
echo --ssh key default--; ssh -T -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 root@185.220.238.143 'echo SIDE_OK; hostname; date -Is; id' 2>&1 | tail -8 || true
echo --key files--; ls -la ~/.ssh 2>/dev/null || true
REMOTE
done