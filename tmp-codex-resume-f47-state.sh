set -euo pipefail
printf '=== host/date ===\n'; hostname; date -Is; uptime || true
printf '\n=== agent/client services ===\n'
systemctl --no-pager --full status leikwan-client-agent.service leikwan-client-47.service 2>/dev/null | sed -n '1,80p' || true
printf '\n=== active/listeners/processes ===\n'
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
ss -ltnp | egrep '(:11035|:39559|:8898|:8899|iperf|wstunnel|leikwan)' || true
ps -eo pid,ppid,etimes,cmd | egrep 'leikwan|wstunnel|iperf3' | grep -v egrep || true
printf '\n=== forward 47 json summary ===\n'
python3 - <<'PY'
import json, os
for p in ['/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/forward-47.json','/var/lib/leikwan-client-agent/forward-47.json']:
    print('---',p, 'exists=', os.path.exists(p))
    if os.path.exists(p):
        try:
            d=json.load(open(p))
            for k in ['listen_host','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','bond_endpoints','tcp_endpoints']:
                if k in d: print(k, d[k])
        except Exception as e: print('ERR',e)
PY
printf '\n=== rendered start script interesting lines ===\n'
if [ -f /usr/local/bin/leikwan-client-start-47.sh ]; then sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh | egrep -n 'wstunnel|2053|8899|8898|127\.0\.0\.1|162\.159|104\.16|nrt1|RUST_LOG|relay' || true; fi
printf '\n=== self-leg cache ===\n'
for p in /var/lib/leikwan-client-agent/self-leg* /var/lib/leikwan-client-agent/*self* /etc/leikwan/*self*; do [ -e "$p" ] && { echo '---' $p; head -c 2000 "$p"; echo; }; done
printf '\n=== recent client47 journal ===\n'
journalctl -u leikwan-client-47.service -n 80 --no-pager -o short-iso 2>/dev/null || true
printf '\n=== recent agent journal ===\n'
journalctl -u leikwan-client-agent.service -n 50 --no-pager -o short-iso 2>/dev/null || true
