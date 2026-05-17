set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest; do
  [ -n "$host" ] || continue
  echo "===== SHANGHAI $host ====="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set +e
    echo "time=$(date -Is) host=$(hostname)"
    echo "-- ping/tcp latency to sidecar ports --"
    ping -c 5 -W 1 185.220.238.143 2>/dev/null | awk "/packets transmitted|rtt|round-trip/ {print}" || true
    python3 - <<"PY"
import socket,time,statistics
host="185.220.238.143"
for port in [8899,8898,2053,22,5201]:
    vals=[]; errs=[]
    for i in range(6):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=2.0)
            s.close(); vals.append((time.time()-t)*1000)
        except Exception as e:
            errs.append(type(e).__name__+":"+str(e)[:60])
        time.sleep(0.08)
    if vals:
        print(f"tcp {host}:{port} min={min(vals):.1f} avg={statistics.mean(vals):.1f} vals="+",".join(f"{v:.1f}" for v in vals))
    else:
        print(f"tcp {host}:{port} fail {errs[:2]}")
PY
    echo "-- local CF backend 316 health --"
    systemctl is-active wstunnel-svc-2-316.service leikwan-tcp-mux-7000.service 2>/dev/null || true
    ss -ltnp | grep -E ":(7000|7013)" || true
    timeout 4 bash -lc "</dev/tcp/127.0.0.1/7013" >/dev/null 2>&1 && echo local7013_open || echo local7013_fail
    echo "-- wstunnel316 recent --"
    journalctl -u wstunnel-svc-2-316.service --since "-10 min" --no-pager -o short-iso 2>/dev/null | grep -E "Opening TCP|Invalid status|error|WARN|INFO|520|handshake" | tail -80 || true
  ' 2>&1 | sed -e 's/\r$//'
  echo
done < shanghais.txt