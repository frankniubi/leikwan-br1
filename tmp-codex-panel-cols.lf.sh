#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
sshpass -e ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@10.0.10.196 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
$c = new Config(__DIR__ . '/master/.env');
$db = new Database($c);
function cols($db,$table){return array_map(fn($r)=>$r['Field'],$db->fetchAll("SHOW COLUMNS FROM `$table`"));}
foreach(['forwards','clients','client_device_sessions','nodes','sidecar_points'] as $t){echo "--- cols $t\n"; echo implode(',', cols($db,$t)),"\n";}
PHP
REMOTE