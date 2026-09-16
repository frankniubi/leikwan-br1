echo '### control-plane-endpoint'
cat /var/lib/leikwan-client-agent/control-plane-endpoint.json; echo
echo '### mirror manifest from JSTC'
curl -s --max-time 25 https://api-leikwanbridge.nbwly.com/artifacts/versions.json -o /tmp/mirror.json -w 'http=%{http_code} bytes=%{size_download} time=%{time_total}\n'
python3 -c "import json;d=json.load(open('/tmp/mirror.json'));e=d['ultra_client_runtime'];print('mirror ultra:',e['version'],e['sha256'],e['size_bytes'])" 2>&1 | tail -2
echo '### update unit state'
systemctl status 'leikwan-client-agent-update-1789305595-auto-rel.service' --no-pager 2>&1 | tail -12
echo '### runtime sha now'
sha256sum /usr/local/bin/ultra-client
echo '### agent update state'
cat /var/lib/leikwan-client-agent/agent_update_state.json; echo