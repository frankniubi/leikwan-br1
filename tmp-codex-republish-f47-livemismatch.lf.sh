#!/usr/bin/env bash
set -euo pipefail
cat > /tmp/codex_republish_f47.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
require 'master/src/Support/ProvisioningService.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;
$c=new Config('master/.env');
$s=new ProvisioningService($c, new Database($c));
$ref=new ReflectionMethod($s,'reconcileForwardEndpoints');
$ref->setAccessible(true);
$ref->invoke($s,47);
echo "republished f47\n";
PHP
sshpass -p 'Frank1212.' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex_republish_f47.php root@10.0.10.196:/tmp/codex_republish_f47.php >/dev/null
sshpass -p 'Frank1212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_republish_f47.php && php -r '\''require "master/src/Config.php"; require "master/src/Database/Database.php"; use Leikwan\Master\Config; use Leikwan\Master\Database\Database; $pdo=(new Database(new Config("master/.env")))->pdo(); foreach($pdo->query("SELECT id,node_id,endpoint_id,status,LEFT(payload_json,400) payload FROM provision_tasks WHERE forward_id=47 ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";} '\'' '
