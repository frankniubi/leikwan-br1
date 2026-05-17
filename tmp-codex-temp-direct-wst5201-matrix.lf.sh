#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
ROUTE='07289d1f5ed25399'
SNI='nrt1.nbwly.com'
TMPPORT=7801
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
# host|pass|edge from current services
cat >/tmp/codex-temp-wst.tsv <<'EOF'
47.116.11.144|@XwDQ6H5mgMXbrGD|162.159.39.4
47.116.104.15|U3I+sVL^%IXyqMX0|162.159.39.4
101.133.142.65|b+z@Qk860a+6UsVW|162.159.39.4
47.116.134.3|RQRUbOkeh7ZpCkDa|162.159.39.6
8.133.170.123|TnJKTGse+JMJFR%t|162.159.39.7
EOF
while IFS='|' read -r host pass edge; do
  echo "=== setup temp direct WST $host edge=$edge ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" root@$host "EDGE=$edge ROUTE=$ROUTE SNI=$SNI P=$TMPPORT bash -s" <<'REMOTE'
set -euo pipefail
pkill -f "wstunnel client -L tcp://0.0.0.0:${P}:127.0.0.1:5201" 2>/dev/null || true
nohup /usr/local/bin/wstunnel client -L tcp://0.0.0.0:${P}:127.0.0.1:5201 --tls-sni-override "$SNI" --http-headers Host:" $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >/tmp/codex-temp-wst5201.log 2>&1 &
sleep 1
ss -ltnp | grep ":${P}" || true
REMOTE
  echo "--- test from client to slave $host:$TMPPORT (-R) ---"
  for attempt in 1 2 3; do
    set +e
    sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "HOST=$host P=$TMPPORT bash -s" <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
python3 - <<PY
import socket,time,statistics,os
host=os.environ['HOST']; p=int(os.environ['P']); vals=[]; errs=[]
for i in range(5):
 t=time.time()
 try:
  s=socket.create_connection((host,p),timeout=3); s.close(); vals.append((time.time()-t)*1000)
 except Exception as e: errs.append(type(e).__name__+':'+str(e))
 time.sleep(.1)
print('tcp_ms', [round(v,1) for v in vals], 'min', round(min(vals),1) if vals else None, 'errs', errs[:2])
PY
timeout 18 iperf3 -c "$HOST" -p "$P" -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-temp-wst5201-$HOST-R.out 2>&1
rc=$?; echo reverse_rc=$rc; cat /tmp/codex-temp-wst5201-$HOST-R.out
timeout 14 iperf3 -c "$HOST" -p "$P" -b 300M -t 5 --connect-timeout 3000 > /tmp/codex-temp-wst5201-$HOST-U.out 2>&1
rc=$?; echo upload_rc=$rc; cat /tmp/codex-temp-wst5201-$HOST-U.out
REMOTE
    rc=$?
    set -e
    [ $rc -eq 0 ] && break
    echo "client attempt $attempt failed rc=$rc" >&2; sleep $((attempt*5))
  done
  echo "--- cleanup $host ---"
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" root@$host "P=$TMPPORT bash -s" <<'REMOTE'
pkill -f "wstunnel client -L tcp://0.0.0.0:${P}:127.0.0.1:5201" 2>/dev/null || true
cat /tmp/codex-temp-wst5201.log 2>/dev/null | tail -20 || true
REMOTE
  echo
done < /tmp/codex-temp-wst.tsv
