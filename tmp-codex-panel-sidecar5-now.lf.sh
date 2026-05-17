#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && set +e
 echo "=== PANEL $(hostname) $(date -Is) ==="
 echo "--- live file markers ---"
 grep -n "managed_iperf3_ports\|sidecarAgentUpdatesEnabled\|cf_runtime\|TEMP_DISABLE_MPTCP_RUNTIME" master/src/Controllers/SidecarAgentController.php | head -80
 grep -n "AGENT_VERSION" sidecar-agent/sidecar_agent.py | head -3
 sha256sum master/src/Controllers/SidecarAgentController.php sidecar-agent/sidecar_agent.py artifacts/versions.json
 echo "--- sidecar point 5 ---"
 php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT id,name,sidecar_host,ssh_port,bond_listen_port,mptcp_listen_port,cf_listen_port,agent_version,last_heartbeat_at,last_status,last_error,agent_config_json,agent_update_request_token,agent_update_requested_at FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n"; $rs=$pdo->query("SELECT id,path_prefix,target_host,target_port,bond_remote_host,bond_remote_port,backend_mode,transport_mode,enabled FROM forwards WHERE id IN (47,59) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); echo "--forwards--\n"; echo json_encode($rs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";'\''
'