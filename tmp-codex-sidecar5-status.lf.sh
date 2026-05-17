#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_sidecar5_status.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$r=$pdo->query('SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_update_requested_at,agent_config_json FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar5_status.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar5_status.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar5_status.php && rm -f codex_sidecar5_status.php'