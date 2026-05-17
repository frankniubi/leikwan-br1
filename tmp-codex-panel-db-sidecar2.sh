#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/panel_sidecar_query.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('.env');
$pdo=(new Database($c))->pdo();
foreach(['bond_sidecar_points','nodes','forwards','forward_chains'] as $t){
  echo "-- $t columns --\n";
  try{$r=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN); echo implode(',', $r)."\n";}catch(Throwable $e){echo $e->getMessage()."\n";}
}
echo "-- sidecars --\n";
try{
foreach($pdo->query('SELECT * FROM bond_sidecar_points')->fetchAll(PDO::FETCH_ASSOC) as $row){
 foreach($row as $k=>$v){ if (preg_match('/(token|secret|password|key)/i',$k)) $row[$k]='<redacted>'; }
 echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
}
}catch(Throwable $e){echo $e->getMessage()."\n";}
echo "-- f47 --\n";
try{
$stmt=$pdo->query('SELECT * FROM forwards WHERE id=47 OR listen_port=11035');
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){ foreach($row as $k=>$v){ if (preg_match('/(token|secret|password|key)/i',$k)) $row[$k]='<redacted>'; } echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
}catch(Throwable $e){echo $e->getMessage()."\n";}
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/panel_sidecar_query.php root@10.0.10.196:/tmp/panel_sidecar_query.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/panel_sidecar_query.php'