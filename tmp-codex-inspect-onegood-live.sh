set -uo pipefail
echo "HOST=$(hostname) DATE=$(date -Is)"
echo '--- onegood process tree ---'
ps -eo pid,ppid,lstart,cmd | grep -E '2957636|2957639|leikwan-client-47-onegood|leikwan-bond|leikwan-ingress|wstunnel.*07289|127\.0\.0\.1:22740|162\.159\.39|47\.116\.11\.144|endpoint_id=316' | grep -v grep || true
echo '--- onegood start/config files ---'
for p in /usr/local/bin/leikwan-client-start-47-onegood.sh /tmp/codex-onegood-47.json /tmp/codex-onegood-47.sh /tmp/codex-onegood-47.service /etc/systemd/system/leikwan-client-47-onegood.service; do
  [ -e "$p" ] && { echo "### $p"; ls -l "$p"; sed -n '1,260p' "$p"; }
done
echo '--- active journal around restart ---'
journalctl -u leikwan-client-47-onegood.service --since '2026-05-17 01:48:00' --no-pager -o short-iso 2>/dev/null | tail -260 || true
echo '--- local /tmp onegood files ---'
find /tmp -maxdepth 2 -type f -iname '*onegood*' -o -iname '*bond*47*' 2>/dev/null | xargs -r ls -l
