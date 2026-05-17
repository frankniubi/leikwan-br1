<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$r=$pdo->query("SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,agent_version,last_heartbeat_at,last_status,last_error,agent_config_json,agent_update_request_token,agent_update_requested_at FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
echo "--cf forwards--\n";
foreach($pdo->query("SELECT id,listen_port,target_host,target_port,bond_remote_host,bond_remote_port,backend_mode,client_mode,transport_mode,path_prefix,enabled FROM forwards WHERE bond_sidecar_point_id=5 AND backend_mode='cf_mode' ORDER BY id") as $row){ echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }
