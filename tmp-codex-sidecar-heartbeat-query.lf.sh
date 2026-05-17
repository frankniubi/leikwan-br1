#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_sidecar_heartbeat_query.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t){ if(!preg_match('/sidecar|heart|status|runtime|log/i',$t)) continue; echo "-- TABLE $t --\n"; $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC); echo implode(',', array_column($cols,'Field'))."\n"; try{ $rows=$pdo->query("SELECT * FROM `$t` ORDER BY 1 DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); echo json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"; }catch(Throwable $e){ echo $e->getMessage()."\n"; }}
?>
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar_heartbeat_query.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar_heartbeat_query.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar_heartbeat_query.php && rm -f codex_sidecar_heartbeat_query.php'