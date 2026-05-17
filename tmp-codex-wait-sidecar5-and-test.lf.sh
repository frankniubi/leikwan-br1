#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
echo '=== wait 75s for sidecar update heartbeat ==='
sleep 75
echo '=== panel sidecar point 5 after update wait ==='
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_config_json,agent_update_request_token,agent_update_requested_at FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";'\'' '
echo '=== client probes after sidecar update ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
 echo date=$(date -Is)
 echo --direct sidecar 5201 tcp connect--; timeout 5 bash -lc "</dev/tcp/185.220.238.143/5201"; echo tcp_rc=$?
 echo --direct sidecar iperf--; timeout 12 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --connect-timeout 3000; echo direct_rc=$?
 echo --restart f47--; systemctl restart leikwan-client-47.service; sleep 3; systemctl is-active leikwan-client-47.service; ss -ltnp | grep -E ":(11035|21739|5201)" || true
 echo --wst local leg--; timeout 10 iperf3 -c 127.0.0.1 -p 21739 -R -b 300M -t 5 --connect-timeout 3000; echo wst_rc=$?
 echo --forward47--; timeout 15 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000; echo f47_rc=$?
 echo --logs--; journalctl -u leikwan-client-47.service --since "-90 sec" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|session established|accepted downstream|Opening TCP connection|Invalid status code|520|WARN|ERROR|failed|Connection refused|recv_reset" | tail -240 || true
 echo --snapshot--; cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'