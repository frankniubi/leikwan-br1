#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_panel_f47_payload.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php'; require 'master/src/Support/ProvisioningService.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database; use Leikwan\Master\Support\ProvisioningService;
$config=new Config('master/.env'); $pdo=(new Database($config))->pdo(); $prov=new ProvisioningService($pdo,$config);
$methods=get_class_methods($prov); foreach($methods as $m){ if(stripos($m,'client')!==false || stripos($m,'forward')!==false || stripos($m,'node')!==false) echo $m."\n"; }
?>
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_panel_f47_payload.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_panel_f47_payload.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_panel_f47_payload.php && rm -f codex_panel_f47_payload.php'