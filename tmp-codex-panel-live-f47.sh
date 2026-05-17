#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
cat >/tmp/codex_f47_panel_live.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function dump($label,$rows){ echo "-- $label --\n"; foreach($rows as $r){ foreach($r as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $r[$k]='<redacted>'; } echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; } }
$stmt=$pdo->prepare('SELECT f.id,f.listen_port,f.target_host,f.target_port,f.transport_mode,f.client_mode,f.backend_mode,f.bond_remote_host,f.bond_remote_port,f.bond_ingress_mode,f.bond_sidecar_point_id,f.path_prefix,f.updated_at,s.id AS service_id,s.status AS service_status,s.name AS service_name,sp.name AS sidecar_name,sp.sidecar_host,sp.listen_port AS sp_listen,sp.bond_listen_port,sp.mptcp_listen_port,sp.cf_listen_port,sp.last_heartbeat_at,sp.last_status,sp.agent_version FROM forwards f JOIN services s ON s.id=f.service_id LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id IN (47,59,48) ORDER BY f.id');
$stmt->execute(); dump('forwards', $stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt=$pdo->query("SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,last_heartbeat_at,last_status,last_error,agent_version,is_active FROM sidecar_points ORDER BY id"); dump('sidecars', $stmt->fetchAll(PDO::FETCH_ASSOC));
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_f47_panel_live.php root@10.0.10.196:/tmp/codex_f47_panel_live.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_f47_panel_live.php'
