#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o LogLevel=ERROR)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is)"
echo "services agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|21739)\b' || true
echo "--- TCP connect latency to local forward 11035 ---"
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
    t=time.time()
    try:
        s=socket.create_connection(("127.0.0.1",11035),timeout=2)
        vals.append((time.time()-t)*1000)
        s.close()
    except Exception as e:
        errs.append(type(e).__name__+":"+str(e))
    time.sleep(0.08)
print("connect_ms", "min", round(min(vals),1) if vals else None, "avg", round(statistics.mean(vals),1) if vals else None, "p50", round(statistics.median(vals),1) if vals else None, "vals", [round(v,1) for v in vals], "errs", errs[:5])
PY
echo "--- App/banner latency through forward 47 target ---"
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(12):
    t=time.time()
    try:
        s=socket.create_connection(("127.0.0.1",11035),timeout=3)
        s.settimeout(3)
        data=s.recv(64)
        dt=(time.time()-t)*1000
        if data:
            vals.append(dt)
            errs.append((round(dt,1),data[:24].hex()))
        else:
            errs.append((round(dt,1),'empty'))
        s.close()
    except Exception as e:
        errs.append((round((time.time()-t)*1000,1),type(e).__name__+":"+str(e)))
    time.sleep(0.15)
print("first_byte_ms", "min", round(min(vals),1) if vals else None, "avg", round(statistics.mean(vals),1) if vals else None, "p50", round(statistics.median(vals),1) if vals else None, "vals", [round(v,1) for v in vals], "samples", errs[:8])
PY
echo "--- CF edge TCP latency from client to selected self-leg edge ---"
python3 - <<'PY'
import socket,time,statistics
host='162.159.39.4'; port=2053
vals=[]; errs=[]
for i in range(20):
    t=time.time()
    try:
        s=socket.create_connection((host,port),timeout=2)
        vals.append((time.time()-t)*1000); s.close()
    except Exception as e: errs.append(type(e).__name__+":"+str(e))
    time.sleep(0.05)
print("edge_tcp_ms", "min", round(min(vals),1) if vals else None, "avg", round(statistics.mean(vals),1) if vals else None, "p50", round(statistics.median(vals),1) if vals else None, "vals", [round(v,1) for v in vals], "errs", errs[:5])
PY
echo "--- bond selected/health snapshot ---"
cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null | python3 -m json.tool 2>/dev/null | sed -n '1,180p' || true
echo "--- recent key logs ---"
journalctl -u leikwan-client-47.service --since '-5 min' --no-pager -o short-iso | grep -E 'session established|Invalid status code|failed to read bond frame header|recv_stream_open_ack|recv_fin|local_read_failed|cooldown|WARN|ERROR' | tail -80 || true
REMOTE