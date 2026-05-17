#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196; PASS='Frank1212.'
cat > /tmp/codex_sidecar5_fix.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query("SELECT id, agent_config_json FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC);
$config=json_decode((string)($row['agent_config_json']??''), true);
if(!is_array($config)) $config=[];
$config['disable_auto_update']=true;
$config['sidecar_agent_update_enabled']=false;
$config['managed_iperf3_ports']=[5201,5202];
$config['cf_tls_key_path']='/etc/ssl/cf/private.key';
$config['cf_tls_cert_path']='/etc/ssl/cf/cert.crt';
$config['cf_direct_backend_forward_ids']=[47,59];
$config['approved_upstream_realizations']=[[
  'mode'=>'cf_mode',
  'profile'=>'cf_jp_vm_tokyo',
  'dial_host'=>'nrt1.nbwly.com',
  'dial_port'=>2053,
  'cf_sni_host'=>'nrt1.nbwly.com',
]];
$pdo->prepare("UPDATE sidecar_points SET agent_config_json=?, updated_at=UTC_TIMESTAMP() WHERE id=5")->execute([json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
// keep forward 47 enabled and force route republish touch
$pdo->prepare("UPDATE forwards SET bond_ingress_mode='plain_tcp_mux', backend_mode='cf_mode', updated_at=UTC_TIMESTAMP() WHERE id IN (47,59)")->execute();
echo json_encode($config, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
PHP
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 /tmp/codex_sidecar5_fix.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_sidecar5_fix.php" >/dev/null
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_sidecar5_fix.php; rm -f codex_sidecar5_fix.php'