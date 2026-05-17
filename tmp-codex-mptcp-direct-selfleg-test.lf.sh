set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service leikwan-client-47-onegood.service >/dev/null 2>&1 || true
pkill -f 'wstunnel client .*07289d1f5ed25399.*nrt1\.nbwly\.com' 2>/dev/null || true
cat >/tmp/codex_mptcp_direct_selfleg_config.json <<'JSON'
{
  "forward_id": 47,
  "listen_port": 11035,
  "transport": "tcp",
  "remote_host": "127.0.0.1",
  "remote_port": 44047,
  "relay_host": "127.0.0.1",
  "relay_port": 8899,
  "tcp_session_token": "dummy",
  "udp_session_token": "dummy",
  "connect_timeout_ms": 3000,
  "relay_idle_timeout_seconds": 120,
  "tcp_congestion_control": "bbr",
  "socket_buffer_bytes": 8388608,
  "bond_mode": "",
  "direct_legs": [],
  "endpoints": [],
  "manage_mptcp_endpoints": false,
  "mptcp_endpoints": [],
  "underlay": {},
  "underlays": []
}
JSON
BASE=/tmp/codex-mptcp-direct-selfleg; rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; pkill -f '/tmp/codex_mptcp_direct_selfleg_config.json' 2>/dev/null || true; }
trap cleanup EXIT
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:44047:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix 07289d1f5ed25399 wss://162.159.39.6:2053 >"$BASE/wst.log" 2>&1 & echo $! >"$BASE/wst.pid"
for p in 44047; do for i in $(seq 1 80); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.2); s.close()
PY
 done; done
RUST_LOG=info /var/lib/leikwan-client-agent/leikwan-mptcp-relay client --config /tmp/codex_mptcp_direct_selfleg_config.json >"$BASE/relay.log" 2>&1 & echo $! >"$BASE/relay.pid"
for i in $(seq 1 80); do ss -ltn | grep -q ':11035 ' && break || sleep .1; done
sleep 1
echo "=== direct mptcp-relay selfleg ssh banner ==="
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(12):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:24].hex()))
 time.sleep(.1)
print('BANNER ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:4])
PY
echo "=== direct mptcp-relay selfleg iperf ==="
timeout 30 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/iperf.out" 2>&1 || true
cat "$BASE/iperf.out"
echo '-- relay key --'; grep -E 'accepted|connected|handshake|relay done|error|WARN|ERROR' "$BASE/relay.log" | tail -120 || true
echo '-- wst key --'; grep -E 'Invalid status|websocket handshake|Opening TCP|TLS handshake|Closing|WARN|ERROR' "$BASE/wst.log" | tail -120 || true
REMOTE