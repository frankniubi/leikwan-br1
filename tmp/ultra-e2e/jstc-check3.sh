grep -m1 '^AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py
systemctl show leikwan-client-agent -p WatchdogUSec --value
systemctl list-units --all --no-pager 2>/dev/null | grep -c 'leikwan-client-agent-update' || true