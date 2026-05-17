#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank20021212.'
ssh_opts=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8)
sshpass -e ssh "${ssh_opts[@]}" root@114.80.33.50 'bash -s' <<'REMOTE'
set -euo pipefail
printf 'date='; date -Is
printf '\n=== system services ===\n'
for s in leikwan-client-agent.service leikwan-client-47.service leikwan-client-59.service; do echo "--- $s"; systemctl is-enabled "$s" 2>/dev/null || true; systemctl is-active "$s" 2>/dev/null || true; systemctl show "$s" -p MainPID -p ExecStart -p ActiveState -p SubState --no-pager 2>/dev/null || true; done
printf '\n=== key sockets ===\n'
ss -ltnp | egrep ':(11035|22740|5201|7000|8898|8899)\b' || true
printf '\n=== all f47-ish process tree ===\n'
ps -eo pid,ppid,lstart,cmd | egrep 'leikwan-client-start-47|mptcp-forward-47|forward_id=47|127\.0\.0\.1:22740|leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035|wstunnel.*07289d1f5ed25399|5201' | grep -v egrep || true
printf '\n=== decode active manual token if any ===\n'
python3 - <<'PY'
import base64,json,subprocess,re
ps=subprocess.check_output(['ps','-eo','pid,args'], text=True, errors='ignore')
for line in ps.splitlines():
    if 'leikwan-bond client' in line and ':11035' in line:
        print(line[:500])
        m=re.search(r'--session-token\s+(\S+)', line)
        if m:
            tok=m.group(1).split('.')[0]
            tok += '='*((4-len(tok)%4)%4)
            print(json.dumps(json.loads(base64.urlsafe_b64decode(tok)), indent=2))
PY
printf '\n=== quick iperf through current 11035 -R -b 300M 8 sec ===\n'
timeout 15 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 2>&1 || true
printf '\n=== journal around manual pid? recent key ===\n'
journalctl --since '15 min ago' --no-pager -o short-iso | egrep -i 'leikwan-bond|invalid bond frame magic|failed to read bond frame|local_read_failed|connection reset|client connection error|520|wstunnel' | tail -180 || true
REMOTE
