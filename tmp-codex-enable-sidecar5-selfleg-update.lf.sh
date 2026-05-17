#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
cat >/tmp/codex_enable_sidecar5_update.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config(__DIR__.'/master/.env')))->pdo();
$row=$pdo->query("SELECT id,agent_config_json,agent_version,last_heartbeat_at,last_status,last_error FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
$cfg=json_decode((string)($row['agent_config_json'] ?? '{}'), true); if(!is_array($cfg)) $cfg=[];
$cfg['disable_auto_update']=false;
$cfg['sidecar_agent_update_enabled']=true;
$token='codex-selfleg-wg-sidecar5-'.gmdate('YmdHis');
$stmt=$pdo->prepare("UPDATE sidecar_points SET agent_config_json=:cfg, agent_update_request_token=:tok, agent_update_requested_at=:ts, updated_at=:ts WHERE id=5");
$stmt->execute(['cfg'=>json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 'tok'=>$token, 'ts'=>gmdate('Y-m-d H:i:s')]);
echo "before=".json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
echo "enabled_token=$token\n";
echo "cfg=".json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_enable_sidecar5_update.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_enable_sidecar5_update.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_enable_sidecar5_update.php && rm -f codex_enable_sidecar5_update.php'
for i in $(seq 1 18); do
  sleep 10
  echo "--- poll $i $(date -Is) ---"
  sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); $r=$pdo->query("SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_update_request_token,agent_update_requested_at,agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";'\'''
done