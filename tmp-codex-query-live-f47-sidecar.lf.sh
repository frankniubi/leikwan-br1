#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
sshpass -e ssh -T \
  -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 \
  root@10.0.10.196 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c = new Config(__DIR__ . '/master/.env');
$db = new Database($c);
function out($label, $rows) { echo "--- {$label}\n"; foreach ($rows as $row) { echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n"; } }
out('forward47', $db->fetchAll("SELECT f.id AS forward_id, f.client_mode, f.transport_mode, f.line_client_id AS client_id, f.listen_port, f.target_host, f.target_port, f.backend_mode, f.forward_combination_id, f.bond_sidecar_point_id, f.bond_remote_host, f.bond_remote_port, f.path_prefix, f.cdn_host, sp.id AS point_id, sp.name AS point_name, sp.sidecar_host, sp.agent_version, sp.agent_config_json, sp.listen_port AS point_listen, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.last_heartbeat_at, sp.last_status FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47"));
out('client3', $db->fetchAll("SELECT id,name,display_host,agent_version,agent_config_json,last_sync_at,agent_update_request_token FROM clients WHERE id=3"));
out('sessions3', $db->fetchAll("SELECT client_id,device_id,device_name,platform,user_agent,last_ip,last_seen_at,last_pull_at,last_heartbeat_at FROM client_device_sessions WHERE client_id=3 ORDER BY last_seen_at DESC LIMIT 5"));
out('shanghai_nodes', $db->fetchAll("SELECT id,name,region,public_ip,private_ip,agent_version,agent_config_json,status,last_heartbeat_at FROM nodes WHERE public_ip IN ('47.116.11.144','47.116.134.3','47.116.104.15','8.133.170.123','101.133.142.65') OR private_ip IN ('47.116.11.144','47.116.134.3','47.116.104.15','8.133.170.123','101.133.142.65') ORDER BY id"));
out('sidecar_points', $db->fetchAll("SELECT id,name,sidecar_host,agent_version,agent_config_json,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,is_active,last_heartbeat_at,last_status,last_error FROM sidecar_points ORDER BY id"));
PHP
REMOTE