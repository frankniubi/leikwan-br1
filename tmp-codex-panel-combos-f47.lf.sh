#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_panel_combos_f47.php <<'PHP'
<?php
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
function dump($label,$rows){echo "-- $label --\n"; foreach($rows as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}
dump('f47_combo', $pdo->query('SELECT f.id,f.client_mode,f.bond_ingress_mode,f.backend_mode,f.forward_combination_id, fc.* FROM forwards f LEFT JOIN forward_combinations fc ON fc.id=f.forward_combination_id WHERE f.id=47')->fetchAll(PDO::FETCH_ASSOC));
dump('combos', $pdo->query('SELECT * FROM forward_combinations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_panel_combos_f47.php root@10.0.10.196:/tmp/codex_panel_combos_f47.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_panel_combos_f47.php'
