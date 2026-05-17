set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -o PreferredAuthentications=password -o PubkeyAuthentication=no)
cat >/tmp/codex_tokyo_sidecar_creds.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
foreach(['sidecar_points','bond_sidecar_points','nodes','server_nodes','relay_nodes'] as $t){
  try{
    echo "-- columns $t --\n";
    $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    echo implode(',', $cols)."\n";
    if (in_array($t,['sidecar_points','bond_sidecar_points'])) {
      $rows=$pdo->query("SELECT * FROM `$t` WHERE sidecar_host IN ('185.220.238.143','161.248.136.183') OR name LIKE '%tokyo%' OR name LIKE '%nrt%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $rows=$pdo->query("SELECT * FROM `$t` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach($rows as $r){ echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; }
  } catch(Throwable $e){ echo "-- $t err ".$e->getMessage()."\n"; }
}
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_tokyo_sidecar_creds.php root@10.0.10.196:/tmp/codex_tokyo_sidecar_creds.php >/dev/null
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_tokyo_sidecar_creds.php'
