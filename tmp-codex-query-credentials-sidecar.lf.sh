set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_query_creds.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach($pdo->query("SELECT id,name,provider,config_json FROM credential_profiles ORDER BY id") as $r){ echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }
echo "--nodes sidecar-ish--\n";
foreach($pdo->query("SELECT id,name,public_ip,credential_profile_id,agent_config_json FROM nodes WHERE public_ip IN ('185.220.238.143','161.248.136.183') OR name LIKE '%tokyo%' OR name LIKE '%nrt%' OR name LIKE '%sidecar%' ORDER BY id") as $r){ echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_creds.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_creds.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_creds.php && rm -f codex_query_creds.php'