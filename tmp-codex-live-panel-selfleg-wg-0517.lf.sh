#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
cat >/tmp/codex_f47_selfleg_wg_panel.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php'; require 'master/src/Support/ProvisioningService.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database; use Leikwan\Master\Support\ProvisioningService;
$config=new Config(__DIR__.'/master/.env'); $db=new Database($config); $pdo=$db->pdo(); $svc=new ProvisioningService($config,$db);
$f=$pdo->query("SELECT f.id, f.line_client_id, f.client_mode, f.backend_mode, f.forward_combination_id, f.bond_sidecar_point_id, f.listen_port, f.target_host, f.target_port, sp.name sp_name, sp.sidecar_host, sp.listen_port sp_listen_port, sp.bond_listen_port, sp.mptcp_listen_port, sp.cf_listen_port, sp.agent_version sp_agent_version, sp.last_heartbeat_at sp_hb, sp.last_status sp_status, sp.agent_config_json sp_cfg, c.agent_version client_agent_version, c.last_sync_at client_sync, c.agent_config_json client_cfg, fc.runtime_preference, fc.allow_private_self_leg, fc.self_leg_scope, fc.self_leg_role, fc.helper_group_cap FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id LEFT JOIN clients c ON c.id=f.line_client_id LEFT JOIN forward_combinations fc ON fc.id=f.forward_combination_id WHERE f.id=47")->fetch(PDO::FETCH_ASSOC);
echo "F47=".json_encode($f, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$session=$pdo->query("SELECT * FROM client_device_sessions WHERE client_id=3 ORDER BY last_seen_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "SESSION=".json_encode($session, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$forwards=$svc->assignedForwardConfigsForClient(3, is_array($session)?$session:null);
foreach($forwards as $fw){ if((int)($fw['forward_id']??0)===47){ echo "PAYLOAD47=".json_encode($fw, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }}
echo "PEERS=".json_encode($svc->selfLegClientPeersForPoint((int)($f['bond_sidecar_point_id']??0)), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$versions=json_decode(file_get_contents('artifacts/versions.json'), true); echo "VERSIONS=".json_encode(array_intersect_key($versions, array_flip(['client_agent','install_forward_runtime','mptcp_client_runtime','sidecar_agent'])), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_f47_selfleg_wg_panel.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_f47_selfleg_wg_panel.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_f47_selfleg_wg_panel.php; rm -f codex_f47_selfleg_wg_panel.php; sha256sum client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py sidecar-agent/sidecar_agent.py artifacts/versions.json'
