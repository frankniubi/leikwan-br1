#!/usr/bin/env bash
set -euo pipefail
SLAVE='47.116.11.144'
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo === host ===; hostname; date -Is
echo === agent ===; systemctl is-active leikwan-slave-agent.service 2>/dev/null; journalctl -u leikwan-slave-agent.service --since '-30 min' --no-pager -n 80 2>/dev/null | tail -80
echo === listeners 7000/backend ===; ss -ltnp | grep -E ':(7000|2000[0-9]|2[0-9]{4}|7329|5214[0-9]|6214[0-9])' | head -80 || true
echo === units ===
for u in leikwan-tcp-mux-7000.service leikwan-wss-mux-7000.service wstunnel-svc-2-316.service wg-quick@lwf47n12.service; do echo ---$u; systemctl is-active $u 2>/dev/null; systemctl status $u --no-pager -l 2>/dev/null | sed -n '1,90p'; done
echo === files ===
for p in /var/lib/leikwan-slave-agent/listeners/routes-7000.json /var/lib/leikwan-slave-agent/services/wstunnel-svc-2-316.json /etc/systemd/system/wstunnel-svc-2-316.service /etc/systemd/system/leikwan-tcp-mux-7000.service /etc/wireguard/lwf47n12.conf; do
  echo ---$p; [ -e "$p" ] && { ls -l "$p"; sed -n '1,220p' "$p"; } || true
done
echo === probe local 7000 first response ===
python3 - <<'PY'
import socket,time
for payload in [b'', b'LKBP', b'GET /07289d1f5ed25399 HTTP/1.1\r\nHost: x\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n\r\n']:
  t=time.time(); data=b''; err=''
  try:
    s=socket.create_connection(('127.0.0.1',7000),timeout=2); s.settimeout(2)
    if payload: s.sendall(payload)
    try: data=s.recv(64)
    except Exception as e: err='recv='+repr(e)
    s.close()
  except Exception as e: err=repr(e)
  print('payload',repr(payload[:8]),'ms',round((time.time()-t)*1000,1),'len',len(data),'hex',data.hex(),'ascii',repr(data),'err',err)
PY
REMOTE
