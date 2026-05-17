set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client onegood target 22 vs 5201 quick ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) services agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
echo 'script token target line:'; grep -n 'target_port' /usr/local/bin/leikwan-client-start-47-onegood.sh || true
echo '-- recent resets target --'; journalctl -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso | grep -E 'failed to connect upstream target|recv_reset|stream_open|upstream_connected|local_read_failed|control_socket_closed|Invalid status code|520|400' | tail -120 || true
REMOTE
echo '=== slave317 exact routes and live wst logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is)"
echo '-- routes file --'; cat /var/lib/leikwan-slave/listeners/routes-7000.json 2>/dev/null | python3 -m json.tool | sed -n '1,260p' || cat /var/lib/leikwan-slave/listeners/routes-7000.json 2>/dev/null || true
echo '-- service json --'; cat /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null | python3 -m json.tool | sed -n '1,220p' || true
echo '-- unit --'; systemctl cat wstunnel-svc-2-317.service | sed -n '1,120p'
echo '-- recent full log --'; journalctl -u wstunnel-svc-2-317.service --since '-20 min' --no-pager -o short-iso | grep -E '07289|remote="127.0.0.1:(22|5201|5202|8899)"|Invalid status code|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' | tail -200 || true
REMOTE