#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank20021212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -e ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash -s' <<'RS'
set +e
echo REMOTE_OK
hostname
date -Is
whoami
echo '=== services ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service 2>/dev/null || true
echo '=== versions/files/runtime ==='
python3 - <<'PY'
import importlib.util, pathlib, json, hashlib
for p in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py','/usr/local/bin/leikwan-mptcp-relay','/usr/local/bin/wstunnel','/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/bond-forward-47.json','/usr/local/bin/leikwan-client-start-47.sh','/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json']:
    path=pathlib.Path(p)
    print('FILE',p,'exists',path.exists(),'size',path.stat().st_size if path.exists() else '')
    if path.exists() and path.is_file():
        print('sha256', hashlib.sha256(path.read_bytes()).hexdigest())
try:
    spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
    m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    print('AGENT_VERSION',m.AGENT_VERSION)
except Exception as e: print('AGENT_IMPORT_ERR',repr(e))
for p in ['/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/bond-forward-47.json']:
    path=pathlib.Path(p)
    if path.exists():
        try:
            d=json.loads(path.read_text())
            keys=['remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','legs','subflow_limit','listen_host','listen_port','session_token','chunk_size','max_window_bytes','connect_timeout_ms','join_timeout_ms']
            print('JSON',p,json.dumps({k:d.get(k) for k in keys if k in d},ensure_ascii=False,indent=2))
        except Exception as e: print('JSON_ERR',p,repr(e))
PY
echo '=== start script excerpt ==='
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null | sed -E 's/(session-token[ =])[^ ]+/\1REDACTED/g' || true
echo '=== listeners ==='
ss -ltnp | grep -E ':11035|:5201|:2053|:2054|:8898|:8899|:43(279|280|281|282|283|284)|:45(0|1)[0-9][0-9]' || true
echo '=== f47 processes ==='
ps -ef | grep -E 'leikwan.*47|wstunnel|mptcp.*47|bond.*47' | grep -v grep || true
echo '=== selfleg cache ==='
cat /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json 2>/dev/null || true; echo
echo '=== pull state tail ==='
cat /var/lib/leikwan-client-agent/config_pull_state.json 2>/dev/null | head -c 4000 || true; echo
echo '=== agent journal ==='
journalctl -u leikwan-client-agent.service --since '-10 min' --no-pager -n 120 || true
echo '=== f47 journal ==='
journalctl -u leikwan-client-47.service --since '-10 min' --no-pager -n 140 || true
RS
