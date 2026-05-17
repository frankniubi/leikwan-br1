#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/f47_probe_runtime.py <<'PY'
import socket, time, json, pathlib, subprocess
print('listener_pre', subprocess.run(['ss','-ltnp'], text=True, capture_output=True).stdout)
for i in range(3):
    try:
        s = socket.create_connection(('127.0.0.1', 11035), timeout=2)
        s.settimeout(2)
        s.sendall(b'A'*303)
        time.sleep(1)
        try:
            data = s.recv(1)
            print('recv', i, data)
        except Exception as e:
            print('recv_exc', i, repr(e))
        s.close()
    except Exception as e:
        print('connect_exc', i, repr(e))
    time.sleep(1)
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/f47_probe_runtime.py root@114.80.33.50:/tmp/f47_probe_runtime.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/f47_probe_runtime.py; echo ===; journalctl -u leikwan-client-47.service -n 40 --no-pager || true'