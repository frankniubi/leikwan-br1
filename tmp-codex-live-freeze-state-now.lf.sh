set -euo pipefail
CPASS='Frank20021212.'
CLIENT='root@114.80.33.50'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
printf 'date=%s host=%s\n' "$(date -Is)" "$(hostname)"
printf 'agent_active='; systemctl is-active leikwan-client-agent.service 2>/dev/null || true
printf 'agent_enabled='; systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
printf 'f47_active='; systemctl is-active leikwan-client-47.service 2>/dev/null || true
printf 'onegood_active='; systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true
printf '\n-- listeners --\n'; ss -ltnp | grep -E '(:11035|:39559|:22740|:5201)' || true
printf '\n-- f47 config summary --\n'
python3 - <<'PY'
import json,pathlib,hashlib,os
for p in ['/usr/local/bin/leikwan-client-start-47.sh','/etc/leikwan/mptcp-forward-47.json','/etc/leikwan/bond-forward-47.json','/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json']:
    q=pathlib.Path(p)
    print('PATH',p,'exists',q.exists(), 'sha', hashlib.sha256(q.read_bytes()).hexdigest() if q.exists() and q.is_file() else '')
    if p.endswith('.json') and q.exists():
        try:
            d=json.loads(q.read_text())
            if 'direct_legs' in d:
                print(json.dumps({k:d.get(k) for k in ['forward_id','listen_host','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','legs']}, ensure_ascii=False, indent=2)[:3000])
            else:
                print(json.dumps(d, ensure_ascii=False, indent=2)[:3000])
        except Exception as e: print('json_error',e)
    elif q.exists() and q.is_file():
        txt=q.read_text(errors='ignore')
        print(txt[:2500])
PY
printf '\n-- process --\n'; ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-bond|leikwan-ingress|wstunnel|iperf3' | grep -v grep || true
printf '\n-- recent f47 logs --\n'; journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|accepted downstream|local_write_failed|local_read_failed|recv_reset|close_timeout|WARN|ERROR|failed|started|Stopped' | tail -260 || true
REMOTE
