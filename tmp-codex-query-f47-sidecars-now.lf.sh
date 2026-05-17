#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_f47_sidecars.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$config=new Config(__DIR__.'/master/.env'); $pdo=(new Database($config))->pdo();
foreach(['forwards','forward_combinations','sidecar_points','sidecar_nodes','nodes','credential_profiles','server_credential_profiles','forward_combination_helper_groups'] as $t){
  try{
    echo "-- $t --\n";
    $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    echo "cols=".implode(',', $cols)."\n";
    if($t==='forwards') $rows=$pdo->query("SELECT * FROM forwards WHERE id=47")->fetchAll(PDO::FETCH_ASSOC);
    elseif($t==='forward_combinations') $rows=$pdo->query("SELECT * FROM forward_combinations WHERE id IN (SELECT forward_combination_id FROM forwards WHERE id=47)")->fetchAll(PDO::FETCH_ASSOC);
    elseif($t==='forward_combination_helper_groups') $rows=$pdo->query("SELECT * FROM forward_combination_helper_groups WHERE forward_combination_id IN (SELECT forward_combination_id FROM forwards WHERE id=47)")->fetchAll(PDO::FETCH_ASSOC);
    elseif(in_array('host',$cols) || in_array('hostname',$cols) || in_array('public_ip',$cols) || in_array('ip',$cols)) $rows=$pdo->query("SELECT * FROM `$t` LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    else $rows=$pdo->query("SELECT * FROM `$t` LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
      $s=json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      if($t==='forwards'||$t==='forward_combinations'||$t==='forward_combination_helper_groups'|| stripos($s,'tokyo')!==false||stripos($s,'nrt')!==false||stripos($s,'185.220')!==false||stripos($s,'161.248')!==false||stripos($s,'sidecar')!==false||stripos($s,'m-jp')!==false) echo $s,"\n";
    }
  }catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_f47_sidecars.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_f47_sidecars.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_f47_sidecars.php; rm -f codex_query_f47_sidecars.php'
