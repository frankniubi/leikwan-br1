#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
cat >/tmp/codex_f47_panel_state.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function dumpRows($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|private_key|public_key)/i',$k)) $r[$k]='<redacted>'; } echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }}
$queries = [
 'forward47' => "SELECT f.*, fc.name AS combo_name, fc.runtime_preference, fc.allow_private_self_leg, fc.self_leg_scope, fc.self_leg_role, fc.helper_group_cap, fc.bond_ingress_mode AS combo_bond_ingress_mode FROM forwards f LEFT JOIN forward_combinations fc ON fc.id=f.forward_combination_id WHERE f.id=47",
 'endpoints47' => "SELECT se.*, n.name AS node_name,n.public_ip,n.status AS node_status,n.agent_version,n.last_seen_at FROM service_endpoints se LEFT JOIN nodes n ON n.id=se.node_id WHERE se.forward_id=47 ORDER BY se.id",
 'sidecar_points' => "SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,agent_version,last_heartbeat_at,last_status,last_error,LEFT(agent_config_json,1200) agent_config_json FROM sidecar_points WHERE id IN (SELECT bond_sidecar_point_id FROM forwards WHERE id=47) OR id=5 ORDER BY id",
 'tasks_recent' => "SELECT id,node_id,action,service_id,forward_id,endpoint_id,status,created_at,updated_at,LEFT(payload_json,900) payload_json,LEFT(result_json,600) result_json,error_message FROM provision_tasks WHERE forward_id=47 OR endpoint_id IN (316,317,318,319,320,340,416,417,418,419,420,421) ORDER BY id DESC LIMIT 30",
];
foreach($queries as $label=>$sql){ try{ dumpRows($label,$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)); } catch(Throwable $e){ echo "-- $label err ".$e->getMessage()."\n"; } }
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_f47_panel_state.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_f47_panel_state.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_f47_panel_state.php && rm -f codex_f47_panel_state.php'
