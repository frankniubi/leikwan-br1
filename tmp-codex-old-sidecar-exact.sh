#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_old_sidecar_exact.php <<'PHP'
<?php
declare(strict_types=1);
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('master/.env');
$pdo=(new Database($c))->pdo();
function j($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
echo "-- columns forward_combinations --\n"; try { echo implode(',', $pdo->query('SHOW COLUMNS FROM forward_combinations')->fetchAll(PDO::FETCH_COLUMN))."\n"; } catch(Throwable $e){ echo $e->getMessage()."\n"; }
echo "-- exact old host in sidecar_points --\n";
$stmt=$pdo->prepare("SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,is_active,last_heartbeat_at FROM sidecar_points WHERE sidecar_host=:h OR CONCAT(sidecar_host,':',listen_port)=:hp OR CONCAT(sidecar_host,':',bond_listen_port)=:hp OR CONCAT(sidecar_host,':',mptcp_listen_port)=:hp");
$stmt->execute(['h'=>'161.248.136.183','hp'=>'161.248.136.183:14950']); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r);
echo "-- exact old host in forwards --\n";
$stmt=$pdo->prepare("SELECT f.id, f.listen_port, f.enabled, f.client_mode, f.backend_mode, f.target_host, f.target_port, f.bond_remote_host, f.bond_remote_port, f.bond_sidecar_point_id, sp.name AS sidecar_name, sp.sidecar_host AS sidecar_host FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.target_host=:h OR f.bond_remote_host=:h OR CONCAT(f.target_host,':',f.target_port)=:hp OR CONCAT(f.bond_remote_host,':',f.bond_remote_port)=:hp ORDER BY f.id");
$stmt->execute(['h'=>'161.248.136.183','hp'=>'161.248.136.183:14950']); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r);
echo "-- exact old host in combination sidecars --\n";
try { $stmt=$pdo->prepare("SELECT fc.*, sp.name AS sidecar_name, sp.sidecar_host FROM forward_combinations fc LEFT JOIN sidecar_points sp ON sp.id=fc.sidecar_point_id WHERE sp.sidecar_host=:h ORDER BY fc.id"); $stmt->execute(['h'=>'161.248.136.183']); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r); } catch(Throwable $e){ echo $e->getMessage()."\n"; }
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_old_sidecar_exact.php root@10.0.10.196:/tmp/codex_old_sidecar_exact.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_old_sidecar_exact.php'