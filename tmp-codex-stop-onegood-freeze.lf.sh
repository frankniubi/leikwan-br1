set -euo pipefail
CPASS='Frank20021212.'
CLIENT='root@114.80.33.50'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
printf 'date=%s host=%s\n' "$(date -Is)" "$(hostname)"
# stop stale manual onegood to isolate tests; keep agent disabled
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127.0.0.1:2274' >/dev/null 2>&1 || true
printf 'agent='; systemctl is-active leikwan-client-agent.service 2>/dev/null || true; printf '/'; systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
printf 'f47='; systemctl is-active leikwan-client-47.service 2>/dev/null || true; printf ' onegood='; systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true
printf '\n-- stale listeners --\n'; ss -ltnp | grep -E '(:11035|:2274|:39559)' || true
REMOTE
