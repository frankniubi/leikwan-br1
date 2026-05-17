#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/panel_sidecar5_state.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c=new Config('master/.env');
$pdo=(new Database($c))->pdo();
function red($row){ foreach($row as $k=>$v){ if(preg_match('/(token|secret|password|private_key|public_key|key)/i',$k)) $row[$k]='<redacted>'; } return $row; }
function j($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"; }
echo "-- sidecar point 5 --\n";
$row=$pdo->query('SELECT * FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC); j(red($row ?: []));
echo "-- sidecar point 5 agent_config_json decoded keys --\n";
$cfg=json_decode((string)($row['agent_config_json']??''), true); if(!is_array($cfg)){$cfg=[];} $clean=$cfg; foreach($clean as $k=>$v){ if(preg_match('/(token|secret|password|private_key|public_key|key)/i',$k)) $clean[$k]='<redacted>'; } j($clean);
echo "-- f47 sidecar-agent management emulation data --\n";
$rows=$pdo->query("SELECT f.id, f.path_prefix, f.target_host, f.target_port, f.transport_mode, f.bond_remote_host, f.bond_remote_port, f.backend_mode, fc.upstream_realization_mode AS chain_upstream_realization_mode FROM forwards f LEFT JOIN forward_chains fc ON fc.id=f.forward_chain_id INNER JOIN services s ON s.id=f.service_id WHERE f.bond_sidecar_point_id=5 AND f.enabled=1 AND f.backend_mode='cf_mode' AND s.status='active' ORDER BY f.id ASC")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r) j(red($r));
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/panel_sidecar5_state.php root@10.0.10.196:/tmp/panel_sidecar5_state.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/panel_sidecar5_state.php'