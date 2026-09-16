echo '### served bytes'
curl -sk https://v3.leikwanhost.com/artifacts/ultra/ultra-client_windows_amd64.exe | sha256sum
curl -sk https://v3.leikwanhost.com/artifacts/windows/leikwan-bridge-setup.exe | sha256sum
echo '### manifest'
python3 - <<'PY'
import json, urllib.request
req = urllib.request.Request('https://v3.leikwanhost.com/artifacts/versions.json', headers={'User-Agent': 'curl/8'})
d = json.loads(urllib.request.urlopen(req, timeout=30).read())
rt = d['ultra_client_runtime_windows']; app = d['windows_client_app']
print('runtime', rt['version'], rt['sha256'][:16], rt['attestation']['fingerprint'])
print('app    ', app['version'], app['sha256'][:16], 'pin', app['runtime_sha256']['ultra_client_runtime_windows'][:16])
print('pin matches runtime:', app['runtime_sha256']['ultra_client_runtime_windows'] == rt['sha256'])
m = {}
for k, e in d.items():
    a = e.get('attestation') if isinstance(e, dict) else None
    if isinstance(a, dict) and a.get('fingerprint'):
        m.setdefault(a['fingerprint'], []).append(str(a.get('self_sha256'))[:12])
print('enforcement map:', json.dumps(m, indent=1))
PY