set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
PANEL=root@10.0.10.196
CLIENT=root@114.80.33.50
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json "$PANEL:/tmp/" >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'RS'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
install -m 0644 /tmp/client_agent.py client-agent/client_agent.py
install -m 0644 /tmp/versions.json artifacts/versions.json
php -l master/src/Controllers/ClientAgentController.php >/dev/null
php -l master/src/Controllers/ArtifactController.php >/dev/null
python3 -m py_compile client-agent/client_agent.py
printf 'panel client_agent sha '; sha256sum client-agent/client_agent.py | awk '{print $1}'
printf 'panel versions client_agent '; python3 - <<'PY'
import json; d=json.load(open('artifacts/versions.json')); print(d['client_agent'])
PY
RS
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py "$CLIENT:/tmp/" >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'RS'
set -euo pipefail
install -m 0644 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0644 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
python3 - <<'PY'
import importlib.util, pathlib, hashlib, json, time
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
sha=hashlib.sha256(pathlib.Path('/usr/local/bin/leikwan-client-agent.py').read_bytes()).hexdigest()
print('installed_agent_version', m.AGENT_VERSION)
print('installed_agent_sha256', sha)
state=pathlib.Path('/var/lib/leikwan-client-agent/agent_update_state.json')
payload={}
if state.exists():
    try: payload=json.loads(state.read_text())
    except Exception: payload={}
# mark both old and new artifact sha auto tokens complete enough to stop immediate rollback loop
for key in ['request_token','last_completed_request_token']:
    payload[key]='auto-release:agent_sha256='+sha
payload.update({'status':'success','last_completed_at':time.time(),'last_attempt_at':time.time(),'installed_version':m.AGENT_VERSION,'last_error':''})
state.parent.mkdir(parents=True, exist_ok=True)
state.write_text(json.dumps(payload, indent=2), encoding='utf-8')
PY
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json
systemctl daemon-reload
systemctl reset-failed leikwan-client-agent.service leikwan-client-47.service || true
systemctl restart leikwan-client-agent.service
sleep 70
systemctl is-active leikwan-client-agent.service leikwan-client-47.service || true
echo '=== hashes ==='
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
echo '=== update state ==='
cat /var/lib/leikwan-client-agent/agent_update_state.json || true
echo '=== f47 script line ==='
grep -E 'wstunnel client|leikwan-bond client' /usr/local/bin/leikwan-client-start-47.sh | sed -E "s/(session-token ')[^']+/'REDACTED/"
echo '=== agent restart health lines ==='
journalctl -u leikwan-client-agent.service --since '-80 sec' --no-pager | grep -E 'forward 47|self-leg|all slave endpoints|requested client self-update|Started|Stopped|applying forward 47|applied forward 47' || true
echo '=== f47 service ==='
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,45p'
RS
