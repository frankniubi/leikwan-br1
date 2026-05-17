set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== routes artifact sidecar ===\n'
grep -R "sidecar-agent.py\|sidecar-agent-update\|sidecar/manifest\|artifacts/sidecar" -n master/src master/public .htaccess 2>/dev/null | head -80
printf '=== curl local artifact ===\n'
python3 - <<'PY'
import urllib.request, re, hashlib
for url in ['http://127.0.0.1/artifacts/sidecar-agent.py','http://127.0.0.1/artifacts/sidecar/manifest','http://127.0.0.1/api/artifacts/sidecar/manifest']:
    try:
        data=urllib.request.urlopen(url, timeout=10).read()
        print(url, 'ok', len(data), data[:80])
        if b'AGENT_VERSION' in data:
            m=re.search(rb'AGENT_VERSION\s*=\s*"([^"]+)"', data); print('version', m.group(1).decode() if m else '?', hashlib.sha256(data).hexdigest())
    except Exception as e: print(url,'ERR',repr(e))
PY
