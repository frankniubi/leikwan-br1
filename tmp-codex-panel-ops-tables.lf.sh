#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_panel_ops.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t){ if(preg_match('/(task|endpoint|provision|node|job|forward|chain|agent|route)/i',$t)) echo $t."\n"; }
foreach($tables as $t){ if(preg_match('/(node.*task|task|provision)/i',$t)){ try{ echo "-- columns $t --\n"; echo implode(',', $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN))."\n";}catch(Throwable $e){} } }
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_ops.php root@10.0.10.196:/tmp/codex_panel_ops.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_ops.php'
