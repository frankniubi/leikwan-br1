#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
TOKEN="codex-sidecar5-update-$(date +%s)"
cat >/tmp/codex_trigger_sidecar5_update.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$token=$argv[1] ?? ('codex-sidecar5-update-'.time());
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query('SELECT id,agent_config_json,agent_version,last_heartbeat_at,last_status,last_error FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$config=[];
if (is_string($row['agent_config_json'] ?? null) && trim($row['agent_config_json']) !== '') { $d=json_decode($row['agent_config_json'], true); if (is_array($d)) $config=$d; }
$config['managed_iperf3_ports']=[5201];
$config['sidecar_agent_update_enabled']=true;
$config['disable_auto_update']=false;
$json=json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$st=$pdo->prepare('UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=:t, agent_update_requested_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=5');
$st->execute(['j'=>$json, 't'=>$token]);
echo "token=$token\n";
echo "before=".json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
echo "after_config=$json\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_trigger_sidecar5_update.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_trigger_sidecar5_update.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" "cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_trigger_sidecar5_update.php '$TOKEN' && rm -f codex_trigger_sidecar5_update.php"