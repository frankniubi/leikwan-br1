echo '### runtime'
/usr/local/bin/ultra-client --version
sha256sum /usr/local/bin/ultra-client | cut -c1-16
echo '### agent update state'
cat /var/lib/leikwan-client-agent/config_pull_state.json | python3 -c "import json,sys; d=json.load(sys.stdin); print('token:', d.get('agent_update_request_token')); print('agent_version:', d.get('agent_version'))"
echo '### failed units'
systemctl --failed --no-pager 2>/dev/null | tail -2