#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_fix_sidecar5_config.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT id,agent_config_json,agent_version,agent_update_request_token FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$config=[];
if (is_string($row['agent_config_json'] ?? null) && trim($row['agent_config_json']) !== '') {
  $d=json_decode($row['agent_config_json'], true); if (is_array($d)) $config=$d;
}
$config['managed_iperf3_ports']=[5201];
$config['disable_auto_update']=true;
$config['sidecar_agent_update_enabled']=false;
// keep existing approved upstreams untouched
$json=json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$st=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=NULL, agent_update_requested_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=5');
$st->execute(['j'=>$json]);
$after=$pdo->query('SELECT id,agent_version,last_heartbeat_at,last_status,last_error,agent_config_json,agent_update_request_token,agent_update_requested_at FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
echo "before=".json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
echo "after=".json_encode($after, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_fix_sidecar5_config.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_fix_sidecar5_config.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_fix_sidecar5_config.php && rm -f codex_fix_sidecar5_config.php'