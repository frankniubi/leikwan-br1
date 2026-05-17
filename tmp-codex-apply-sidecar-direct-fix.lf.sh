#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196; PASS='Frank1212.'
cat > /tmp/codex_sidecar_direct_patch.py <<'PY'
from pathlib import Path
p=Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php')
s=p.read_text()
old="""        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $request->input('supports_mptcp'),
            'wireguard_public_key' => $request->input('wireguard_public_key'),
            'wireguard_listen_port' => $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),
        ]);
        $updateRequestToken = trim((string) ($point['agent_update_request_token'] ?? ''));"""
new="""        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $request->input('supports_mptcp'),
            'wireguard_public_key' => $request->input('wireguard_public_key'),
            'wireguard_listen_port' => $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $request->input('wireguard_fallback_tcp_port'),
        ]);
        $directCfBackendForwardIds = [];
        $rawDirectCfBackendForwardIds = $runtimeState['cf_direct_backend_forward_ids'] ?? [];
        if (is_array($rawDirectCfBackendForwardIds)) {
            foreach ($rawDirectCfBackendForwardIds as $rawForwardId) {
                $directForwardId = (int) $rawForwardId;
                if ($directForwardId > 0) {
                    $directCfBackendForwardIds[$directForwardId] = true;
                }
            }
        }
        $updateRequestToken = trim((string) ($point['agent_update_request_token'] ?? ''));"""
if old not in s and '$directCfBackendForwardIds = [];' not in s:
    raise SystemExit('direct ids anchor missing')
if old in s:
    s=s.replace(old,new,1)
old2="""                    'target' => $target,
                    'transport_mode' => $transportMode,
                ];"""
new2="""                    'target' => $target,
                    'transport_mode' => $transportMode,
                    'backend_mode' => isset($directCfBackendForwardIds[$forwardId]) ? 'direct' : 'wstunnel',
                ];"""
if old2 not in s and "'backend_mode' => isset($directCfBackendForwardIds[$forwardId])" not in s:
    raise SystemExit('route backend_mode anchor missing')
if old2 in s:
    s=s.replace(old2,new2,1)
p.write_text(s)
PY
cat > /tmp/codex_sidecar5_direct_cfg.php <<'PHP'
<?php
require '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Config.php'; require '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env')))->pdo();
$row=$pdo->query('SELECT agent_config_json FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$c=json_decode((string)($row['agent_config_json']??''), true); if(!is_array($c)) $c=[];
$c['disable_auto_update']=true;
$c['sidecar_agent_update_enabled']=false;
$c['managed_iperf3_ports']=[5201,5202];
$c['cf_tls_key_path']='/etc/ssl/cf/private.key';
$c['cf_tls_cert_path']='/etc/ssl/cf/cert.crt';
$c['cf_direct_backend_forward_ids']=[47,49,52,59,61];
$pdo->prepare('UPDATE sidecar_points SET agent_config_json=?, updated_at=UTC_TIMESTAMP() WHERE id=5')->execute([json_encode($c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
$pdo->prepare("UPDATE forwards SET updated_at=UTC_TIMESTAMP() WHERE bond_sidecar_point_id=5 AND enabled=1 AND backend_mode='cf_mode'")->execute();
echo json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
PHP
sshpass -p "$PASS" scp -q -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no /tmp/codex_sidecar_direct_patch.py /tmp/codex_sidecar5_direct_cfg.php "$PANEL:/tmp/"
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no "$PANEL" 'python3 /tmp/codex_sidecar_direct_patch.py && php -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php && php /tmp/codex_sidecar5_direct_cfg.php && rm -f /tmp/codex_sidecar_direct_patch.py /tmp/codex_sidecar5_direct_cfg.php'