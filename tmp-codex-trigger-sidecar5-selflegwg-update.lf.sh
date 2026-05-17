#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
TOKEN="codex-sidecar5-selflegwg-$(date +%Y%m%d%H%M%S)"
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_trigger_sidecar5_update.php <<PHP
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\\Master\\Config;
use Leikwan\\Master\\Database\\Database;
\$pdo=(new Database(new Config('master/.env')))->pdo();
\$token='$TOKEN';
\$stmt=\$pdo->prepare('UPDATE sidecar_points SET agent_update_request_token = :token, agent_update_requested_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = 5');
\$stmt->execute(['token'=>\$token]);
\$row=\$pdo->query('SELECT id,name,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_update_requested_at FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
echo json_encode(\$row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_trigger_sidecar5_update.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_trigger_sidecar5_update.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_trigger_sidecar5_update.php; rm -f codex_trigger_sidecar5_update.php'