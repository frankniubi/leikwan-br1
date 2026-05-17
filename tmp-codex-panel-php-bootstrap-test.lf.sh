#!/usr/bin/env bash
set -euo pipefail
PANEL=10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" root@$PANEL 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
php -r '
require "master/bootstrap.php";
$pdo = null;
foreach (["db", "pdo"] as $k) { if (isset($GLOBALS[$k]) && $GLOBALS[$k] instanceof PDO) { $pdo=$GLOBALS[$k]; break; } }
if (!$pdo && class_exists("App\\Support\\Database")) { }
echo "loaded\n";
'
REMOTE