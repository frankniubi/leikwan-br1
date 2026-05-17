set -uo pipefail
echo "HOST=$(hostname) DATE=$(date -Is)"
echo '--- freeze client agent auto-update ---'
systemctl disable --now leikwan-client-agent.service 2>/dev/null || true
systemctl mask leikwan-client-agent.service 2>/dev/null || true
systemctl reset-failed leikwan-client-agent.service 2>/dev/null || true
systemctl list-unit-files '*leikwan-client-agent*' --no-pager 2>/dev/null || true
systemctl list-units '*leikwan-client-agent*' --all --no-pager 2>/dev/null || true
echo '--- f47 services/listeners ---'
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
ss -ltnp | grep -E ':(11035|22740|1431[0-9]|2331[0-9]|2531[0-9]|7000|7001|7002|8898|8899|2053|5201|5202)\b' || true
echo '--- key runtime files ---'
for p in /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /etc/systemd/system/leikwan-client-47.service /etc/systemd/system/leikwan-client-47-onegood.service /tmp/codex-onegood-47.json /tmp/codex-onegood-47.sh; do
  if [ -e "$p" ]; then echo "### $p"; ls -l "$p"; sed -n '1,220p' "$p" | head -220; fi
done
echo '--- recent f47 logs ---'
journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso 2>/dev/null | grep -E 'invalid bond frame magic|failed to read bond frame|hello-ack|no bond legs|session established|entering cooldown|local_read_failed|local_write_failed|recv_reset|Connection reset|Broken pipe|ERROR|WARN|started|Stopped' | tail -220 || true
