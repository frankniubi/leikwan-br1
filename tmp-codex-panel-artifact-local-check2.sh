set -u
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 || exit 1
printf '=== curl local artifact ===\n'
python3 - <<'PY'
import urllib.request, re, hashlib
for url in ['http://127.0.0.1/artifacts/sidecar-agent.py','http://127.0.0.1/artifacts/sidecar-manifest.json','http://127.0.0.1/artifacts/install-bond-sidecar.sh']:
    try:
        data=urllib.request.urlopen(url, timeout=10).read()
        print(url, 'ok', len(data), data[:80])
        if b'AGENT_VERSION' in data:
            m=re.search(rb'AGENT_VERSION\s*=\s*"([^"]+)"', data); print('version', m.group(1).decode() if m else '?', hashlib.sha256(data).hexdigest())
    except Exception as e: print(url,'ERR',repr(e))
PY
printf '=== direct file ===\n'
python3 - <<'PY'
import pathlib, re, hashlib
for p in ['sidecar-agent/sidecar_agent.py','install/install-bond-sidecar.sh','install/sidecar-agent-update.sh']:
 data=pathlib.Path(p).read_bytes(); print(p,len(data),hashlib.sha256(data).hexdigest());
 if b'AGENT_VERSION' in data:
  m=re.search(rb'AGENT_VERSION\s*=\s*"([^"]+)"',data); print('version',m.group(1).decode() if m else '?')
PY
