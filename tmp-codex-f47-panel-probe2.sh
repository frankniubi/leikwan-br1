#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
SSH_OPTS=(-o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -e ssh "${SSH_OPTS[@]}" root@10.0.10.196 'bash -s' <<'RS'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
cat > /tmp/codex_f47_panel_probe.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$c=new Config('master/.env'); $pdo=(new Database($c))->pdo();
function redact($row){ foreach($row as $k=>$v){ if(preg_match('/(token|secret|password|key)/i',$k)) $row[$k]='<redacted>'; } return $row; }
function dumpj($label,$v){ echo "-- $label --\n"; echo json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"; }
$f=$pdo->query("SELECT f.*, s.status service_status, c.name client_name, c.display_host client_display_host, sp.id sp_id, sp.name sp_name, sp.sidecar_host sp_host, sp.listen_port sp_legacy_port, sp.bond_listen_port sp_bond_port, sp.mptcp_listen_port sp_mptcp_port, sp.cf_listen_port sp_cf_port, sp.agent_version sp_agent_version, sp.last_status sp_last_status, sp.last_heartbeat_at sp_last_heartbeat_at, sp.agent_config_json sp_agent_config_json FROM forwards f LEFT JOIN services s ON s.id=f.service_id LEFT JOIN clients c ON c.id=f.line_client_id LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47")->fetch(PDO::FETCH_ASSOC);
dumpj('f47', redact($f ?: []));
$sidecars=$pdo->query("SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,is_active,agent_version,last_status,last_heartbeat_at,agent_config_json FROM sidecar_points ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
dumpj('sidecar_points', array_map('redact',$sidecars));
$routes=$pdo->query("SELECT f.id, f.path_prefix, f.client_mode, f.transport_mode, f.backend_mode, f.target_host, f.target_port, f.bond_remote_host, f.bond_remote_port, f.bond_sidecar_point_id, s.status service_status FROM forwards f INNER JOIN services s ON s.id=f.service_id WHERE f.bond_sidecar_point_id=(SELECT bond_sidecar_point_id FROM forwards WHERE id=47) AND f.enabled=1 ORDER BY f.id")->fetchAll(PDO::FETCH_ASSOC);
dumpj('same_sidecar_forwards', array_map('redact',$routes));
// Pull actual client config via ProvisioningService for client 3 active session if possible
try {
  require 'master/src/Support/ProvisioningService.php';
  $svc=new Leikwan\Master\Support\ProvisioningService($c, new Database($c));
  $ref=new ReflectionMethod($svc,'buildClientConfig'); $ref->setAccessible(true);
  $cfg=$ref->invoke($svc,47);
  dumpj('buildClientConfig47_summary', [
    'mode'=>$cfg['mode']??null,
    'runtime_preference'=>$cfg['forward_combination_runtime_preference']??null,
    'bonding_keys'=>is_array($cfg['bonding']??null)?array_keys($cfg['bonding']):null,
    'bonding'=>is_array($cfg['bonding']??null)?array_intersect_key($cfg['bonding'], array_flip(['remote_target','sidecar_host','sidecar_port','relay_host','relay_port','ingress_mode','route_key','policy_mode'])):null,
    'mptcp'=>is_array($cfg['mptcp']??null)?array_intersect_key($cfg['mptcp'], array_flip(['sidecar_host','sidecar_port','relay_host','relay_port','remote_host','remote_port'])):null,
    'bond_sidecar_port'=>$cfg['bond_sidecar_port']??null,
    'mptcp_sidecar_port'=>$cfg['mptcp_sidecar_port']??null,
    'cf_listen'=>$cfg['bond_sidecar_cf_listen_port']??null,
    'sni'=>$cfg['sni']??null,
    'path_prefix'=>$cfg['path_prefix']??null,
  ]);
} catch (Throwable $e) { echo "buildClientConfig error ".$e->getMessage()."\n"; }
PHP
php /tmp/codex_f47_panel_probe.php
RS
