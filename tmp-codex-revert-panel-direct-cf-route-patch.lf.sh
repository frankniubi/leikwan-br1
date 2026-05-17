#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196; PASS='Frank1212.'
cat > /tmp/codex_revert_direct_patch.py <<'PY'
from pathlib import Path
p=Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php')
s=p.read_text()
s=s.replace("\n        $directCfBackendForwardIds = [];\n        $rawDirectCfBackendForwardIds = $runtimeState['cf_direct_backend_forward_ids'] ?? [];\n        if (is_array($rawDirectCfBackendForwardIds)) {\n            foreach ($rawDirectCfBackendForwardIds as $rawForwardId) {\n                $directForwardId = (int) $rawForwardId;\n                if ($directForwardId > 0) {\n                    $directCfBackendForwardIds[$directForwardId] = true;\n                }\n            }\n        }", "")
s=s.replace("\n                    'backend_mode' => isset($directCfBackendForwardIds[$forwardId]) ? 'direct' : 'wstunnel',", "")
p.write_text(s)
PY
cat > /tmp/codex_remove_direct_cfg.php <<'PHP'
<?php
require '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Config.php'; require '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env')))->pdo();
$row=$pdo->query('SELECT agent_config_json FROM sidecar_points WHERE id=5')->fetch(PDO::FETCH_ASSOC);
$c=json_decode((string)($row['agent_config_json']??''), true); if(!is_array($c)) $c=[];
unset($c['cf_direct_backend_forward_ids']);
$c['disable_auto_update']=true; $c['sidecar_agent_update_enabled']=false; $c['managed_iperf3_ports']=[5201,5202];
$pdo->prepare('UPDATE sidecar_points SET agent_config_json=?, updated_at=UTC_TIMESTAMP() WHERE id=5')->execute([json_encode($c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
echo json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
PHP
sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 /tmp/codex_revert_direct_patch.py /tmp/codex_remove_direct_cfg.php "$PANEL:/tmp/" >/dev/null
sshpass -p "$PASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 "$PANEL" 'python3 /tmp/codex_revert_direct_patch.py && php -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/SidecarAgentController.php && php /tmp/codex_remove_direct_cfg.php && rm -f /tmp/codex_revert_direct_patch.py /tmp/codex_remove_direct_cfg.php'