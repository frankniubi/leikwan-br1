#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
cat >/tmp/codex_query_f47_payload.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php'; require 'master/src/Support/ProvisioningService.php';
spl_autoload_register(static function(string $class): void { $prefix='Leikwan\\Master\\'; if(!str_starts_with($class,$prefix)) return; $path=__DIR__.'/master/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if(is_file($path)) require_once $path; });
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$r=$pdo->query("SELECT payload_json FROM provision_tasks WHERE forward_id=47 AND endpoint_id=316 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo $r['payload_json'] ?? '';
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_query_f47_payload.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_query_f47_payload.php" >/dev/null
PAYLOAD=$(sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_query_f47_payload.php; rm -f codex_query_f47_payload.php')
# shellcheck disable=SC2016
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "PAYLOAD_B64=$(printf '%s' "$PAYLOAD" | base64 -w0) bash -s" <<'REMOTE'
set -euo pipefail
python3 - <<'PY'
import os,base64,json,re,pathlib
payload=json.loads(base64.b64decode(os.environ['PAYLOAD_B64']).decode())
# keep only Shanghai active endpoints, disable self_leg from this test by using helpers only
endpoints=[]
# Query current start script to know local ports? We'll render a manual one below.
print(json.dumps({k: payload.get(k) for k in ['target','listen_port','path_prefix','backend_mode','ingress_protocol','backend_protocol','ingress_auth_token','target_protocol']}, indent=2))
print('cf', payload.get('cf_mode'))
PY
REMOTE