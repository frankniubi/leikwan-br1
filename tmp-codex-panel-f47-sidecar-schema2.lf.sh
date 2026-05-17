#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_panel_f47_sidecar_query2.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function exists_table($pdo,$table){ $st=$pdo->prepare('SHOW TABLES LIKE ?'); $st->execute([$table]); return (bool)$st->fetchColumn(); }
function cols($pdo,$table){ if(!exists_table($pdo,$table)) return []; $rs=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); return array_map(fn($r)=>$r['Field'],$rs); }
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "-- INTERESTING TABLES --\n"; echo implode("\n", array_values(array_filter($tables, fn($t)=>preg_match('/sidecar|forward|assign|node|endpoint|combo|combination/i',$t)))),"\n";
foreach(['sidecar_points','forwards','nodes','forward_assignments','forward_endpoints','forward_node_bindings','forward_combination_nodes','forward_combination_helper_groups'] as $t){ $c=cols($pdo,$t); if($c){ echo "-- COLUMNS $t --\n"; echo implode(',',$c),"\n"; }}
$sideCols=cols($pdo,'sidecar_points');
$want=['id','name','sidecar_host','listen_port','bond_listen_port','mptcp_listen_port','cf_listen_port','agent_token','agent_version','agent_config_json','agent_update_request_token','agent_update_requested_at','last_heartbeat_at','last_status','last_error','is_active'];
$select=array_values(array_intersect($want,$sideCols));
if($select){
  echo "-- SIDECAR 5 --\n"; echo json_encode($pdo->query('SELECT '.implode(',',array_map(fn($c)=>"`$c`",$select)).' FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
$fcols=cols($pdo,'forwards');
$fwant=['id','service_id','listen_port','target_host','target_port','bond_remote_host','bond_remote_port','bond_ingress_mode','bond_sidecar_point_id','forward_combination_id','backend_mode','cf_location_group_id','transport_mode','client_mode','sni_host','path_prefix','node_group_key','endpoint_count','public_port','cdn_host','enabled','updated_at'];
$fselect=array_values(array_intersect($fwant,$fcols));
if($fselect){
  echo "-- FORWARD 47 --\n"; echo json_encode($pdo->query('SELECT '.implode(',',array_map(fn($c)=>"`$c`",$fselect)).' FROM forwards WHERE id=47')->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
foreach($tables as $t){
 if(!preg_match('/forward|assign|endpoint|node/i',$t)) continue;
 $c=cols($pdo,$t); if(!in_array('forward_id',$c,true)) continue;
 try{ $st=$pdo->query("SELECT * FROM `$t` WHERE forward_id=47 LIMIT 20"); $rows=$st->fetchAll(PDO::FETCH_ASSOC); if($rows){ echo "-- ROWS $t forward_id=47 --\n"; echo json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n"; }}catch(Throwable $e){ echo "-- ERR $t ".$e->getMessage()."\n"; }
}
?>
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_panel_f47_sidecar_query2.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_panel_f47_sidecar_query2.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_panel_f47_sidecar_query2.php && rm -f codex_panel_f47_sidecar_query2.php'