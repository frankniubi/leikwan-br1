#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_panel_endpoints.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
foreach(["forward_endpoints","service_endpoints","forward_chain_endpoints","node_tasks","nodes","client_forward_endpoints"] as $t){
  try{ echo "-- columns $t --\n"; echo implode(",",$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN))."\n"; }catch(Throwable $e){}
}
foreach(["forward_endpoints","service_endpoints","forward_chain_endpoints","client_forward_endpoints"] as $t){
  try{
    echo "-- rows $t --\n";
    $rows=$pdo->query("SELECT * FROM `$t` WHERE forward_id=47 OR service_id=2 OR id IN (316,317,318,319,320,340) LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match("/(token|secret|password|key)/i",$k))$r[$k]="<redacted>";} echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
  }catch(Throwable $e){ echo "$t err ".$e->getMessage()."\n"; }
}
try{
  echo "-- tasks endpoint recent --\n";
  $rows=$pdo->query("SELECT id,node_id,service_id,forward_id,endpoint_id,action,status,created_at,updated_at, LEFT(payload,800) payload FROM node_tasks WHERE endpoint_id IN (316,317,318,319,320,340,416,417,418,419,420,421) ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match("/(token|secret|password|key)/i",$k))$r[$k]="<redacted>";} echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
}catch(Throwable $e){ echo "tasks err ".$e->getMessage()."\n"; }
try{
  echo "-- nodes likely --\n";
  $cols=$pdo->query("SHOW COLUMNS FROM nodes")->fetchAll(PDO::FETCH_COLUMN);
  $select=[]; foreach(['id','name','host','public_ip','ssh_host','status','last_heartbeat_at','agent_version'] as $c){ if(in_array($c,$cols,true))$select[]=$c; }
  $rows=$pdo->query("SELECT ".implode(',',$select)." FROM nodes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){ echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
}catch(Throwable $e){ echo "nodes err ".$e->getMessage()."\n"; }
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_endpoints.php root@10.0.10.196:/tmp/codex_panel_endpoints.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_endpoints.php'
