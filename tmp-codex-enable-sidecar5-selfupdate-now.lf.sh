#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
cat >/tmp/codex_enable_sidecar5_selfupdate_now.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config(__DIR__.'/master/.env')))->pdo();
$row=$pdo->query("SELECT id,agent_config_json,agent_version,agent_update_request_token,last_heartbeat_at,last_status,last_error FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
if(!$row){fwrite(STDERR,"missing sidecar 5\n"); exit(1);} 
$cfg=json_decode((string)($row['agent_config_json'] ?? ''), true); if(!is_array($cfg)) $cfg=[];
$cfg['sidecar_agent_update_enabled']=true;
$cfg['disable_auto_update']=false;
$cfg['managed_iperf3_ports']=[5201,5202];
$cfg['wireguard_listen_port']=$cfg['wireguard_listen_port'] ?? 14969;
$cfg['wireguard_fallback_tcp_port']=$cfg['wireguard_fallback_tcp_port'] ?? 24969;
$token='codex-sidecar5-selfleg-wg-'.gmdate('YmdHis');
$st=$pdo->prepare("UPDATE sidecar_points SET agent_config_json=:j, agent_update_request_token=:t, agent_update_requested_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=5");
$st->execute(['j'=>json_encode($cfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'t'=>$token]);
echo json_encode(['before'=>$row,'token'=>$token,'cfg'=>$cfg], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_enable_sidecar5_selfupdate_now.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_enable_sidecar5_selfupdate_now.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_enable_sidecar5_selfupdate_now.php && rm -f codex_enable_sidecar5_selfupdate_now.php'
