#!/usr/bin/env bash
set -euo pipefail
PANEL=10.0.10.196; PPASS='Frank1212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1)
sshpass -p "$PPASS" ssh "${SSH[@]}" root@$PANEL 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php' <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach($pdo->query('SELECT id,forward_id,node_id,public_host,public_ip,public_port,cdn_host,priority,weight,health_status,last_error FROM service_endpoints WHERE forward_id=47 OR id IN (316,317,318,319,320) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
?>
PHP