#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'
cd "$WORKDIR"
python3 - <<'PY'
import json, hashlib, pathlib
p=pathlib.Path('artifacts/versions.json')
versions=json.loads(p.read_text(encoding='utf-8'))
b=pathlib.Path('client-agent/client_agent.py').read_bytes()
versions['client_agent']['version']='2026.05.12.10'
versions['client_agent']['sha256']=hashlib.sha256(b).hexdigest()
versions['client_agent']['size_bytes']=len(b)
p.write_text(json.dumps(versions, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(json.dumps(versions['client_agent'], indent=2))
PY
python3 -m py_compile client-agent/client_agent.py
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; cd $PANEL_ROOT; install -m 0644 /tmp/client_agent.py client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py artifacts/client-agent.py; install -m 0644 /tmp/versions.json artifacts/versions.json; php -r 'json_decode(file_get_contents(\"artifacts/versions.json\"), true, 512, JSON_THROW_ON_ERROR); echo \"versions ok\\n\";'; sha256sum artifacts/client-agent.py client-agent/client_agent.py; grep -A5 '\"client_agent\"' artifacts/versions.json"
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py root@114.80.33.50:/tmp/leikwan-client-agent.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set -e; install -m 0644 /tmp/leikwan-client-agent.py /usr/local/bin/leikwan-client-agent.py; python3 -m py_compile /usr/local/bin/leikwan-client-agent.py; python3 /usr/local/bin/leikwan-client-agent.py --config /etc/leikwan-client-agent/config.json version; sha256sum /usr/local/bin/leikwan-client-agent.py; rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json; systemctl restart leikwan-client-agent.service; sleep 4; systemctl is-active leikwan-client-agent.service; journalctl -u leikwan-client-agent.service -n 30 --no-pager | tail -n 30'