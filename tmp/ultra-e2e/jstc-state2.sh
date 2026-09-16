echo '### agent journal tail'
journalctl -u leikwan-client-agent --no-pager -n 6 2>/dev/null | tail -6
echo '### update units'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'leikwan-client-agent-update'