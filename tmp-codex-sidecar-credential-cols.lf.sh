#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_sidecar_cols.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach(['sidecar_points','credential_profiles','server_credential_profiles','nodes','service_endpoints','provision_tasks'] as $t){
 try { echo "--$t--\n"; foreach($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC) as $c){echo $c['Field']."\t".$c['Type']."\n";} } catch(Throwable $e){ echo "ERR $t ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_sidecar_cols.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_sidecar_cols.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_sidecar_cols.php && rm -f codex_query_sidecar_cols.php'