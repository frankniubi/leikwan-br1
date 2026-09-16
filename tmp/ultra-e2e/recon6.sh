echo '### sha of managed bins'
for f in /usr/local/bin/ultra-client /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-client-agent-update; do printf '%s  ' $f; sha256sum $f 2>/dev/null | cut -d' ' -f1; done
echo '### install-health.json'
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/install-health.json'));print(json.dumps(d,indent=1)[:3000])"
echo '### agent_update_state'
cat /var/lib/leikwan-client-agent/agent_update_state.json
echo '### installed.json (applied)'
python3 -c "import json;d=json.load(open('/var/lib/leikwan-client-agent/applied.json'));print(json.dumps(d,indent=1)[:1200])"
echo '### done'