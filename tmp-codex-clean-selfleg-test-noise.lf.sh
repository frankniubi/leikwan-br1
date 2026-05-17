set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service leikwan-client-47.service >/dev/null 2>&1 || true
systemctl disable leikwan-client-47-onegood.service leikwan-client-47.service >/dev/null 2>&1 || true
# clean leaked self-leg test wstunnels from prior interrupted runs only
pkill -f 'wstunnel client .*07289d1f5ed25399.*nrt1\.nbwly\.com' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:2274' 2>/dev/null || true
sleep .5
echo "cleaned date=$(date -Is) agent=$(systemctl is-active leikwan-client-agent.service)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47-onegood.service 2>/dev/null || true)"
ps -eo pid,ppid,cmd | grep -E '07289d1f5ed25399|leikwan-client-47-onegood|leikwan-client-47.service|leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035|leikwan-ingress client --listen 127\.0\.0\.1:2274' | grep -v grep || true
ss -ltnp | grep -E ':(11035|2274[0-9]|13865|32965|32054|3223[34])\b' || true
REMOTE