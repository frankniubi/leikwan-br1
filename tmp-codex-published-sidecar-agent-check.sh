set -euo pipefail
printf '=== current sidecar agent publish check ===\n'
python3 - <<'PY'
import urllib.request, re, hashlib
url='https://v3.leikwanhost.com/artifacts/sidecar-agent.py'
try:
    data=urllib.request.urlopen(url, timeout=20).read()
    text=data.decode('utf-8', errors='replace')
    m=re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text)
    print('version', m.group(1) if m else '?')
    print('sha', hashlib.sha256(data).hexdigest(), 'bytes', len(data))
except Exception as e: print('ERR', repr(e))
PY
