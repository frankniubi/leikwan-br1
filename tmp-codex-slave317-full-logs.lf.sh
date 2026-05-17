set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
echo '-- service 317 current --'
systemctl status wstunnel-svc-2-317.service --no-pager -l | sed -n '1,80p'
echo '-- tcp mux current --'
systemctl status leikwan-tcp-mux-7000.service --no-pager -l | sed -n '1,80p'
echo '-- 317 logs since 20:48 UTC --'
journalctl -u wstunnel-svc-2-317.service --since '2026-05-16 20:48:00 UTC' --no-pager -o short-iso | sed -n '1,220p'
echo '-- mux logs since 20:48 UTC --'
journalctl -u leikwan-tcp-mux-7000.service --since '2026-05-16 20:48:00 UTC' --no-pager -o short-iso | sed -n '1,220p'
echo '-- processes --'
ps -ef | grep -E 'wstunnel|leikwan-ingress' | grep -v grep
REMOTE