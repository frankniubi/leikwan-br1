set +e
echo date=$(date -Is)
systemctl is-active leikwan-client-agent.service leikwan-client-47-onegood.service leikwan-client-47.service 2>/dev/null || true
ss -ltnp | grep -E ':(11035|22740|32501)' || true
journalctl -u leikwan-client-47-onegood.service --since '-4 min' --no-pager -o short-iso | tail -180
echo --iperfout--
cat /tmp/codex-onegood47-iperf2.out 2>/dev/null || true
