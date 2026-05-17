#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_nodes.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$ips=['47.116.11.144','47.116.134.3','47.116.104.15','8.133.170.123','101.133.142.65'];
$placeholders=implode(',', array_fill(0,count($ips),'?'));
$st=$pdo->prepare("SELECT id,name,public_ip,agent_version,status,last_heartbeat_at,agent_config_json,agent_update_request_token,agent_update_requested_at FROM nodes WHERE public_ip IN ($placeholders) ORDER BY id");
$st->execute($ips);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_nodes.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_nodes.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_nodes.php && rm -f codex_query_nodes.php'