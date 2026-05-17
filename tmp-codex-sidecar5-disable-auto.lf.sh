#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_sidecar5_disable_auto.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT agent_config_json FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$cfg=json_decode((string)($row['agent_config_json']??''), true); if(!is_array($cfg)) $cfg=[];
$cfg['sidecar_agent_update_enabled']=false;
$cfg['disable_auto_update']=true;
$stmt=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:cfg, agent_update_request_token=NULL, agent_update_requested_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=5');
$stmt->execute(['cfg'=>json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
echo json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar5_disable_auto.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar5_disable_auto.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar5_disable_auto.php; rm -f codex_sidecar5_disable_auto.php'