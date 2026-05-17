#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_sidecar_creds_query.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$config=new Config(__DIR__.'/master/.env'); $pdo=(new Database($config))->pdo();
$tables=['sidecar_points','nodes','credential_profiles','server_credential_profiles','sidecar_nodes','node_credentials'];
foreach($tables as $t){
  try{
    echo "--$t--\n";
    $rows=$pdo->query("SELECT * FROM `$t` LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
      $s=json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      if(stripos($s,'185.220.238.143')!==false || stripos($s,'tokyo')!==false || stripos($s,'nrt')!==false || stripos($s,'sidecar')!==false || $t==='credential_profiles' || $t==='server_credential_profiles') echo $s,"\n";
    }
  }catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar_creds_query.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar_creds_query.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar_creds_query.php; rm -f codex_sidecar_creds_query.php'
