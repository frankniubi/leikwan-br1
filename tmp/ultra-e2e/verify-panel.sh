echo '### served bytes'
curl -sk https://v3.leikwanhost.com/artifacts/ultra/ultra-client_linux_amd64 | sha256sum
curl -sk https://v3.leikwanhost.com/artifacts/client-agent.py | sha256sum
echo '### manifest'
python3 - <<'PY'
import json, urllib.request
req = urllib.request.Request('https://v3.leikwanhost.com/artifacts/versions.json', headers={'User-Agent': 'curl/8'})
d = json.loads(urllib.request.urlopen(req, timeout=30).read())
print('runtime', d['ultra_client_runtime']['version'], d['ultra_client_runtime']['sha256'][:16], d['ultra_client_runtime']['attestation']['fingerprint'])
print('agent  ', d['client_agent']['version'], d['client_agent']['sha256'][:16])
published = {}
for key, entry in d.items():
    a = entry.get('attestation') if isinstance(entry, dict) else None
    if isinstance(a, dict) and a.get('fingerprint'):
        published.setdefault(a['fingerprint'], []).append(str(a.get('self_sha256'))[:12])
print('enforcement map:', json.dumps(published))
PY
echo '### jstc runtime'
/usr/local/bin/ultra-client --version