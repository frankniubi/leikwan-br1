echo '### running agent: attestation support?'
grep -c 'runtime_attestation' /usr/local/bin/leikwan-client-agent.py || true
echo '### running agent version'
grep -m1 '^AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py
echo '### update units'
systemctl list-units --all --no-pager 2>/dev/null | grep 'leikwan-client-agent-update' | tail -2
echo '### agent update state'
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/agent_update_state.json'));print({k:d.get(k) for k in ('request_token','status','installed_version','last_error')})"
echo '### recent update journal'
journalctl -u 'leikwan-client-agent-update*' --no-pager -n 5 2>/dev/null | tail -5