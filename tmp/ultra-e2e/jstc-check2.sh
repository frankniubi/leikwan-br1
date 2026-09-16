echo '### agent version + update state'
grep -m1 '^AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/agent_update_state.json'));print({k:d.get(k) for k in ('status','installed_version','last_error')})"
echo '### failed units'
systemctl --failed --no-pager 2>/dev/null | tail -2
echo '### runtime'
/usr/local/bin/ultra-client --version