#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PASS='Frank1212.'
cat > /tmp/codex_sidecar_creds_now.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach(['sidecar_points','bond_sidecar_points','nodes','credential_profiles','server_credential_profiles'] as $t){
  try { echo "--$t--\n"; $rows=$pdo->query("SELECT * FROM `$t` LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r){ if(stripos(json_encode($r),'185.220.238.143')!==false || stripos(json_encode($r),'tokyo')!==false || stripos(json_encode($r),'nrt')!==false || $t==='credential_profiles' || $t==='server_credential_profiles') echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; } } catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 /tmp/codex_sidecar_creds_now.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar_creds_now.php" >/dev/null
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar_creds_now.php; rm -f codex_sidecar_creds_now.php'
