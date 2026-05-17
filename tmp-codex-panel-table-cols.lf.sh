set -euo pipefail
cat >/tmp/codex_panel_table_cols.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
foreach(['service_endpoints','nodes','provision_tasks','forward_publications','node_service_states'] as $t){
 echo "-- $t --\n";
 try { $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){echo $c['Field'].',';} echo "\n"; $rows=$pdo->query("SELECT * FROM `$t` LIMIT 2")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r){foreach($r as $k=>$v){if(preg_match('/(token|secret|password|key)/i',$k))$r[$k]='<redacted>'; } echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";} } catch(Throwable $e){ echo "ERR ".$e->getMessage()."\n"; }
}
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_table_cols.php root@10.0.10.196:/tmp/codex_panel_table_cols.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_table_cols.php'