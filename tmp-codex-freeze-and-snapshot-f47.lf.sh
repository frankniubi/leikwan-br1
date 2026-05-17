#!/usr/bin/env bash
set -u
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== freeze client agent ==="
date -Is; hostname
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl is-enabled leikwan-client-agent.service 2>&1 || true
systemctl is-active leikwan-client-agent.service 2>&1 || true
echo "=== forward service state ==="
systemctl is-active leikwan-client-47.service 2>&1 || true
systemctl --no-pager -l status leikwan-client-47.service | sed -n '1,80p' || true
echo "=== version/hash ==="
grep -n "AGENT_VERSION" /usr/local/bin/leikwan-client-agent.py 2>/dev/null | head -3 || true
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-bond /var/lib/leikwan-client-agent/leikwan-mptcp-relay 2>/dev/null || true
echo "=== listeners/processes ==="
ss -ltnp | grep -E ':(11035|7000|7001|7002|2173[0-9]|22740|39559|4327[0-9]|4328[0-9]|5201|8898|8899|2053)' || true
ps -ef | grep -E 'leikwan-client-start-47|leikwan-bond client|leikwan-mptcp-relay client|wstunnel client -L tcp://127.0.0.1:|leikwan-ingress client' | grep -v grep || true
echo "=== runtime json/script summary ==="
python3 - <<'PY'
import json,pathlib,re
paths=['/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/bond-forward-47.json','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/applied_forwards.json','/var/lib/leikwan-client-agent/applied-forwards.json','/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json','/var/lib/leikwan-client-agent/self-leg-best-ip-cache.json','/run/leikwan/bond-leg-health-forward-47.json']
for p in paths:
    path=pathlib.Path(p); print('---',p)
    if not path.exists(): print('MISSING'); continue
    txt=path.read_text(errors='ignore')
    try:
        data=json.loads(txt)
        if p.endswith('mptcp-forward-47.json') or p.endswith('bond-forward-47.json'):
            keys=['forward_id','listen_port','transport','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','endpoints','underlays','mptcp_endpoints','policy_mode','subflow_limit','target_host','target_port']
            print(json.dumps({k:data.get(k) for k in keys if k in data},indent=2,ensure_ascii=False)[:12000])
        else:
            print(json.dumps(data,indent=2,ensure_ascii=False)[:10000])
    except Exception as e:
        print('ERR',repr(e)); print(txt[:3000])
print('--- start script selected lines')
sp=pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh')
if sp.exists():
    for line in sp.read_text(errors='ignore').splitlines():
        if any(s in line for s in ['wstunnel client','wss://','leikwan-bond','leikwan-mptcp-relay','leikwan-ingress','--legs','127.0.0.1:8899','127.0.0.1:8898','http-upgrade-path-prefix','tls-sni']):
            print(line)
PY
echo "=== recent important logs ==="
journalctl -u leikwan-client-47.service --since '-30 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|accepted downstream|local_write_failed|recv_reset|Connection refused|ERROR|WARN|failed|started|Stopped' | tail -240 || true
REMOTE
