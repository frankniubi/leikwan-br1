#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_client47_token.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$rows=$pdo->query("SELECT c.id,c.name,c.display_host,c.agent_token,c.agent_version,c.last_sync_at,c.agent_config_json FROM clients c WHERE c.id IN (SELECT DISTINCT line_client_id FROM forwards WHERE id=47 OR id=48 OR id=59) OR c.display_host LIKE '%114.80.33.50%' ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
?>
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_client47_token.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_client47_token.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_client47_token.php && rm -f codex_client47_token.php'