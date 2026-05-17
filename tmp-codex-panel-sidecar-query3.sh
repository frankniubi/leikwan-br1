#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
cat > /tmp/panel_sidecar_query.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('master/.env');
$pdo=(new Database($c))->pdo();
$tables=['sidecar_points','bond_sidecar_points','nodes','forwards','client_device_sessions','forward_combinations','cf_location_groups','cf_upstream_realizations'];
foreach($tables as $t){
  echo "-- $t columns --\n";
  try{$r=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN); echo implode(',', $r)."\n";}catch(Throwable $e){echo $e->getMessage()."\n";}
}
echo "-- sidecar_points --\n";
try{foreach($pdo->query('SELECT * FROM sidecar_points ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){foreach($row as $k=>$v){if(preg_match('/(token|secret|password|key)/i',$k))$row[$k]='<redacted>';}; echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}catch(Throwable $e){echo $e->getMessage()."\n";}
echo "-- forward47 --\n";
try{$stmt=$pdo->query('SELECT * FROM forwards WHERE id=47 OR listen_port=11035'); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){foreach($row as $k=>$v){if(preg_match('/(token|secret|password|key)/i',$k))$row[$k]='<redacted>';}; echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}catch(Throwable $e){echo $e->getMessage()."\n";}
echo "-- f47 client sessions --\n";
try{$sql="SELECT f.id forward_id, f.line_client_id, c.id client_id, c.name client_name, cds.hostname, cds.remote_addr, cds.last_seen_at, cds.agent_version FROM forwards f LEFT JOIN clients c ON c.id=f.line_client_id LEFT JOIN client_device_sessions cds ON cds.client_id=c.id WHERE f.id=47 ORDER BY cds.last_seen_at DESC LIMIT 10"; foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row){echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}catch(Throwable $e){echo $e->getMessage()."\n";}
echo "-- sidecar cf runtime rows if available --\n";
foreach(['sidecar_runtime_routes','sidecar_cf_runtime_routes','sidecar_tasks','agent_tasks','node_tasks'] as $t){try{$r=$pdo->query("SELECT * FROM `$t` ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); echo "table $t\n"; foreach($r as $row){foreach($row as $k=>$v){if(preg_match('/(token|secret|password|key)/i',$k))$row[$k]='<redacted>';}; echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}catch(Throwable $e){}}
PHP
sshpass -p "$PASS_PANEL" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/panel_sidecar_query.php root@10.0.10.196:/tmp/panel_sidecar_query.php >/dev/null
sshpass -p "$PASS_PANEL" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/panel_sidecar_query.php'