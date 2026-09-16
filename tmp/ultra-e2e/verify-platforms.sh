python3 - <<'PY'
import hashlib, json, pathlib, urllib.request
root = pathlib.Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3')
req = urllib.request.Request('https://v3.leikwanhost.com/artifacts/versions.json', headers={'User-Agent': 'curl/8'})
d = json.loads(urllib.request.urlopen(req, timeout=30).read())
print('published platform runtimes:')
for key in ('ultra_client_runtime','ultra_client_runtime_linux_arm64','ultra_client_runtime_macos_x64','ultra_client_runtime_macos_arm64','ultra_client_runtime_android_arm64','ultra_client_runtime_android_x86_64','ultra_client_runtime_windows'):
    e = d[key]
    local = hashlib.sha256((root / e['path']).read_bytes()).hexdigest()
    print('  %-36s %s %s %s' % (key, e['version'], e['sha256'][:16], 'OK' if local == e['sha256'] else 'LOCAL MISMATCH'))
m = {}
for k, e in d.items():
    a = e.get('attestation') if isinstance(e, dict) else None
    if isinstance(a, dict) and a.get('fingerprint'):
        m.setdefault(a['fingerprint'], []).append(str(a.get('self_sha256'))[:12])
print('enforcement map:')
for fp, hashes in m.items():
    print('  %s -> %d hashes' % (fp, len(hashes)))
print('  2026.09.13.3 hashes:', sorted(m.get('2026.09.13.3.452c8fe8', [])))
PY