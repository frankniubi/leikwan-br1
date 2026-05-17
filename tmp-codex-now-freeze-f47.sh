#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank20021212.'
ssh_opts=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8)
sshpass -e ssh "${ssh_opts[@]}" root@114.80.33.50 'bash -s' <<'REMOTE'
set -euo pipefail
printf '=== freeze client-agent ===\n'
date -Is
(systemctl disable --now leikwan-client-agent.service || systemctl disable --now leikwan-client-agent || true) 2>&1
printf '\n=== client-agent status ===\n'
systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
systemctl is-active leikwan-client-agent.service 2>/dev/null || true
systemctl status leikwan-client-agent.service --no-pager -l | sed -n '1,80p' || true
printf '\n=== forward47 status ===\n'
systemctl is-active leikwan-client-47.service 2>/dev/null || true
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,90p' || true
printf '\n=== current listener/process ===\n'
ss -ltnp '( sport = :11035 or sport = :7000 or sport = :8899 )' || true
ps -ef | egrep 'leikwan-(bond|client-start-47)|wstunnel|iperf3' | grep -v grep || true
printf '\n=== rendered runtime ===\n'
ls -l /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json 2>/dev/null || true
sha256sum /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json 2>/dev/null || true
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
printf '\n=== mptcp json summary ===\n'
python3 - <<'PY' 2>/dev/null || true
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
if p.exists():
    j=json.load(open(p))
    keys=['listen_host','listen_port','target_host','target_port','bond_mode','remote_host','remote_port','direct_legs','endpoints']
    for k in keys:
        v=j.get(k)
        if k in ('direct_legs','endpoints') and isinstance(v,list):
            print(k, 'len', len(v))
            for x in v[:10]:
                print(' ', {kk:x.get(kk) for kk in ('endpoint_id','candidate_kind','display_host','public_host','public_port','remote_host','remote_port','path_prefix','dial_connect_ip','cf_sni_host') if kk in x})
        else:
            print(k, v)
PY
printf '\n=== health/cache files ===\n'
for f in /var/lib/leikwan-client-agent/bond-endpoint-health.json /var/lib/leikwan-client-agent/latency-primary-state.json /var/lib/leikwan-client-agent/self-leg-best-ip-cache.json /run/leikwan/bond-leg-health-forward-47.json; do
  echo "--- $f"; test -e "$f" && (ls -l "$f"; cat "$f") || echo missing
done
printf '\n=== recent forward47 journal key lines ===\n'
journalctl -u leikwan-client-47.service --since '25 min ago' --no-pager -o short-iso | egrep -i 'invalid bond frame magic|failed to read bond frame|hello|local_read_failed|local_write_failed|stream_open|error|warn|520|websocket|transport .*established|failed' | tail -120 || true
REMOTE
