#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SLAVE=root@47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=root@185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
PROXY_SCRIPT=/tmp/codex_sidecar_proxy_$$.sh
cat > "$PROXY_SCRIPT" <<'PROXY'
#!/usr/bin/env bash
exec sshpass -p 'U3I+sVL^%IXyqMX0' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -W "$1:$2" root@47.116.104.15
PROXY
chmod 700 "$PROXY_SCRIPT"
trap 'rm -f "$PROXY_SCRIPT"' EXIT
# sync sidecar code through slave hop
sshpass -p "$SIDEPASS" scp "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY_SCRIPT %h %p" sidecar-agent/sidecar_agent.py "$SIDE:/tmp/sidecar_agent.py" >/dev/null
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY_SCRIPT %h %p" "$SIDE" 'bash -s' <<'REMOTE'
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
sleep 3
systemctl is-active leikwan-bond-sidecar-5-agent.service 2>/dev/null || systemctl is-active leikwan-sidecar-agent.service 2>/dev/null || true
REMOTE
# force one client sync/apply now that sidecar code is installed
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl restart leikwan-client-agent.service || true
sleep 8
python3 - <<'PY'
import importlib.util, pathlib
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(pathlib.Path('/etc/leikwan/client-agent.json'))
agent.sync_once()
print('sync_once ok')
PY
REMOTE
sleep 15
# state dumps
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
echo '=== client state ==='
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py 2>/dev/null || true
python3 - <<'PY'
import json, pathlib
for p in ['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/applied.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  print(json.dumps(data if p.endswith('mptcp-forward-47.json') else data.get('47', data), ensure_ascii=False, indent=2)[:12000])
 except Exception as e: print(type(e).__name__, e)
PY
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ping -c 3 -W 1 10.204.53.42 2>/dev/null || true
ss -ltnp | egrep ':(11035|8898|8899|2053|5201|14969)\b' || true
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 180 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|private|mptcp|restart|failed|error|selected|bootstrap|probe|sync_once' | tail -140 || true
REMOTE
sshpass -p "$SIDEPASS" ssh -T "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY_SCRIPT %h %p" "$SIDE" 'bash -s' <<'REMOTE'
set +e
echo '=== sidecar state ==='
systemctl is-active leikwan-bond-sidecar-5-agent.service leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service 2>/dev/null || true
sha256sum /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null || true
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ping -c 3 -W 1 10.204.53.41 2>/dev/null || true
ls -l /var/lib/leikwan-sidecar-5/wireguard/self-leg-clients /var/lib/leikwan-sidecar/wireguard/self-leg-clients 2>/dev/null || true
sed -n '1,100p' /etc/wireguard/lw-self-47.conf 2>/dev/null || true
ss -ltnp | egrep ':(14969|24969|8899|8898|2053|5201|5202)\b' || true
journalctl -u leikwan-bond-sidecar-5-agent.service -n 160 --no-pager -o short-iso | egrep -i 'self|wg|wireguard|peer|lw-self|failed|error|restart' | tail -120 || true
REMOTE
