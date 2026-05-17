#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
# Ensure endpoint 317 wstunnel edge 39.4 and agent disabled to avoid overwrite.
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true; systemctl is-active leikwan-slave-agent.service; systemctl is-active wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service' || true
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== 5201 server setup $(date -Is) ==="
# Start local iperf target on client so sidecar->client reverse has real target through bond to 127.0.0.1:5201.
pkill -f 'iperf3 -s -p 5201' 2>/dev/null || true
nohup iperf3 -s -p 5201 --one-off > /tmp/codex-iperf3-5201-server.log 2>&1 &
sleep .2
ss -ltnp | grep ':5201' || true
echo "=== onegood iperf upload -b 300M t=12 ==="
timeout 30 iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 12 --connect-timeout 3000 > /tmp/codex-onegood47-upload-now.out 2>&1
rc=$?
echo iperf_upload_rc=$rc
cat /tmp/codex-onegood47-upload-now.out
echo "=== logs after upload ==="
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | grep -E 'session established|recv_reset|local_read_failed|local_write_failed|failed to connect upstream|entering cooldown|no active|WARN|ERROR' | tail -120 || true
echo "=== server log ==="; cat /tmp/codex-iperf3-5201-server.log || true
REMOTE
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'echo "=== slave317 logs after upload ==="; journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-2 min" --no-pager -o short-iso | grep -E "Opening TCP|Closing|Invalid status|plain tcp mux open|failed|ERROR|WARN|8899|07289" | tail -160 || true'