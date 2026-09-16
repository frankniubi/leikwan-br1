python3 /root/prune-panel-attest.py
echo '### map now'
python3 - <<'PY'
import json
d = json.load(open('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json'))
m = {}
for k, e in d.items():
    a = e.get('attestation') if isinstance(e, dict) else None
    if isinstance(a, dict) and a.get('fingerprint'):
        m.setdefault(a['fingerprint'], []).append(str(a.get('self_sha256'))[:12])
print(json.dumps(m, indent=1))
PY