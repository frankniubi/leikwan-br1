#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is) onegood=$(systemctl is-active leikwan-client-47-onegood.service)"
cat >/tmp/codex-ssh-rtt47.py <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(120):
    t=time.time(); data=b''
    try:
        s=socket.create_connection(('127.0.0.1',11035),timeout=2.0)
        s.settimeout(3.0)
        data=s.recv(96)
        s.close()
        if data.startswith(b'SSH-'):
            vals.append((time.time()-t)*1000)
        else:
            errs.append(('bad',data[:20].decode('latin1','replace')))
    except Exception as e:
        errs.append((type(e).__name__,str(e)[:80]))
    time.sleep(0.05)
print('RTT120 ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'p50',round(statistics.median(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals_first20',[round(v,1) for v in vals[:20]],'errs',errs[:8])
PY
python3 /tmp/codex-ssh-rtt47.py
# Light data-plane test to target SSH, not iperf, to avoid target busy mismatch.
python3 - <<'PY'
import socket,time
s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(3)
print('banner',s.recv(80).decode('latin1','replace').strip())
s.close()
PY
echo '-- recent logs key --'
journalctl -u leikwan-client-47-onegood.service --since '-5 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|no bond legs|recv_reset|local_read_failed|local_write_failed|entering cooldown|WARN|ERROR' | tail -160 || true
REMOTE
