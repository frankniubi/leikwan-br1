#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SLAVE=root@47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=root@185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
PROXY="sshpass -p '$SPASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -W %h:%p $SLAVE"
# push direct runtime files to client so we don't wait for old frozen updater
sshpass -p "$CPASS" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py "$CLIENT:/tmp/" >/dev/null
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl stop leikwan-client-agent.service >/dev/null 2>&1 || true
install -m 0755 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0755 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
install -m 0755 /tmp/leikwan_mptcp_client.py /usr/local/bin/leikwan-mptcp-client.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
python3 - <<'PY'
import importlib.util, pathlib, hashlib
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
print('client_agent_version', ca.AGENT_VERSION)
for q in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py']:
 pp=pathlib.Path(q); print(q, hashlib.sha256(pp.read_bytes()).hexdigest(), pp.stat().st_size)
PY
systemctl enable --now leikwan-client-agent.service >/dev/null 2>&1 || systemctl restart leikwan-client-agent.service
REMOTE
# update sidecar agent through slave hop; leave disable_auto_update in config but install code for peer management
sshpass -p "$SIDEPASS" scp "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" sidecar-agent/sidecar_agent.py "$SIDE:/tmp/sidecar_agent.py" >/dev/null
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" "$SIDE" 'bash -s' <<'REMOTE'
set -euo pipefail
install -m 0755 /tmp/sidecar_agent.py /usr/local/bin/leikwan-sidecar-agent.py
python3 -m py_compile /usr/local/bin/leikwan-sidecar-agent.py
python3 - <<'PY'
import importlib.util, pathlib, hashlib
p=pathlib.Path('/usr/local/bin/leikwan-sidecar-agent.py')
spec=importlib.util.spec_from_file_location('sa',str(p)); sa=importlib.util.module_from_spec(spec); spec.loader.exec_module(sa)
print('sidecar_agent_version', sa.AGENT_VERSION)
print('sha', hashlib.sha256(p.read_bytes()).hexdigest(), p.stat().st_size)
PY
systemctl restart leikwan-bond-sidecar-5-agent.service || systemctl restart leikwan-sidecar-agent.service || true
sleep 2
systemctl is-active leikwan-bond-sidecar-5-agent.service 2>/dev/null || systemctl is-active leikwan-sidecar-agent.service 2>/dev/null || true
REMOTE
# wait for one heartbeat/pull and show selected evidence
sleep 35
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
echo '=== client after sync ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
grep -m1 AGENT_VERSION /usr/local/bin/leikwan-client-agent.py
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py 2>/dev/null || true
ss -ltnp | egrep ':(11035|14969|8898|8899|2053|5201)\b' || true
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
python3 - <<'PY'
import json, pathlib
for p in ['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/applied-forwards.json']:
 print('---', p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  print(json.dumps(data if 'mptcp-forward-47' in p else data.get('47', data), ensure_ascii=False, indent=2)[:7000])
 except Exception as e: print(type(e).__name__, e)
PY
echo '=== journals ==='
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 160 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|mptcp|restart|failed|error|selected|private|direct|probe' | tail -120 || true
REMOTE
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" "$SIDE" 'bash -s' <<'REMOTE'
set +e
echo '=== sidecar after sync ==='
systemctl is-active leikwan-bond-sidecar-5-agent.service leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service 2>/dev/null || true
grep -m1 AGENT_VERSION /usr/local/bin/leikwan-sidecar-agent.py
sha256sum /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null || true
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ls -l /var/lib/leikwan-sidecar-5/wireguard/self-leg-clients /var/lib/leikwan-sidecar/wireguard/self-leg-clients 2>/dev/null || true
sed -n '1,80p' /etc/wireguard/lw-self-47.conf 2>/dev/null || true
ss -ltnp | egrep ':(14969|24969|8899|8898|2053|5201|5202)\b' || true
journalctl -u leikwan-bond-sidecar-5-agent.service -n 120 --no-pager -o short-iso | egrep -i 'self|wg|wireguard|peer|lw-self|failed|error|restart' | tail -100 || true
REMOTE
