set -euo pipefail
sshpass -p 'Frank20021212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set +e
kill 2729861 2729855 2729849 2>/dev/null || true
pkill -f '127.0.0.1:2915' 2>/dev/null || true
echo cleaned
ss -ltnp | grep -E ':2915' || true
REMOTE