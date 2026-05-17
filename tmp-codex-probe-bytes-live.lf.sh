#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
CLIENT='114.80.33.50'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== time ==="; date -Is
echo "=== service states ==="; systemctl is-active leikwan-client-47.service leikwan-client-59.service leikwan-client-agent.service 2>/dev/null || true
echo "=== local first bytes from f47/f59 legs ==="
python3 - <<'PY'
import socket,time
for port in [21739,21740,22183,22184,22185,22186,22187,22188,22189,22383,22384]:
    t=time.time(); data=b''; err=''
    try:
        s=socket.create_connection(('127.0.0.1',port), timeout=2)
        s.settimeout(2)
        try:
            s.sendall(b'LKBP')
        except Exception as e:
            err += ' send='+repr(e)
        try:
            data=s.recv(32)
        except Exception as e:
            err += ' recv='+repr(e)
        s.close()
    except Exception as e:
        err = repr(e)
    print(port, 'ms', round((time.time()-t)*1000,1), 'len', len(data), 'hex', data.hex(), 'ascii', repr(data[:32]), 'err', err)
PY

echo "=== f47 files ==="
for p in /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /var/lib/leikwan-client-agent/bond-endpoint-health.json /run/leikwan/bond-leg-health-forward-47.json; do
  echo "--- $p"; [ -e "$p" ] && sed -n '1,220p' "$p" || true
done
REMOTE
