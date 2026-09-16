sleep 90
echo '### agent'
grep -m1 '^AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py
systemctl show leikwan-client-agent -p WatchdogUSec --value
systemctl show leikwan-client-agent -p ActiveEnterTimestamp --value
echo '### update units'
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'leikwan-client-agent-update' || true
echo '### watchdog kills since the update'
journalctl -u leikwan-client-agent --no-pager --since '-20min' 2>/dev/null | grep -c 'Watchdog timeout' || true
echo '### failed units'
systemctl --failed --no-pager 2>/dev/null | tail -2