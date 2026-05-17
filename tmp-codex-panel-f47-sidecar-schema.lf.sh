#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_panel_f47_sidecar_query.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function cols($pdo,$table){ $rs=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); return array_map(fn($r)=>$r['Field'],$rs); }
foreach(['sidecar_points','forwards','forward_node_assignments','nodes'] as $t){ echo "-- COLUMNS $t --\n"; echo implode(',', cols($pdo,$t)),"\n"; }
$sideCols=cols($pdo,'sidecar_points');
$want=['id','name','region','sidecar_host','host','ssh_host','ssh_port','bond_listen_port','mptcp_listen_port','cf_listen_port','agent_version','last_heartbeat_at','last_status','last_error','agent_config_json','agent_token','agent_update_request_token','agent_update_requested_at'];
$select=array_values(array_intersect($want,$sideCols));
if($select){
  $sql='SELECT '.implode(',',array_map(fn($c)=>"`$c`",$select)).' FROM sidecar_points WHERE id=5';
  echo "-- SIDECAR 5 --\n"; echo json_encode($pdo->query($sql)->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
$fcols=cols($pdo,'forwards');
$fwant=['id','client_id','sidecar_point_id','forward_combination_id','path_prefix','target_host','target_port','backend_mode','transport_mode','bond_remote_host','bond_remote_port','bond_sidecar_host','bond_sidecar_port','cf_connect_host','cf_sni_host','cf_dial_port','runtime_preference','runtime_state_json','agent_config_json','updated_at'];
$fselect=array_values(array_intersect($fwant,$fcols));
if($fselect){
  echo "-- FORWARD 47 --\n"; echo json_encode($pdo->query('SELECT '.implode(',',array_map(fn($c)=>"`$c`",$fselect)).' FROM forwards WHERE id=47')->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
try{
  echo "-- ENDPOINT ASSIGNMENTS 47 --\n";
  $sql="SELECT fna.*, n.host AS node_host, n.name AS node_name FROM forward_node_assignments fna LEFT JOIN nodes n ON n.id=fna.node_id WHERE fna.forward_id=47 ORDER BY fna.id";
  echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}catch(Throwable $e){ echo "assign_err: ".$e->getMessage()."\n"; }
?>
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_panel_f47_sidecar_query.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_panel_f47_sidecar_query.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_panel_f47_sidecar_query.php && rm -f codex_panel_f47_sidecar_query.php'