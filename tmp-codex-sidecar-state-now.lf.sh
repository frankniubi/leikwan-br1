#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_sidecar_state.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach($pdo->query("SELECT id,name,sidecar_host,bind_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,last_heartbeat_at,last_status,last_error,agent_version,agent_config_json FROM sidecar_points ORDER BY id") as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";}
echo "-- forwards --\n";
foreach($pdo->query("SELECT id, service_id, client_mode, backend_mode, transport_mode, listen_port, target_host,target_port,bond_remote_host,bond_remote_port,bond_sidecar_point_id,path_prefix,enabled FROM forwards WHERE id IN (47,59) ORDER BY id") as $r){echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";}
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar_state.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar_state.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar_state.php && rm -f codex_sidecar_state.php'