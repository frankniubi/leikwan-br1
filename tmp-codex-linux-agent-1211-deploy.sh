#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
WORKDIR='/mnt/c/Users/frankniubi/Downloads/leikwan-br1'
PANEL_ROOT='/www/wwwroot/v3.leikwanhost.com/leikwan-v3'
cd "$WORKDIR"
python3 -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py
python3 - <<'PY'
import json, pathlib, hashlib, re
versions=json.loads(pathlib.Path('artifacts/versions.json').read_text(encoding='utf-8'))
agent_text=pathlib.Path('client-agent/client_agent.py').read_text(encoding='utf-8')
ver=re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', agent_text).group(1)
assert ver == versions['client_agent']['version'], (ver, versions['client_agent']['version'])
for key, rel in [('client_agent','client-agent/client_agent.py'), ('install_forward_runtime','client-agent/install_forward_runtime.py')]:
    p=pathlib.Path(rel)
    assert versions[key]['sha256']==hashlib.sha256(p.read_bytes()).hexdigest(), key
    assert versions[key]['size_bytes']==p.stat().st_size, key
print(json.dumps({k: versions[k] for k in ['client_agent','install_forward_runtime']}, indent=2))
PY
# publish to panel artifacts so Linux self-update will stop looping
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py artifacts/versions.json root@10.0.10.196:/tmp/ >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 "set -e; cd $PANEL_ROOT; install -m 0644 /tmp/client_agent.py client-agent/client_agent.py; install -m 0755 /tmp/client_agent.py artifacts/client-agent.py; install -m 0644 /tmp/install_forward_runtime.py client-agent/install_forward_runtime.py; install -m 0755 /tmp/install_forward_runtime.py artifacts/install-forward-runtime.py; install -m 0644 /tmp/versions.json artifacts/versions.json; php -r 'json_decode(file_get_contents(\"artifacts/versions.json\"), true, 512, JSON_THROW_ON_ERROR); echo \"versions ok\\n\";'; python3 -m py_compile artifacts/client-agent.py artifacts/install-forward-runtime.py; sha256sum artifacts/client-agent.py artifacts/install-forward-runtime.py; grep -A6 '\"client_agent\"' artifacts/versions.json; grep -A6 '\"install_forward_runtime\"' artifacts/versions.json"
# sync directly to Linux client and clear stale self-update state/cache
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py root@114.80.33.50:/tmp/ >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set -e
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
python3 - <<"PY"
import importlib.util, json, pathlib, re, time
spec=importlib.util.spec_from_file_location("ca","/usr/local/bin/leikwan-client-agent.py")
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
print("agent_version", m.AGENT_VERSION)
state=pathlib.Path("/var/lib/leikwan-client-agent/agent_update_state.json")
payload={}
if state.exists():
    try: payload=json.loads(state.read_text())
    except Exception: payload={}
# The previous state was stuck at running for the exact auto-release token; mark it complete after direct verified install.
token=str(payload.get("request_token", ""))
if token.startswith("auto-release:agent=2026.05.12.11") or token.startswith("auto-release:agent_sha256=") or token.startswith("auto-release:"):
    payload.update({"status":"success","last_completed_request_token":token,"last_completed_at":time.time(),"installed_version":m.AGENT_VERSION,"last_error":""})
    state.write_text(json.dumps(payload, indent=2), encoding="utf-8")
PY
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json
systemctl daemon-reload
systemctl reset-failed leikwan-client-agent.service leikwan-client-47.service || true
systemctl restart leikwan-client-agent.service
sleep 12
systemctl is-active leikwan-client-agent.service
cat /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo ---pull-state---
cat /var/lib/leikwan-client-agent/config_pull_state.json 2>/dev/null || true
echo ---agent-log---
journalctl -u leikwan-client-agent.service -n 80 --no-pager | tail -n 80
'
