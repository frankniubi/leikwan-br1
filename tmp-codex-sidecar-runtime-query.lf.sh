#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_sidecar_runtime_query.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t){
  if(preg_match('/(heartbeat|sidecar|runtime|status|node)/i',$t)){
    echo "--TABLE $t--\n";
    try{ $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN); echo implode(',', $cols)."\n"; }
    catch(Throwable $e){ echo "COLERR ".$e->getMessage()."\n"; }
  }
}
foreach(['sidecar_points','node_heartbeats','sidecar_heartbeats','sidecar_runtime_states','sidecar_statuses'] as $t){
  try{
    echo "--ROWS $t--\n";
    $rows=$pdo->query("SELECT * FROM `$t` ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){ $s=json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if(stripos($s,'185.220.238.143')!==false || stripos($s,'tokyo')!==false || stripos($s,'2053')!==false || stripos($s,'8899')!==false || stripos($s,'07289')!==false) echo substr($s,0,4000)."\n"; }
  }catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p 'Frank1212.' scp -q -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no /tmp/codex_sidecar_runtime_query.php root@10.0.10.196:/tmp/codex_sidecar_runtime_query.php
sshpass -p 'Frank1212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_sidecar_runtime_query.php; rm -f /tmp/codex_sidecar_runtime_query.php'
