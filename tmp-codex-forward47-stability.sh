#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== settle ===";
  sleep 15;
  date;
  echo "=== service/listener ===";
  systemctl is-active leikwan-client-agent.service || true;
  systemctl is-active leikwan-client-47.service || true;
  ss -ltnp | grep 11035 || true;
  echo "=== raw local connect ===";
  python3 - <<"PY"
import socket, time
for i in range(3):
    try:
        s=socket.create_connection(("127.0.0.1",11035),timeout=2)
        print("connect_ok", i)
        s.close()
    except Exception as e:
        print("connect_fail", i, repr(e))
    time.sleep(1)
PY
  echo "=== iperf3 reverse ===";
  timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 50M -t 8 || true;
  echo "=== latest logs ===";
  journalctl -u leikwan-client-47.service -n 80 --no-pager || true;
'