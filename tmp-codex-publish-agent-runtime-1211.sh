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
for key, rel, ver in [
    ('client_agent', 'client-agent/client_agent.py', '2026.05.12.11'),
    ('install_forward_runtime', 'client-agent/install_forward_runtime.py', '2026.05.16.2'),
]:
    b=pathlib.Path(rel).read_bytes()
    versions[key]['version']=ver
    versions[key]['sha256']=hashlib.sha256(b).hexdigest()
    versions[key]['size_bytes']=len(b)
p.write_text(json.dumps(versions, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(json.dumps({k: versions[k] for k in ['client_agent','install_forward_runtime']}, indent=2))
PY
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py artifacts/versions.json root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; cd $PANEL_ROOT; install -m 0644 /tmp/client_agent.py client-agent/client_agent.py; install -m 0644 /tmp/client_agent.py artifacts/client-agent.py; install -m 0644 /tmp/install_forward_runtime.py client-agent/install_forward_runtime.py; install -m 0644 /tmp/install_forward_runtime.py artifacts/install-forward-runtime.py; install -m 0644 /tmp/versions.json artifacts/versions.json; php -r 'json_decode(file_get_contents(\"artifacts/versions.json\"), true, 512, JSON_THROW_ON_ERROR); echo \"versions ok\\n\";'; sha256sum artifacts/client-agent.py artifacts/install-forward-runtime.py; grep -A5 '\"client_agent\"' artifacts/versions.json; grep -A5 '\"install_forward_runtime\"' artifacts/versions.json"
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py root@114.80.33.50:/tmp/ >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set -e
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
python3 - <<"PY"
import importlib.util
spec=importlib.util.spec_from_file_location("ca","/usr/local/bin/leikwan-client-agent.py")
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); print("agent_version", m.AGENT_VERSION)
PY
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json
systemctl restart leikwan-client-agent.service
sleep 8
systemctl is-active leikwan-client-agent.service
journalctl -u leikwan-client-agent.service -n 60 --no-pager | tail -n 60
'