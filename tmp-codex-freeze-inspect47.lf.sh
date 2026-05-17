#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== freeze client agent ==="
systemctl disable --now leikwan-client-agent.service 2>&1 || true
systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
systemctl is-active leikwan-client-agent.service 2>/dev/null || true
printf '\n=== service active ===\n'
systemctl is-active leikwan-client-47.service 2>/dev/null || true
systemctl status leikwan-client-47.service --no-pager -l 2>/dev/null | sed -n '1,90p' || true
printf '\n=== version/hash ===\n'
grep -n 'AGENT_VERSION' /usr/local/bin/leikwan-client-agent.py 2>/dev/null | head -3 || true
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json /var/lib/leikwan-client-agent/leikwan-mptcp-relay 2>/dev/null || true
printf '\n=== runtime summary ===\n'
python3 - <<'PY'
import json, pathlib
for p in ['/etc/leikwan/mptcp-forward-47.json','/run/leikwan/bond-leg-health-forward-47.json','/var/lib/leikwan-client-agent/bond-endpoint-health.json','/var/lib/leikwan-client-agent/self-leg-best-ip-cache.json','/var/lib/leikwan-client-agent/applied-forwards.json']:
    print('---',p)
    path=pathlib.Path(p)
    if not path.exists(): print('MISSING'); continue
    try:
        d=json.loads(path.read_text())
        if p.endswith('mptcp-forward-47.json'):
            keys=['forward_id','listen_host','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','mptcp_endpoints','underlays','endpoints','policy_mode','transport']
            print(json.dumps({k:d.get(k) for k in keys}, indent=2, ensure_ascii=False)[:16000])
        elif p.endswith('bond-endpoint-health.json'):
            print(json.dumps(d.get('47',d), indent=2, ensure_ascii=False)[:14000])
        else:
            print(json.dumps(d, indent=2, ensure_ascii=False)[:14000])
    except Exception as e:
        print('ERR',repr(e)); print(path.read_text(errors='ignore')[:3000])
print('--- script important')
s=pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh')
if s.exists():
    for line in s.read_text(errors='ignore').splitlines():
        if any(x in line for x in ['wstunnel client','leikwan-ingress','leikwan-bond','leikwan-mptcp','wss://','47.116','162.159','104.16','nrt1','RUST_LOG']): print(line)
PY
printf '\n=== listeners/processes ===\n'
ss -ltnp | grep -E ':(11035|39559|21739|21740|291[0-9]+|7000|2053|8898|8899)' || true
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-start-47|leikwan-mptcp-relay client --config /etc/leikwan/mptcp-forward-47|wstunnel client -L tcp://127.0.0.1:|leikwan-bond client|leikwan-ingress client|iperf3' | grep -v grep || true
printf '\n=== recent f47 logs ===\n'
journalctl -u leikwan-client-47.service --since '-30 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|ERROR|WARN|failed|error|accepted|listening' | tail -220 || true
REMOTE
