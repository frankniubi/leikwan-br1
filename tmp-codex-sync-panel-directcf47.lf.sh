#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
SCP_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
REMOTE=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
LOCAL=master/src/Controllers/SidecarAgentController.php
sha256sum "$LOCAL"
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$PANEL" "cp '$REMOTE/master/src/Controllers/SidecarAgentController.php' '$REMOTE/master/src/Controllers/SidecarAgentController.php.bak-codex-$(date +%Y%m%d%H%M%S)'"
sshpass -p "$PASS" scp "${SCP_OPTS[@]}" "$LOCAL" "$PANEL:$REMOTE/master/src/Controllers/SidecarAgentController.php"
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$PANEL" "cd '$REMOTE' && php -l master/src/Controllers/SidecarAgentController.php && sha256sum master/src/Controllers/SidecarAgentController.php && php -r '
require \"master/src/Config.php\"; require \"master/src/Database/Database.php\";
use Leikwan\\Master\\Config; use Leikwan\\Master\\Database\\Database;
\$pdo=(new Database(new Config(\"master/.env\")))->pdo();
\$row=\$pdo->query(\"SELECT agent_config_json FROM sidecar_points WHERE id=5\")->fetch(PDO::FETCH_ASSOC);
\$c=json_decode((string)(\$row[\"agent_config_json\"]??\"\"), true); if(!is_array(\$c)) \$c=[];
\$c[\"disable_auto_update\"]=true;
\$c[\"sidecar_agent_update_enabled\"]=false;
\$c[\"managed_iperf3_ports\"]=[5201,5202];
\$c[\"cf_tls_key_path\"]=\"/etc/ssl/cf/private.key\";
\$c[\"cf_tls_cert_path\"]=\"/etc/ssl/cf/cert.crt\";
\$c[\"cf_direct_backend_forward_ids\"]=[47];
\$pdo->prepare(\"UPDATE sidecar_points SET agent_config_json=?, updated_at=UTC_TIMESTAMP() WHERE id=5\")->execute([json_encode(\$c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
\$pdo->prepare(\"UPDATE forwards SET updated_at=UTC_TIMESTAMP() WHERE id=47\")->execute();
echo json_encode(\$c, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), PHP_EOL;
'"