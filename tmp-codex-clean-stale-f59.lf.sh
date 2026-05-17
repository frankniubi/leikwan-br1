set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is)"
echo '-- kill stale forward59 32501 processes so f47-only test is isolated --'
ps -eo pid,args | awk '/leikwan-bond client/ && /:32501/ {print $1}' | xargs -r kill
ps -eo pid,args | awk '/leikwan-ingress client/ && /566ff12a38fd8869/ {print $1}' | xargs -r kill
systemctl reset-failed leikwan-client-59.service 2>/dev/null || true
sleep 1
ss -ltnp | grep -E ':(32501|11035|2274[0-9])\b' || true
journalctl -u leikwan-client-47-onegood.service --since '-5 min' --no-pager -o short-iso | grep -E 'session established|failed|invalid bond|read bond|WARN|ERROR|cooldown|local_read|local_write|recv_reset' | tail -80 || true
REMOTE