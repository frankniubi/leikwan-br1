set +e
echo '--- client final state ---'
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47.service 2>/dev/null || true) pid=$(systemctl show -p MainPID --value leikwan-client-47.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47-onegood.service 2>/dev/null || true) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-47|11035|22740|leikwan-mptcp-relay' | grep -v grep || true
journalctl -u leikwan-client-47.service --since '-3 min' --no-pager -o short-iso | tail -80 || true