#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196; PASS='Frank1212.'
cat > /tmp/codex_sidecar5_state.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$tables=['sidecar_points','sidecar_nodes','bond_sidecar_points','nodes','credential_profiles','server_credential_profiles','forwards','forward_combinations','forward_combination_endpoints','node_services'];
foreach($tables as $t){
  try{
    echo "--$t--\n";
    $rows=$pdo->query("SELECT * FROM `$t` LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r){
      $s=json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      if(stripos($s,'185.220.238.143')!==false || stripos($s,'nrt1')!==false || stripos($s,'tokyo')!==false || stripos($s,'07289d1f5ed25399')!==false || stripos($s,'sidecar')!==false || stripos($s,'GK@')!==false || stripos($s,'161.248.136.183')!==false || ($t==='credential_profiles'||$t==='server_credential_profiles')) echo $s,"\n";
    }
  } catch(Throwable $e) { echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 /tmp/codex_sidecar5_state.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar5_state.php" >/dev/null
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar5_state.php; rm -f codex_sidecar5_state.php'