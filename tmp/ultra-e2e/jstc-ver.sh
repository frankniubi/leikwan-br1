echo '### jstc runtime + agent'
/usr/local/bin/ultra-client --version
grep -m1 '^AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py
echo '### failed units'
systemctl --failed --no-pager 2>/dev/null | tail -2
echo '### last status push'
journalctl -u leikwan-client-agent --no-pager -n 3 2>/dev/null | tail -3