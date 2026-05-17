#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e
  echo ---service-json---
  sed -n "1,220p" /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json 2>/dev/null || true
  echo ---agent-code-snippet---
  grep -n -E "is_udp_uot_shadow_metadata|backend_protocol == .tcp_relay.|backend_mode == .cf_mode.|render_cf_wss_backend_unit" /usr/local/bin/leikwan-slave-agent.py | sed -n "1,80p"
  echo ---public artifact curl sha---
  python3 - <<"PY"
import urllib.request,hashlib
for url in ["https://v3-mirror.leikwanhost.com/artifacts/slave-agent.py", "https://v3-mirror.leikwanhost.com/artifacts/versions.json"]:
    try:
        data=urllib.request.urlopen(url, timeout=10).read()
        print(url, len(data), hashlib.sha256(data).hexdigest(), data[:200])
    except Exception as e: print(url, "ERR", repr(e))
PY
'
