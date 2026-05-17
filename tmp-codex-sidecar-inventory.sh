#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex_sidecar_inventory.php <<'PHP'
<?php
declare(strict_types=1);
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('master/.env');
$pdo=(new Database($c))->pdo();
function j($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
$sidecars=$pdo->query("SELECT sp.id, sp.name, sp.sidecar_host, sp.listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.is_active, sp.last_status, sp.last_heartbeat_at, sp.updated_at, COUNT(f.id) AS forward_count, SUM(CASE WHEN f.enabled=1 THEN 1 ELSE 0 END) AS active_forward_count FROM sidecar_points sp LEFT JOIN forwards f ON f.bond_sidecar_point_id=sp.id GROUP BY sp.id ORDER BY sp.id")->fetchAll(PDO::FETCH_ASSOC);
echo "-- sidecars --\n"; foreach($sidecars as $r) j($r);
echo "-- forwards on old sidecar host 161.248.136.183 --\n";
$stmt=$pdo->prepare("SELECT f.id, f.listen_port, f.target_host, f.target_port, f.enabled, f.client_mode, f.backend_mode, f.bond_sidecar_point_id, sp.name AS sidecar_name, sp.sidecar_host, sp.listen_port AS sp_listen_port FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE sp.sidecar_host=:h OR f.bond_remote_host=:h ORDER BY f.id");
$stmt->execute(['h'=>'161.248.136.183']); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r);
echo "-- combinations old sidecar host 161.248.136.183 --\n";
try { $stmt=$pdo->prepare("SELECT fc.id, fc.name, fc.label, fc.is_active, fc.node_group_key, fc.sidecar_point_id, sp.name AS sidecar_name, sp.sidecar_host FROM forward_combinations fc LEFT JOIN sidecar_points sp ON sp.id=fc.sidecar_point_id WHERE sp.sidecar_host=:h ORDER BY fc.id"); $stmt->execute(['h'=>'161.248.136.183']); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r); } catch(Throwable $e) { echo "combo query err ".$e->getMessage()."\n"; }
echo "-- f47/f48/f59 current sidecars --\n";
$stmt=$pdo->query("SELECT f.id, f.listen_port, f.enabled, f.client_mode, f.backend_mode, f.bond_remote_host, f.bond_remote_port, f.bond_sidecar_point_id, sp.name AS sidecar_name, sp.sidecar_host, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id IN (47,48,59) ORDER BY f.id"); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) j($r);
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_sidecar_inventory.php root@10.0.10.196:/tmp/codex_sidecar_inventory.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_sidecar_inventory.php'