#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
foreach(["sidecar_points","forwards"] as $t){ echo "--cols $t--\n"; foreach($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN) as $c) echo "$c,"; echo "\n"; }
echo "--sidecar5--\n";
$r=$pdo->query("SELECT * FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
echo "--cf forwards point5--\n";
foreach($pdo->query("SELECT id,listen_port,target_host,target_port,bond_remote_host,bond_remote_port,bond_sidecar_point_id,backend_mode,client_mode,transport_mode,path_prefix,enabled FROM forwards WHERE bond_sidecar_point_id=5 ORDER BY id") as $row){ echo json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }
'\'''