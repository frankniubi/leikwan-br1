set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
pgrep -af 'wstunnel.*07289d1f5ed25399' || true
pids=$(pgrep -f 'wstunnel.*07289d1f5ed25399' || true)
echo "pids=[$pids]"
if [ -n "$pids" ]; then kill $pids 2>/dev/null || true; sleep .5; fi
pgrep -af 'wstunnel.*07289d1f5ed25399' || true
ss -ltnp | grep -E ':(13865|32965|32054|3223[34])\b' || true
REMOTE